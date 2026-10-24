#!/usr/bin/env bash
# Sets up (or updates) a Magrathea Explorer instance on a production server.
#
# There is no build step on the server: the admin app's build output (dist/)
# is committed to git, and api/src/app is a committed symlink to it. A deploy
# is "clone, composer install, configure, point the vhost at api/src/".
# docs/deploy.md walks through the full setup for local-disk and Cloudflare R2 instances.
#
# Usage, from a clone of this repo on the server:
#
#   git clone <repo-url> /var/www/explorer && cd /var/www/explorer
#   scripts/deploy.sh install       # first deploy: checks, deps, dirs, configs, schema
#   scripts/deploy.sh update        # later deploys: git pull + deps + pending migrations list
#   scripts/deploy.sh vhost         # print a sample Apache vhost for this instance
#
# Env overrides:
#   WEB_USER   user the web server/php-fpm runs as (default: www-data). On shared
#              hosts like DreamHost PHP runs as your own user -- set WEB_USER=$USER.
#   SKIP_DB=1  on install, don't load api/database/database.sql
#
# What it never does: overwrite an existing magrathea.conf/storage.conf, load the
# schema into a database that already has tables, apply migrations on its own, or
# touch /etc (vhost and crontab lines are printed for you to install).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
API_DIR="$ROOT_DIR/api"
CONF_DIR="$API_DIR/configs"
WEB_USER="${WEB_USER:-www-data}"

info()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn()  { printf '\033[1;33m!!\033[0m  %s\n' "$*" >&2; }
die()   { printf '\033[1;31mxx\033[0m  %s\n' "$*" >&2; exit 1; }

# Reads a value out of one of the ini configs, from the section that
# magrathea.conf's [general] use_environment points at -- same lookup the app does.
conf() {
	local file="$1" key="$2"
	php -r '
		$env = parse_ini_file($argv[1], true)["general"]["use_environment"] ?? "production";
		$ini = parse_ini_file($argv[2], true);
		echo $ini[$env][$argv[3]] ?? "";
	' "$CONF_DIR/magrathea.conf" "$CONF_DIR/$file" "$key"
}

check_requirements() {
	info "Checking requirements"
	command -v php >/dev/null || die "php not found (8.1+ required)"
	command -v composer >/dev/null || die "composer not found -- see https://getcomposer.org/download/"
	php -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);' \
		|| die "PHP 8.1+ required, found $(php -r 'echo PHP_VERSION;')"

	local missing=()
	for ext in gd mysqli dom fileinfo exif; do
		php -m | grep -qix "$ext" || missing+=("$ext")
	done
	[ ${#missing[@]} -eq 0 ] || die "missing PHP extensions: ${missing[*]}"

	# The CLI and the web SAPI can have different ini files -- this only checks the CLI one.
	local upload; upload="$(php -r 'echo ini_get("upload_max_filesize");')"
	info "PHP OK ($(php -r 'echo PHP_VERSION;')), CLI upload_max_filesize=$upload"
}

install_deps() {
	info "Installing composer dependencies (no dev)"
	# composer.json lives in api/, so vendor/ lands where _inc.php's ../vendor/autoload.php expects it.
	(cd "$API_DIR" && composer install --no-dev --optimize-autoloader --no-interaction)
}

setup_configs() {
	local created=0
	for name in magrathea.conf storage.conf; do
		if [ ! -f "$CONF_DIR/$name" ]; then
			cp "$CONF_DIR/$name.sample" "$CONF_DIR/$name"
			chmod 640 "$CONF_DIR/$name"
			warn "created api/configs/$name from its sample"
			created=1
		fi
	done
	if [ $created -eq 1 ]; then
		cat >&2 <<-EOF

		Fill in the [production] sections before running install again:
		  api/configs/magrathea.conf  -- set [general] use_environment = "production",
		                                 db_*, app_url, logs_path, cache_path
		  api/configs/storage.conf    -- driver = "local" (local_path + local_url)
		                                 or "s3" (endpoint/bucket/keys; R2 = region "auto")
		EOF
		exit 1
	fi

	[ "$(php -r 'echo parse_ini_file($argv[1], true)["general"]["use_environment"] ?? "";' "$CONF_DIR/magrathea.conf")" = "production" ] \
		|| warn "magrathea.conf's use_environment is not \"production\""
}

setup_dirs() {
	info "Creating runtime directories"
	local logs cache
	logs="$(conf magrathea.conf logs_path)"; logs="${logs:-$API_DIR/logs}"
	cache="$(conf magrathea.conf cache_path)"; cache="${cache:-$API_DIR/cache}"
	local dirs=("$logs" "$cache")

	if [ "$(conf storage.conf driver)" = "local" ]; then
		local storage; storage="$(conf storage.conf local_path)"
		[ -n "$storage" ] || die "storage.conf: driver is local but local_path is empty"
		dirs+=("$storage")
		mkdir -p "$storage"
		# Turns ?disposition=&filename= into a Content-Disposition header and disables
		# listings of per-key dirs. Must live next to the files, not under api/src.
		cp "$API_DIR/storage/.htaccess" "$storage/.htaccess"
	fi

	for dir in "${dirs[@]}"; do
		mkdir -p "$dir"
		if [ "$(id -u)" -eq 0 ]; then
			chown -R "$WEB_USER" "$dir"
		elif [ "$(id -un)" != "$WEB_USER" ]; then
			warn "$dir must be writable by $WEB_USER -- run as root or: sudo chown -R $WEB_USER $dir"
		fi
		echo "    $dir"
	done
}

setup_php_ini() {
	# php_value in .htaccess doesn't work on DreamHost (see api/src/.htaccess) -- .user.ini
	# does on php-fpm/CGI hosts, and .htaccess already denies serving it.
	local ini="$API_DIR/src/.user.ini"
	if [ ! -f "$ini" ]; then
		info "Writing api/src/.user.ini (upload limits, mirrors docker/php.ini)"
		printf 'upload_max_filesize=64M\npost_max_size=64M\nmemory_limit=512M\n' > "$ini"
	fi
}

load_schema() {
	if [ "${SKIP_DB:-0}" = "1" ]; then
		warn "SKIP_DB=1 -- not loading the schema"
		return
	fi
	command -v mysql >/dev/null || die "mysql client not found (or rerun with SKIP_DB=1 and load api/database/database.sql yourself)"

	local host name user
	host="$(conf magrathea.conf db_host)"
	name="$(conf magrathea.conf db_name)"
	user="$(conf magrathea.conf db_user)"
	export MYSQL_PWD; MYSQL_PWD="$(conf magrathea.conf db_pass)"

	local tables
	tables="$(mysql -h "$host" -u "$user" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$name'")" \
		|| die "can't connect to $name on $host as $user -- check magrathea.conf"
	if [ "$tables" -gt 0 ]; then
		warn "database $name already has $tables table(s) -- not loading database.sql (use migrations instead)"
		return
	fi
	info "Loading api/database/database.sql into $name"
	mysql -h "$host" -u "$user" "$name" < "$API_DIR/database/database.sql"
}

