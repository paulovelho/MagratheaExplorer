# Deploying Magrathea Explorer

How to stand up a production instance. Every instance stores its files in exactly one
place, chosen at deploy time in `api/configs/storage.conf`:

- **Local storage**: files live on the server's own disk and the web server serves them.
- **Cloudflare R2**: files live in an R2 bucket and Cloudflare serves them.

Most of the setup is the same for both; [Common setup](#common-setup) covers it, then
each storage type has its own section. `scripts/deploy.sh` automates the parts that can
be automated safely and prints the rest.

## Which one?

| | Local storage | Cloudflare R2 |
|---|---|---|
| Where files live | `local_path` on the server's disk | an R2 bucket |
| Who serves file downloads | your web server, at `/storage` | Cloudflare, at the bucket's public domain |
| Web server config | extra `/storage` alias + `mod_headers` | nothing extra |
| Download filename (`Content-Disposition`) | set per request by `api/storage/.htaccess` | saved on the object at upload time |
| Disk space / backups | your problem: watch the disk, back up `local_path` | Cloudflare's: pay per GB stored, no egress fees |
| Good for | small instances, shared hosting, one box | anything that will grow, or serves a lot of traffic |

Either way, **uploads still pass through PHP on your server**: it sniffs the MIME
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
[local](#local-storage-instance) or [R2](#cloudflare-r2-instance). Both config files are
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
for local storage. For Caddy, start from `docker/caddy/site.caddy`: change the hostname,
the paths and the `php_fastcgi` upstream, and drop the `/storage` block on R2 instances.

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

## Local storage instance

Files are written to `local_path` as `{key-storage-uuid}/{token}.{ext}` and served by
the web server at `local_url`.

### storage.conf

```ini
[production]
	driver = "local"
	local_path = "/var/www/explorer-storage"
	local_url = "https://files.example.com/storage"
```

- Keep `local_path` **outside** `api/src/`, so uploaded files never sit inside the PHP
  app's docroot. They're served only through the `/storage` alias.
- `local_url` is `app_url` + `/storage`, the path the vhost aliases.

### What `deploy.sh install` does for it

It creates `local_path` and copies `api/storage/.htaccess` into it. That file:

- turns the `?disposition=…&filename=…` query string on download URLs into a real
  `Content-Disposition` header, so files download under their original name instead of
  `{token}.{ext}`
- disables directory listings. A listing of a key's folder would be a full inventory of
  that key's files.

If you move or recreate `local_path` by hand, copy that `.htaccess` back.

### Web server

Apache needs, besides the docroot:

```apache
Alias /storage /var/www/explorer-storage
<Directory /var/www/explorer-storage>
    Options -Indexes +FollowSymLinks
    AllowOverride All          # lets the storage .htaccess run
    Require all granted
    Header set X-Content-Type-Options "nosniff"
</Directory>
```

and both modules enabled: `a2enmod rewrite headers`. Without `mod_headers`, files still
download, but under their opaque token name.

The `nosniff` header stops browsers from guessing a user-uploaded file's type and
rendering it as something more dangerous than its sniffed `Content-Type`. Nothing in the
repo sets it for you; `scripts/deploy.sh vhost` includes it. On Caddy, add
`header X-Content-Type-Options nosniff` inside the `/storage` block.

On Caddy, the `handle_path /storage/*` block in `docker/caddy/site.caddy` does the same
job; no `.htaccess` is involved.

### Operations

- **Disk space**: every upload plus its thumbnail lands here. Monitor the disk; the
  admin **Storage** page shows total usage across keys.
- **Backups**: back up `local_path` **and** the database together. Files without their
  DB rows are unreachable, and DB rows without their files are broken links.

## Cloudflare R2 instance

Files are stored in an R2 bucket through R2's S3-compatible API (the `s3` driver, which
also works for real AWS S3), and served directly by Cloudflare from the bucket's public
URL. Your server never serves file downloads.

### 1. Create the bucket

Cloudflare dashboard → **R2** → **Create bucket**, e.g. `explorer-files`. Pick the
location hint closest to your users.

### 2. Make it publicly readable

Explorer hands out plain public URLs, so the bucket needs a public domain. In the
bucket's **Settings → Public access**:

- **Custom domain (use this in production)**: connect e.g. `cdn.files.example.com`. The
  domain must be a zone on the same Cloudflare account. You get Cloudflare caching, and
  you can add response headers (step 5).
- **r2.dev subdomain**: one click, but rate-limited and meant for testing only.

Whichever one you use is your `s3_public_url`. **It's required for R2.** Without it, file
URLs fall back to the S3 API endpoint, which is not public, so every file link fails.

### 3. Create an API token

**R2 → Manage R2 API Tokens → Create API token**:

- Permissions: **Object Read & Write**
- Scope: **only** the bucket from step 1

Save the **Access Key ID** and **Secret Access Key**: the secret is shown only once. The
S3 endpoint is on the same screen: `https://<account_id>.r2.cloudflarestorage.com`.

### 4. storage.conf

```ini
[production]
	driver = "s3"
	s3_endpoint = "https://<account_id>.r2.cloudflarestorage.com"
	s3_region = "auto"                  ; R2 always uses "auto"
	s3_bucket = "explorer-files"
	s3_access_key = "<access key id>"
	s3_secret_key = "<secret access key>"
	s3_path_style = true
	s3_public_url = "https://cdn.files.example.com"   ; no trailing slash needed
```

The `local_*` keys are ignored with `driver = "s3"` and can stay empty.

### 5. Add `nosniff` on the public domain

R2 can't store an `X-Content-Type-Options` header on an object (S3's `PutObject` has no
such field), so add it at Cloudflare: on the custom domain's zone, go to **Rules →
Transform Rules → Modify Response Header**, match hostname `cdn.files.example.com`, and
**set** `X-Content-Type-Options` = `nosniff`. Without it, browsers may guess a
user-uploaded file's type and render it as something more dangerous. You can't do this
on `r2.dev`, which is one more reason not to use it in production.

### Web server

Just the docroot. Leave out the `/storage` alias; `scripts/deploy.sh vhost` already
leaves it out when `driver = "s3"`.

### How R2 serving differs from local storage

- **Headers are set once, at upload.** `Content-Type`, `Content-Disposition` (with the
  original filename) and `Cache-Control: public, max-age=31536000, immutable` are saved
  on the object when it's uploaded. Changing them for existing files means re-uploading.
  The long cache is safe because every upload gets a new random token; a file is
  never overwritten at the same URL.
- **Deletes may lag at the edge.** Deleting a file removes the object from R2, but
  Cloudflare's cache on the custom domain can keep serving it until it expires. If
  takedowns need to be immediate, purge the URL in Cloudflare too.
- **No CORS setup needed.** The admin app only links to file URLs; it never reads them
  from JavaScript. You'd only need a CORS policy on the bucket if a client starts
  `fetch()`ing files directly.

### Operations

- **Backups**: the database is still on your server and needs backing up. R2 holds the
  files; turn on bucket object versioning or copy to a second bucket if you need
  protection against accidental deletes.
- **Cost**: R2 bills for storage and operations, not for downloads.

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
