#!/usr/bin/env bash
# Sets up a Magrathea Explorer instance on a production server.
#
# There is no build step on the server: the admin app's build output (dist/)
# is committed to git, and api/src/app is a committed symlink to it. A deploy
# is "clone, composer install, configure, point the vhost at api/src/".
# docs/deploy.md walks through the full setup for local-disk and Cloudflare R2 instances.
#
# Usage, from a clone of this repo on the server:
#
#   git clone <repo-url> /var/www/explorer && cd /var/www/explorer
#   (cd api && composer install --no-dev --optimize-autoloader)
#   scripts/deploy.sh install       # menu of install steps: checks, configs, dirs, database
#   scripts/deploy.sh vhost         # print a sample Apache vhost for this instance
#
# install shows the list of steps with how each one went, and you pick what runs
# next -- Enter runs the suggested one, so a clean install is just Enter all the way.
# A failed step is flagged and you choose whether to retry it, move on, or quit.
# It's meant to be run by hand, from a terminal.
#
# Env overrides:
#   WEB_USER   user the web server/php-fpm runs as (default: www-data). On shared
#              hosts like DreamHost PHP runs as your own user -- set WEB_USER=$USER.
#   SKIP_DB=1  on install, don't touch the database
#
# What it never does: run composer, overwrite an existing magrathea.conf/storage.conf,
# load the schema into a database that already has tables, apply migrations, or
# touch /etc (vhost and crontab lines are printed for you to install).
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
API_DIR="$ROOT_DIR/api"
CONF_DIR="$API_DIR/configs"
WEB_USER="${WEB_USER:-www-data}"

info()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn()  { printf '\033[1;33m!!\033[0m  %s\n' "$*" >&2; }
fail()  { printf '\033[1;31mxx\033[0m  %s\n' "$*" >&2; return 1; }

# Reads a value out of one of the ini configs, from the section that
# magrathea.conf's [general] use_environment points at -- same lookup the app does,
# including "$=VAR" values, which the framework reads from the environment.
conf() {
	local file="$1" key="$2"
	php -r '
		$env = @parse_ini_file($argv[1], true)["general"]["use_environment"] ?? "production";
		$ini = @parse_ini_file($argv[2], true) ?: [];
		$value = $ini[$env][$argv[3]] ?? "";
		if (is_string($value) && strpos($value, "$=") === 0) $value = getenv(substr($value, 2)) ?: "";
		echo $value;
	' "$CONF_DIR/magrathea.conf" "$CONF_DIR/$file" "$key"
}

environment() {
	php -r 'echo @parse_ini_file($argv[1], true)["general"]["use_environment"] ?? "";' "$CONF_DIR/magrathea.conf"
}

# Install steps, in the order they normally run, and what the menu calls them.
STEPS=(check_requirements check_deps setup_configs check_configs setup_dirs setup_php_ini install_database)
LABELS=(
	"Check PHP requirements"
	"Check composer dependencies"
	"Create config files"
	"Check configuration"
	"Create runtime directories"
	"Write api/src/.user.ini"
	"Install the database"
)
STATUS=()   # per step: "", "ok" or "failed"
NEXT=0      # index of the step the menu suggests next

