# Deploying Magrathea Explorer

How to stand up a production instance. Every instance stores its files in exactly one
place, chosen at deploy time in `api/configs/storage.conf`:

- **Local storage**: files live on the server's own disk and the web server serves them.
- **Cloudflare R2**: files live in an R2 bucket and Cloudflare serves them.
- **AWS S3**: files live in an S3 bucket and CloudFront serves them.

Most of the setup is the same for all of them; [Common setup](#common-setup) covers it,
then each storage type has its own guide: [setup-local.md](setup-local.md),
[setup-s3-cloudflare.md](setup-s3-cloudflare.md) and [setup-s3-aws.md](setup-s3-aws.md).
`scripts/deploy.sh` automates the parts that can be automated safely and prints the rest.

## Which one?

| | Local storage | Cloudflare R2 | AWS S3 |
|---|---|---|---|
| Where files live | `local_path` on the server's disk | an R2 bucket | an S3 bucket |
| Who serves file downloads | your web server, at `/storage` | Cloudflare, at the bucket's public domain | CloudFront, at its alternate domain |
| Web server config | extra `/storage` alias + `mod_headers` | nothing extra | nothing extra |
| Download filename (`Content-Disposition`) | set per request by `api/storage/.htaccess` | saved on the object at upload time | saved on the object at upload time |
| Disk space / backups | your problem: watch the disk, back up `local_path` | Cloudflare's: pay per GB stored, no egress fees | AWS's: pay per GB stored; CloudFront Free plan covers 100 GB/month of downloads |
| Files domain DNS | nothing extra | must be on Cloudflare DNS | any provider (two CNAMEs) |
| Good for | small instances, shared hosting, one box | anything that will grow, or serves a lot of traffic | the same, when the domain's DNS has to stay off Cloudflare |

Whichever you pick, **uploads still pass through PHP on your server**: it sniffs the MIME
type, strips metadata, and builds the thumbnail before handing the file to storage.
PHP's upload limits apply to both.

Switching an instance from one type to the other later is possible but manual; see
[Switching storage type](#switching-storage-type).

## Common setup

### 1. Server requirements

- PHP 8.1+ with `gd`, `mysqli`, `dom`, `fileinfo`, `exif`
- MariaDB or MySQL, plus the `mysql` command-line client (to load the schema)
- Composer
- Apache with `mod_rewrite` (and `mod_headers` for local storage), or Caddy + php-fpm
- No Node.js: the admin app is built ahead of time and committed in `dist/`

### 2. Get the code and a database

```sh
git clone <repo-url> /var/www/explorer
cd /var/www/explorer
(cd api && composer install --no-dev --optimize-autoloader)
```

`deploy.sh` doesn't run composer; it only checks that `api/vendor/` is there.

Create an empty database and a user for it:

```sql
CREATE DATABASE magrathea_explorer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'explorer'@'localhost' IDENTIFIED BY '<strong-password>';
GRANT ALL PRIVILEGES ON magrathea_explorer.* TO 'explorer'@'localhost';
```

### 3. Configure

Run the installer once. With no config files present, it copies the two samples and
flags that step as failed; quit (`q`) and fill them in:

```sh
scripts/deploy.sh install
```

Edit `api/configs/magrathea.conf`:

```ini
[general]
	use_environment = "production"      ; picks the [production] section in BOTH config files
	time_zone = "America/Sao_Paulo"

[production]
	db_host = "127.0.0.1"
	db_name = "magrathea_explorer"
	db_user = "explorer"
	db_pass = "<strong-password>"
	logs_path = "/var/www/explorer/api/logs"
	cache_path = "/var/www/explorer/api/cache"
	timezone = "America/Sao_Paulo"
	app_url = "https://files.example.com"   ; public base URL, no trailing slash
	jwt_key = "<long random string>"         ; signs admin sessions, e.g. `openssl rand -hex 32`
```

Then fill in `api/configs/storage.conf` for the storage type you picked:
[local](setup-local.md#storageconf), [R2](setup-s3-cloudflare.md#4-storageconf) or
[AWS S3](setup-s3-aws.md#7-storageconf). Both config files are
gitignored; never commit them.

### 4. Install

```sh
WEB_USER=www-data scripts/deploy.sh install
```

`WEB_USER` is whoever PHP runs as (`www-data` on Debian/Ubuntu Apache; on DreamHost
and similar shared hosts it's your own user, so use `WEB_USER=$USER`). The script:

1. checks the PHP version and extensions
2. checks that composer dependencies are installed (`api/vendor/`)
3. checks that both config files exist (copying the samples if not)
4. checks the configuration: `use_environment` points at a section present in both
   files, `db_host`, `db_name`, `db_user`, `app_url` and `jwt_key` are set, and
   `storage.conf` has every key its `driver` needs
5. creates the logs, cache and (local storage only) storage folders, and makes them
   writable by `WEB_USER`. This needs root; without it you get the `chown` command to run.
6. writes `api/src/.user.ini` with 64 MB upload limits, if it doesn't exist yet
7. installs the database: connects with the `db_*` settings, creates `db_name` if it
   doesn't exist (only works if `db_user` is allowed to), and loads
   `api/database/database.sql`, but only if the database is still empty
   (`SKIP_DB=1` skips this step)

`install` shows these steps as a menu, each marked `ok`, `FAILED` or not run yet,
and you pick what runs next. Enter runs the suggested step (the next one that
hasn't passed), so a clean install is Enter all the way down; `a` runs the rest in
order, stopping at a failure. A failed step is flagged and nothing else happens
until you choose: fix the problem and run it again, move on anyway, or quit. That
also lets you re-run a single step, e.g. **Check configuration** and **Install the
database** after editing `magrathea.conf`. `v` prints the vhost from step 5 below.

On quit it lists the steps that failed or never ran, and exits non-zero if there are
any. It's interactive only: run without a terminal (piped, cron, CI), it refuses.

`.user.ini` only works where PHP runs as php-fpm/CGI. With `mod_php`, set
`upload_max_filesize` and `post_max_size` in the server's `php.ini` instead.

### 5. Web server

Point the document root at **`api/src/`**. That's all the API and the admin app need:
`api/src/.htaccess` handles routing, and `/app` is a committed symlink to `dist/`.

```sh
scripts/deploy.sh vhost
```

prints an Apache vhost built from your configs. It includes the `/storage` block only
for local storage. For Caddy, copy `api/site.caddy.sample` to `api/src/site.caddy` (gitignored), then change
the hostname, the paths and the `php_fastcgi` socket, and drop the `/storage` block on
S3/R2 instances. The framework admin's **site.caddy** page shows that copy.

Put HTTPS in front (e.g. `certbot --apache`). `app_url` must match the final `https://`
URL.

### 6. First admin user

Open `https://files.example.com/magrathea.php`. With no admin users yet, it shows the
first-run form. The admin panel is the **only** place access keys can be created;
there's no public endpoint for it.

Once logged in, check **Storage** in the admin panel: it shows the active driver and
whether the storage is reachable.

### 7. Cron

Keys scheduled for deletion are removed after 14 days by `cron.php`. Run it daily as
`WEB_USER`:

```cron
0 3 * * * php /var/www/explorer/api/src/cron.php --run >> /var/www/explorer/api/logs/cron.log 2>&1
```

`--dry-run` lists what's due without deleting anything.

### 8. Smoke test

```sh
curl https://files.example.com/api/v1/version
curl -X POST -H "Authorization: Bearer <key-uuid>" -F "file=@test.jpg" \
  https://files.example.com/api/v1/files
```

Open the `url` from the upload response in a browser: it should load, and the download
should keep the original filename.

## Storage setup

Finish the instance with the guide for its storage type:

- [Local storage](setup-local.md)
- [Cloudflare R2](setup-s3-cloudflare.md)
- [AWS S3](setup-s3-aws.md)

## Updating an instance

There's no update command; it's a pull plus dependencies:

```sh
cd /var/www/explorer
cat api/version                       # note the version you're coming from
git pull --ff-only
(cd api && composer install --no-dev --optimize-autoloader)
```

Then apply every file in `api/database/migrations/` (named `<version>_<what>.sql`) newer
than the version you came from, in version order, ideally right after a DB backup:

```sh
mysql -u explorer -p magrathea_explorer < api/database/migrations/1.3.0_shares.sql
```

`database.sql` is for fresh installs only. Never load it into a running instance.

## Switching storage type

`driver` is instance-wide and not meant to change on a running instance. Each file's
URL is built from its stored path (`{key-storage-uuid}/{token}.{ext}`, the same on
both drivers), so a move is possible by copying the files over at the same paths, e.g.
local → R2:

```sh
rclone copy /var/www/explorer-storage r2:explorer-files --exclude .htaccess
```

then switching `storage.conf`. **Caveat:** objects copied this way don't get the
`Content-Disposition` that Explorer sets on upload, so old files download under their
token name until they're re-uploaded. Going from R2 to local has no such issue, since
local storage sets the header per request.