# Migrations are named <version>_<what>.sql; anything newer than the version we
# came from and up to the one we're at now still needs to be applied.
pending_migrations() {
	local from="$1" to="$2"
	[ "$from" != "$to" ] || return 0
	for file in "$API_DIR"/database/migrations/*.sql; do
		[ -e "$file" ] || continue
		local v; v="$(basename "$file" | cut -d_ -f1)"
		if [ "$(printf '%s\n%s\n' "$from" "$v" | sort -V | tail -1)" = "$v" ] && [ "$v" != "$from" ] \
			&& [ "$(printf '%s\n%s\n' "$v" "$to" | sort -V | tail -1)" = "$to" ]; then
			echo "$file"
		fi
	done
}

print_vhost() {
	local url host storage
	url="$(conf magrathea.conf app_url)"
	host="$(printf '%s' "$url" | sed -E 's#^[a-z]+://##; s#[:/].*$##')"
	storage="$(conf storage.conf local_path)"
	cat <<-EOF
	# Apache vhost for $host (needs mod_rewrite + mod_headers; add TLS via certbot or similar).
	# Caddy-fronted instances: use docker/caddy/site.caddy instead.
	<VirtualHost *:80>
	    ServerName $host
	    DocumentRoot $API_DIR/src

	    <Directory $API_DIR/src>
	        Options -Indexes +FollowSymLinks
	        AllowOverride All
	        Require all granted
	    </Directory>
	EOF
	# s3/R2 instances serve files from the bucket's public URL -- no /storage here.
	if [ "$(conf storage.conf driver)" = "local" ]; then
		cat <<-EOF

		    # storage.conf's local_path, served at /storage so local_url resolves.
		    Alias /storage $storage
		    <Directory $storage>
		        Options -Indexes +FollowSymLinks
		        AllowOverride All
		        Require all granted
		        Header set X-Content-Type-Options "nosniff"
		    </Directory>
		EOF
	fi
	echo "</VirtualHost>"
}

print_next_steps() {
	local url; url="$(conf magrathea.conf app_url)"
	cat <<-EOF

	Next steps (not automated):
	  1. Web server: point the docroot at $API_DIR/src
	     (scripts/deploy.sh vhost prints an Apache sample). /app needs nothing extra.
	  2. Open $url/magrathea.php -- with no admin users yet it shows the first-run
	     form. The admin panel is the only place keys can be created.
	  3. Cron (daily) for scheduled key deletions:
	     0 3 * * * php $API_DIR/src/cron.php --run >> $API_DIR/logs/cron.log 2>&1
	  4. Smoke test: curl $url/api/v1/version
	EOF
}

cmd_install() {
	check_requirements
	install_deps
	setup_configs
	setup_dirs
	setup_php_ini
	load_schema
	info "Installed version $(cat "$API_DIR/version")"
	print_next_steps
}

cmd_update() {
	check_requirements
	local before after
	before="$(cat "$API_DIR/version")"
	info "Pulling latest code (currently $before)"
	git -C "$ROOT_DIR" pull --ff-only
	after="$(cat "$API_DIR/version")"
	install_deps
	setup_dirs

	local pending; pending="$(pending_migrations "$before" "$after")"
	if [ -n "$pending" ]; then
		warn "$before -> $after has migrations to apply, in order:"
		while read -r file; do
			echo "    mysql -h <db_host> -u <db_user> -p <db_name> < $file"
		done <<< "$pending"
	fi
	info "Now at version $after"
}

case "${1:-}" in
	install) cmd_install ;;
	update)  cmd_update ;;
	vhost)   print_vhost ;;
	*)       awk 'NR > 1 && !/^#/ { exit } NR > 1 { sub(/^# ?/, ""); print }' "$0"; exit 1 ;;
esac