run_step() {
	local i="$1"
	echo
	# Steps never need input; this keeps a command inside one from eating the menu's.
	if "${STEPS[$i]}" </dev/null; then
		STATUS[i]=ok
	else
		STATUS[i]=failed
		warn "\"${LABELS[$i]}\" failed"
	fi
	# Suggest the first step after this one that hasn't passed yet.
	for ((NEXT = i + 1; NEXT < ${#STEPS[@]}; NEXT++)); do
		[ "${STATUS[$NEXT]}" = ok ] || break
	done
	[ "${STATUS[$i]}" = ok ]
}

# Runs the suggested step and every one after it that hasn't passed yet, until one fails.
run_remaining() {
	local i
	for ((i = NEXT; i < ${#STEPS[@]}; i++)); do
		[ "${STATUS[$i]}" = ok ] && continue
		run_step "$i" || return 1
	done
}

menu() {
	local i mark choice default
	while true; do
		echo
		info "Magrathea Explorer $(cat "$API_DIR/version") -- install"
		for i in "${!STEPS[@]}"; do
			case "${STATUS[$i]}" in
				ok)     mark='\033[1;32m  ok  \033[0m' ;;
				failed) mark='\033[1;31mFAILED\033[0m' ;;
				*)      mark='      ' ;;
			esac
			printf '  %d. [%b] %s\n' $((i + 1)) "$mark" "${LABELS[$i]}"
		done
		default=q
		if [ "$NEXT" -lt ${#STEPS[@]} ]; then
			default=$((NEXT + 1))
			echo "  a. Run the rest from step $default in order (stops at a failure)"
		fi
		echo "  v. Print an Apache vhost"
		echo "  q. Quit"

		read -r -p "Choose [$default]: " choice || choice=q
		choice="${choice:-$default}"
		case "$choice" in
			a|A) run_remaining ;;
			v|V) echo; print_vhost ;;
			q|Q) return ;;
			*)
				if [[ "$choice" =~ ^[0-9]+$ ]] && [ "$choice" -ge 1 ] && [ "$choice" -le ${#STEPS[@]} ]; then
					run_step $((choice - 1))
				else
					warn "unknown choice: $choice"
				fi
				;;
		esac
	done
}

# Lists the steps that failed or never ran; succeeds only if there are none.
summary() {
	local i failed=() skipped=()
	for i in "${!STEPS[@]}"; do
		case "${STATUS[$i]}" in
			ok)     ;;
			failed) failed+=("${LABELS[$i]}") ;;
			*)      skipped+=("${LABELS[$i]}") ;;
		esac
	done
	echo
	if [ ${#failed[@]} -eq 0 ] && [ ${#skipped[@]} -eq 0 ]; then
		info "All steps OK"
		return 0
	fi
	if [ ${#failed[@]} -gt 0 ]; then
		warn "failed:"
		printf '      %s\n' "${failed[@]}" >&2
	fi
	if [ ${#skipped[@]} -gt 0 ]; then
		warn "not run:"
		printf '      %s\n' "${skipped[@]}" >&2
	fi
	return 1
}

check_requirements() {
	info "Checking requirements"
	command -v php >/dev/null || fail "php not found (8.1+ required)" || return 1
	php -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);' \
		|| fail "PHP 8.1+ required, found $(php -r 'echo PHP_VERSION;')" || return 1

	local missing=()
	for ext in gd mysqli dom fileinfo exif; do
		php -m | grep -qix "$ext" || missing+=("$ext")
	done
	[ ${#missing[@]} -eq 0 ] || fail "missing PHP extensions: ${missing[*]}" || return 1

	# The CLI and the web SAPI can have different ini files -- this only checks the CLI one.
	local upload; upload="$(php -r 'echo ini_get("upload_max_filesize");')"
	info "PHP OK ($(php -r 'echo PHP_VERSION;')), CLI upload_max_filesize=$upload"
}

check_deps() {
	info "Checking composer dependencies"
	# composer.json lives in api/, so vendor/ lands where _inc.php's ../vendor/autoload.php expects it.
	[ -f "$API_DIR/vendor/autoload.php" ] \
		|| fail "api/vendor/ is missing -- run: (cd api && composer install --no-dev --optimize-autoloader)" || return 1
	echo "    $API_DIR/vendor"
}

setup_configs() {
	info "Checking config files"
	local created=0 name
	for name in magrathea.conf storage.conf; do
		if [ ! -f "$CONF_DIR/$name" ]; then
			cp "$CONF_DIR/$name.sample" "$CONF_DIR/$name" || return 1
			chmod 640 "$CONF_DIR/$name"
			warn "created api/configs/$name from its sample"
			created=1
		fi
	done
	if [ $created -eq 1 ]; then
		cat >&2 <<-EOF

		Fill in the [production] sections before running install again:
		  api/configs/magrathea.conf  -- set [general] use_environment = "production",
		                                 db_*, app_url, jwt_key, logs_path, cache_path
		  api/configs/storage.conf    -- driver = "local" (local_path + local_url)
		                                 or "s3" (endpoint/bucket/keys; R2 = region "auto")
		EOF
		return 1
	fi
}

check_configs() {
	local env; env="$(environment)"
	info "Checking configuration (environment: ${env:-<none>})"
	[ -n "$env" ] || fail "magrathea.conf: [general] use_environment is not set" || return 1
	[ "$env" = "production" ] || warn "magrathea.conf's use_environment is \"$env\", not \"production\""

	local errors=0 key file
	for file in magrathea.conf storage.conf; do
		php -r 'exit(is_array((@parse_ini_file($argv[1], true) ?: [])[$argv[2]] ?? null) ? 0 : 1);' "$CONF_DIR/$file" "$env" \
			|| { fail "$file: no [$env] section (or the file doesn't parse)"; errors=1; }
	done
	[ $errors -eq 0 ] || return 1

	# A "$=VAR" value reads as empty here when VAR is only set for the web server, not this shell.
	for key in db_host db_name db_user app_url jwt_key; do
		[ -n "$(conf magrathea.conf "$key")" ] || { fail "magrathea.conf [$env]: $key is empty"; errors=1; }
	done
	[ -n "$(conf magrathea.conf db_pass)" ] || warn "magrathea.conf [$env]: db_pass is empty"
	case "$(conf magrathea.conf app_url)" in
		*/) warn "magrathea.conf [$env]: app_url should not end with a slash" ;;
	esac

	local driver; driver="$(conf storage.conf driver)"
	case "$driver" in
		local) local keys=(local_path local_url) ;;
		# Without s3_public_url, file URLs point at the (private) S3 API endpoint.
		s3)    local keys=(s3_endpoint s3_region s3_bucket s3_access_key s3_secret_key s3_public_url) ;;
		*)     fail "storage.conf [$env]: driver must be \"local\" or \"s3\", got \"$driver\""; return 1 ;;
	esac
	for key in "${keys[@]}"; do
		[ -n "$(conf storage.conf "$key")" ] || { fail "storage.conf [$env]: $key is empty (driver = $driver)"; errors=1; }
	done

	[ $errors -eq 0 ] || return 1
	echo "    magrathea.conf + storage.conf OK (driver: $driver)"
}

setup_dirs() {
	info "Creating runtime directories"
	local logs cache
	logs="$(conf magrathea.conf logs_path)"; logs="${logs:-$API_DIR/logs}"
	cache="$(conf magrathea.conf cache_path)"; cache="${cache:-$API_DIR/cache}"
	local dirs=("$logs" "$cache")

	if [ "$(conf storage.conf driver)" = "local" ]; then
		local storage; storage="$(conf storage.conf local_path)"
		[ -n "$storage" ] || fail "storage.conf: driver is local but local_path is empty" || return 1
		dirs+=("$storage")
		mkdir -p "$storage" || return 1
		# Turns ?disposition=&filename= into a Content-Disposition header and disables
		# listings of per-key dirs. Must live next to the files, not under api/src.
		cp "$API_DIR/storage/.htaccess" "$storage/.htaccess" || return 1
	fi

	local errors=0 dir
	for dir in "${dirs[@]}"; do
		mkdir -p "$dir" || { errors=1; continue; }
		if [ "$(id -u)" -eq 0 ]; then
			chown -R "$WEB_USER" "$dir" || errors=1
		elif [ "$(id -un)" != "$WEB_USER" ]; then
			warn "$dir must be writable by $WEB_USER -- run as root or: sudo chown -R $WEB_USER $dir"
		fi
		echo "    $dir"
	done
	return $errors
}

setup_php_ini() {
	# php_value in .htaccess doesn't work on DreamHost (see api/src/.htaccess) -- .user.ini
	# does on php-fpm/CGI hosts, and .htaccess already denies serving it.
	local ini="$API_DIR/src/.user.ini"
	if [ ! -f "$ini" ]; then
		info "Writing api/src/.user.ini (upload limits, mirrors docker/php.ini)"
		printf 'upload_max_filesize=64M\npost_max_size=64M\nmemory_limit=512M\n' > "$ini"
	else
		info "api/src/.user.ini already exists -- leaving it alone"
	fi
}

install_database() {
	if [ "${SKIP_DB:-0}" = "1" ]; then
		warn "SKIP_DB=1 -- not touching the database"
		return
	fi
	info "Installing the database"
	command -v mysql >/dev/null \
		|| fail "mysql client not found (or rerun with SKIP_DB=1 and load api/database/database.sql yourself)" || return 1

	local host port name user
	host="$(conf magrathea.conf db_host)"
	port="$(conf magrathea.conf db_port)"; port="${port:-3306}"
	name="$(conf magrathea.conf db_name)"
	user="$(conf magrathea.conf db_user)"
	export MYSQL_PWD; MYSQL_PWD="$(conf magrathea.conf db_pass)"
	local mysql=(mysql -h "$host" -P "$port" -u "$user")

	"${mysql[@]}" -e "SELECT 1" >/dev/null \
		|| fail "can't connect to $host:$port as $user -- check db_* in magrathea.conf" || return 1

	local exists
	exists="$("${mysql[@]}" -N -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '$name'")" || return 1
	if [ "$exists" -eq 0 ]; then
		info "Creating database $name"
		"${mysql[@]}" -e "CREATE DATABASE \`$name\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" \
			|| fail "can't create database $name -- create it as a DB admin (see docs/deploy.md) and rerun" || return 1
	fi

	local tables
	tables="$("${mysql[@]}" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$name'")" || return 1
	if [ "$tables" -gt 0 ]; then
		warn "database $name already has $tables table(s) -- not loading database.sql"
		warn "upgrading? apply the files in api/database/migrations/ newer than the installed version by hand"
		return
	fi
	info "Loading api/database/database.sql into $name"
	"${mysql[@]}" "$name" < "$API_DIR/database/database.sql" \
		|| fail "loading database.sql failed -- the database may be half-loaded; drop its tables before retrying" || return 1
	echo "    $("${mysql[@]}" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$name'") table(s) created"
}

print_vhost() {
	local url host storage
	url="$(conf magrathea.conf app_url)"
	host="$(printf '%s' "$url" | sed -E 's#^[a-z]+://##; s#[:/].*$##')"
	storage="$(conf storage.conf local_path)"
	cat <<-EOF
	# Apache vhost for $host (needs mod_rewrite + mod_headers; add TLS via certbot or similar).
	# Caddy-fronted instances: copy api/site.caddy.sample to api/src/site.caddy instead.
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
	[ -t 0 ] || fail "install is interactive -- run it from a terminal" || return 1
	local i
	for i in "${!STEPS[@]}"; do STATUS[i]=""; done
	menu
	summary || return 1
	print_next_steps
}

case "${1:-}" in
	install) cmd_install ;;
	vhost)   print_vhost ;;
	*)       awk 'NR > 1 && !/^#/ { exit } NR > 1 { sub(/^# ?/, ""); print }' "$0"; exit 1 ;;
esac
