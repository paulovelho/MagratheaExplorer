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
type, strips metadata, and runs the image processor (thumbnails and other renditions, made
from the stored bytes and stored next to the original).
PHP's upload limits apply to both.

Switching an instance from one type to the other later is possible but manual; see
[Switching storage type](#switching-storage-type).

## Common setup

### 1. Server requirements

- PHP 8.1+ with `gd`, `mysqli`, `dom`, `fileinfo`, `exif`, `mbstring`, `xmlreader` (1.5.0:
  every SVG upload is checked with it, and fails without it). Check the **web** PHP, not only
  the CLI: on shared hosts they can be different builds
- For uploads from a URL (1.5.0, off by default): PHP's `curl`, `intl` for international host
  names, and outbound HTTPS from the web server. See [Uploads from a URL](#uploads-from-a-url)
- For the image processor (1.5.0): Python 3 with `venv`, and PHP's `proc_open` enabled. See
  [Image processor](#image-processor)
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

`timezone` (in the environment section, not `[general]`, where it's ignored) is the zone the
database's times are kept in. The admin shows them in it; the API converts them to UTC (`…Z`).
- **Set it before the first key and never change it afterwards:** times already stored would be
  read in the new zone, and every `expiration`, `execute_at` and `last_viewed_at` would shift.
- Leave `db_sync_timezone` out (it defaults to on): it makes MySQL's session use the same zone,
  so `created_at` reads back in it too.
- In a zone with daylight saving, one local hour a year happens twice, and the API's UTC for a
  time stored in it may be an hour off. Sao Paulo has had no DST since 2019; `UTC` avoids it.

Optional keys, in the same environment section (absent = the default shown):

```ini
	verbatim_default = "false"       ; images without a `verbatim` flag: false = strip + convert, true = store as sent
	convert_max_bytes = "25M"        ; non-verbatim images above either limit are refused (4131)
	convert_max_pixels = "40000000"  ; width x height
	processing_tmp_path = "/var/www/explorer/api/tmp"   ; default: api/tmp
	processor_command = '["/home/u/explorer-venv/bin/python","/var/www/explorer/api/processor/vips/processor.py"]'   ; default: none
	processor_timeout_s = "120"
	processor_memory_mb = "384"
	max_pixels = "200000000"         ; width x height above which the processor decodes nothing
	inline_max_bytes = "16M"         ; images up to both inline limits get their renditions during the upload
	inline_max_pixels = "40000000"
	cron_process_max_files = "20"    ; cron.php --process: jobs per run
	cron_process_max_s = "50"        ; cron.php --process: no new job after this many seconds
	processing_max_attempts = "3"    ; runs of one job before it's failed
	client_ip_header = "CF-Connecting-IP"   ; default: none (REMOTE_ADDR). See Key provisioning
	idempotency_retention_h = "24"   ; hours an Idempotency-Key is remembered, 24 at least
	source_url_enabled = "false"     ; POST /files with source_url: the server downloads the file. See Uploads from a URL
	source_url_timeout_s = "90"      ; the whole download, redirects included
	source_url_ports = ""            ; ports a source_url may use besides 443, comma-separated
	folder_delete_inline_files = "200"   ; DELETE /folder?recursive=1: bigger subtrees go to cron.php --process
	folder_delete_max_s = "15"       ; cron.php --process: seconds a run spends on recursive deletes
```

- `verbatim_default` is the instance default. A key's own setting (admin → Keys, "Images
  without verbatim flag") overrides it, and so does a request's `verbatim` field. Bulk
  imports follow the key's effective default.
- The conversion limits bound PHP memory. Re-encoding holds the image in GD plus the encoder's
  own copy: measured on PHP 8.4, a 40 MP re-encode peaks at ~320 MB, which fits the
  `memory_limit = 512M` that `deploy.sh` writes. A JPEG that has to be turned upright (EXIF
  orientation 5-8, portrait photos) needs a second canvas (~470 MB at 40 MP), so it counts 1.5x
  its pixels: about 26.7 MP at the default. Raise the limits only together with `memory_limit`.
  Verbatim uploads skip conversion: PHP never decodes them.
- A value these keys can't read fails every upload with 5004 naming the key. The processing
  keys (`processor_*`, `max_pixels`, `inline_*`, `processing_max_attempts`) don't fail uploads:
  every image upload is stored with processing status `failed` and an error naming the key.
- `processing_tmp_path` is the instance's own scratch folder: `cron.php`'s lock files, the
  files it fetches back from storage, the processor's job folders, and `source_url` downloads. Keep it inside the
  account, not the shared `/tmp`, and writable by the user cron runs as.
  `scripts/deploy.sh install` creates it.
- `processor_*`, `max_pixels`, `inline_*`, `cron_process_*` and `processing_max_attempts`: see
  [Image processor](#image-processor).
- `client_ip_header`: see [Key provisioning](#key-provisioning).
- `source_url_*`: see [Uploads from a URL](#uploads-from-a-url).
- `folder_delete_*`: `DELETE /folder/{uuid}?recursive=1` hides the whole subtree at once and
  deletes up to `folder_delete_inline_files` files in the request. A bigger tree is deleted by
  `cron.php --process` ([Cron](#7-cron)), at most `folder_delete_max_s` seconds per run, after
  the processing jobs. With both defaults a busy run can take ~65 s; the next minute's run then
  finds the lock and skips, nothing worse. A 100k-file tree takes many runs, and the key's
  `total_size` drops as it goes.
- `idempotency_retention_h`: how long `POST /files` and `POST /provision/keys` remember an
  `Idempotency-Key`, so that a client's retry gets the first request's file or key instead of a
  second one. Below 24 fails `cron.php --process` with 5004: clients are told they can retry
  for 24 h. `cron.php --process` deletes the expired records (1000 per run).

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
6. writes `api/src/.user.ini` with 64 MB upload limits, if it doesn't exist yet (bigger
   uploads: see [Large uploads](#large-uploads-per-key-limits) below)
7. installs the database: connects with the `db_*` settings, creates `db_name` if it
   doesn't exist (only works if `db_user` is allowed to), and loads
   `api/database/database.sql`, but only if the database is still empty
   (`SKIP_DB=1` skips this step)
8. checks the image processor: runs `api/processor/run-fixtures.php` as `WEB_USER`. It fails
   when `processor_command` isn't set, since images then get no thumbnails (see
   [Image processor](#image-processor)); move on from the menu if that's intended

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

#### Large uploads (per-key limits)

Each key can have its own upload limit: admin → Keys, **Max upload (MB)**. Empty means the
instance default, `max_upload_size` in admin → App Config (5 MB when unset). PHP's limits cap
every key: with the 64 MB `.user.ini` above, a key set to 150 MB still stops at 64 MB. `GET /key`
reports the lower figure, the key form shows what PHP allows, and the key list flags each key
PHP cuts short.

To let the largest key limit through, edit `api/src/.user.ini` (`install` writes it once and
never overwrites it). For keys up to 150 MB:

```ini
upload_max_filesize=160M
post_max_size=170M
max_input_time=300
max_execution_time=300
memory_limit=512M
```

- `post_max_size` stays above `upload_max_filesize`: it limits the whole request body,
  multipart overhead and form fields included.
- `max_input_time` and `max_execution_time` at 300 or more: receiving a 150 MB upload over a
  slow connection, then storing it, takes longer than the defaults allow.
- `memory_limit` doesn't grow. PHP writes the request body to disk, verbatim images and
  non-image files are streamed to storage, and images that get converted are capped by
  `convert_max_bytes`/`convert_max_pixels`. Each upload in flight takes its size in PHP's temp
  dir (`upload_tmp_dir`, else the system's), so keep room there.
- PHP rereads `.user.ini` every `user_ini.cache_ttl` (5 minutes by default). `php` on the
  command line doesn't read it at all, so check the key form or `GET /key`, not `php -i`.
- On DreamHost, `.user.ini` is the only way: `php_value` in `.htaccess` doesn't work there.
- What sits in front of PHP has its own body limit, and it answers with its own 413 before
  Explorer sees the request: nginx's `client_max_body_size` (1 MB by default), Apache's
  `LimitRequestBody`, and Cloudflare's proxy (100 MB on the Free and Pro plans). An API
  hostname proxied through Cloudflare can't take uploads over 100 MB on those plans: make it
  DNS-only (grey cloud), or keep key limits under 100 MB.

#### Uploads from a URL

`POST /files` can take a `source_url` instead of a file: the server downloads it and stores it
like an upload (1.5.0). It's **off until `source_url_enabled = "true"`**, because it lets any key
holder make the server open outbound connections. Turn it on only where a consuming project
needs it.

What the server does with a URL, so you know what it can and can't reach:
- `https://` only, port 443 plus `source_url_ports`, no credentials in the URL;
- every address the host resolves to must be public: private ranges, loopback, link-local
  (cloud metadata at 169.254.169.254 included), CGNAT, multicast, reserved and documentation
  ranges are all refused, IPv6 included (only global unicast passes). It connects to exactly the
  addresses it checked, so a DNS answer that changes in between (rebinding) gets nowhere;
- at most 3 redirects, each checked the same way; no proxy (the environment's `https_proxy` is
  ignored), no cookies, nothing forwarded from the client.

Setting it up:
- **PHP's `curl`**, and **`intl`** if URLs may have international host names (without it they're
  refused with a hint to send punycode).
- **Outbound HTTPS from the web PHP**: a firewall that blocks it turns every request into a
  5021 after the connect timeout (10 s).
- **DNS:** names are resolved with `dns_get_record()`, the resolvers in `/etc/resolv.conf`.
  `/etc/hosts` doesn't count, on purpose. A slow resolver holds the request: it has no timeout
  of its own.
- **Disk:** the download goes to `processing_tmp_path` and is processed from there, not copied:
  keep room for the largest key limit times the downloads that can run at once.
- **Size:** a download is held to the key's effective upload limit, the same `max_upload_size`
  `GET /key` shows, so PHP's `upload_max_filesize`/`post_max_size` (above) cap it too, although
  PHP doesn't receive it as a request body. That's on purpose: one limit per key, whatever the
  source.
- **Time:** `source_url_timeout_s` (90) covers the download; after it come the hash, the upload
  to storage (~3 s for 150 MB to R2) and processing (30 s at most). Everything in front of PHP
  has to wait that long:
  - `max_execution_time` 300 (as above), although on Linux it doesn't count time spent waiting
    on the network;
  - Cloudflare's proxy gives up after **100 s** and answers 524, while PHP carries on and stores
    the file. The client's retry with the same `Idempotency-Key` then gets 4091 and later the
    file, but staying under 100 s avoids that: keep `source_url_timeout_s` at 90 or less behind
    Cloudflare;
  - FastCGI/proxy timeouts (php-fpm's `request_terminate_timeout`, Apache's `ProxyTimeout` or
    `FcgidIOTimeout`, Caddy's `read_timeout`) at least as long. On DreamHost, measure it
    ([dreamhost-tests.md](dreamhost-tests.md#6-uploads-from-a-url)).
- **Each download holds a PHP worker** for its whole length (90 s at most). A key holder can tie
  up as many as they send requests in parallel; the key's `usage_limit` bounds how many files,
  not how many attempts.

#### Direct uploads from a browser

`POST /upload-tokens` lets a consuming project's backend mint a single-use token, and a browser
then uploads one file straight to `POST /upload/{token}` with it, without the key (1.5.0).
Nothing to turn on, and the CORS it needs is the `*` the API already sends (a multipart POST
with no custom headers needs no preflight). What does matter:
- **Cloudflare's 100 MB body cap.** When the API hostname is proxied by Cloudflare (orange
  cloud) on the Free or Pro plan, any request body over 100 MB gets a 413 from Cloudflare,
  before PHP sees it. Direct uploads exist for big files from a browser (print files of 150 MB),
  so for them **the API hostname has to be DNS-only (grey cloud)**, or they stay under 100 MB.
  Multipart `POST /files` has the same ceiling ([Large uploads](#large-uploads-per-key-limits)).
- **PHP's limits** are the same as for any upload: the browser's file goes through
  `post_max_size`/`upload_max_filesize` and `max_input_time` ([Large uploads](#large-uploads-per-key-limits)).
- **Expiry is checked when the upload arrives**, which for PHP is when the whole body is in.
  Tokens last at most 15 minutes: a slow connection uploading 150 MB needs most of that.
- `cron.php --process` deletes tokens a week after they expire.

#### URL rotation and the CDN

`POST /file/{uuid}/rotate` copies a file and its renditions to new URLs and deletes the old
objects (1.5.0). Objects are stored with `Cache-Control: public, max-age=31536000, immutable`,
and on R2 a custom domain is behind Cloudflare's cache: **an old URL that was fetched through it
keeps being served from the edge until Cloudflare evicts it, for up to a year.** The server
purges nothing. When a rotation has to really kill a URL (a leak), purge it in Cloudflare
(dashboard → Caching → Configuration → Custom Purge → by URL; the old URL is the one the client
had). The same goes for CloudFront on AWS (an invalidation). On local storage there's no edge
cache unless you put one in front.

The copy is done by the storage itself (S3 CopyObject: the bytes don't pass through PHP). A
single CopyObject stops at 5 GiB on both S3 and R2 (R2 counts a copy as a single-request
upload), so a rotation of a bigger file fails with 5001 and changes nothing: there's no
multipart copy. Old objects whose delete fails are retried by `cron.php --process` (admin → Processing
lists the ones that keep failing).

#### Image processor

Image properties (DPI, alpha, opacity, ICC, …) and renditions (thumbnails, previews) come from
a separate program, the processor (1.5.0). **Without it, images get no thumbnail at all**:
since 1.5.0 PHP no longer makes one. Upgrading an instance means setting this up, or every
new image gets processing status `none` and `thumbnail_url: null` (old thumbnails keep
working). Explorer runs it with `proc_open`, never through a shell. Engine #1 is a
Python script on libvips (pyvips), in a venv outside the repo, built from a pinned
`requirements.txt`. Without `processor_command`, Explorer has no processor. The contract, the
fixtures and the engine's limits are in [api/processor/README.md](../api/processor/README.md).
On DreamHost, follow [dreamhost-tests.md](dreamhost-tests.md), which covers the same steps
plus the web PHP and cron checks.

1. Build the venv, as `WEB_USER` (on DreamHost, your own user). Ubuntu's system Python refuses
   `pip install --user` (PEP 668); a venv is the way:

   ```sh
   python3 -m venv ~/explorer-venv
   ~/explorer-venv/bin/pip install -r /var/www/explorer/api/processor/vips/requirements.txt
   ```

   `pyvips-binary` is libvips itself, a self-contained wheel: no system libvips needed, and every
   server runs the same build.
2. Point `processor_command` at it in `magrathea.conf`. It's an argv in JSON, single-quoted in
   the ini, absolute paths only:

   ```ini
   	processor_command = '["/home/u/explorer-venv/bin/python","/var/www/explorer/api/processor/vips/processor.py"]'
   ```

3. Check it, as `WEB_USER`. Every check must pass:

   ```sh
   php /var/www/explorer/api/processor/run-fixtures.php
   ```

   It runs the processor on every fixture through the same code Explorer uses, then on an image
   it generates at `max_pixels` (a few MB, a few seconds; `--skip-big` skips it). **Rerun it
   after every deploy and after any server change.**

- `processor_memory_mb` is the processor's own memory cap (`RLIMIT_DATA`). Its memory grows
  with the image's width:
  - a 200 MP square peaks at 150–210 MB, and fails at 128;
  - a 28000 px wide panorama peaks at ~260 MB;
  - hence the default of 384.

  Change it together with `max_pixels`, never just one of them: run-fixtures checks the pair.
- `processor_timeout_s`: the processor is killed after that long, and the run counts as failed.
  A 200 MP PNG takes ~5 s here.
- **Where it runs.** Images up to `inline_max_bytes` and `inline_max_pixels` (16 MB, 40 MP)
  are processed during the upload request, so their upload response has the renditions, as
  thumbnails did before 1.5.0. That run is cut at 30 s (or `processor_timeout_s`, if lower);
  one that's cut is retried from the queue. Bigger images, and every file of a bulk import,
  are queued: the upload response says `pending`, and `cron.php --process` makes them (see
  [Cron](#7-cron)). Without that cron line, they stay `pending` forever.
- **What each key gets** is its rendition profile (admin → Keys, "Renditions"): by default one
  `thumb`, a ConfigApp `thumbnail_size` square cropped to fill, jpg (png for a png), like the
  1.4.x thumbnail but center-cropped and flattened onto white. A profile is up to 6 specs, 2048
  px at most: renditions don't count toward a key's usage, so their size is bounded here. A
  profile change applies to new uploads; existing files keep their renditions until
  regenerated (`cron.php --regenerate`, the Browser's "regenerate", or the API).
- A failed run is retried by the queue up to `processing_max_attempts` runs in all. A run that
  finishes with some renditions failed isn't retried: those failures repeat (an image too wide
  for `processor_memory_mb`). Failures are listed in admin → Processing.
- No BMP: the pyvips wheel has no BMP loader, so a BMP stored as BMP (verbatim or
  `no_convert`) gets no renditions. A default upload converts BMP to webp first, so it does.
- **If the system Python is upgraded**, the venv's interpreter disappears. Every run then fails
  with a Python error (`No such file or directory`, `No module named …`), and run-fixtures shows
  it. Rebuild the venv: `rm -rf ~/explorer-venv`, then step 1 again.
- **Anyone who can log into the admin can run commands as `WEB_USER`.** The admin's Setup →
  Configuration File page writes `magrathea.conf`, so it can change `processor_command`. That page
  can also add keys that aren't there yet. Treat admin accounts as shell accounts: strong
  passwords, and no account for anyone you wouldn't give SSH to.

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
first-run form. Keys are created in the admin panel (Keys), or by a consuming project with
`POST /provision/keys` once you turn provisioning on (below).

**Key secrets are shown once (1.5.0).** Only a hash of each key's secret is stored. The admin
shows a new key's secret on the page right after it's created, and after **Rotate secret**,
and never again: copy it into the integration's config then. A lost secret can only be
rotated. Dumps made before the 1.5.0 migration still hold every secret in plaintext: delete
them (and their offsite copies) once you no longer need to roll back.

Once logged in, check **Storage** in the admin panel: it shows the active driver and
whether the storage is reachable.

#### Key provisioning

For a consuming project that creates a key per tenant itself (Turbopod: one per workspace).
It's off until you generate a secret. In admin → **Provisioning**:

1. **Set the limits and what each key gets** first: *Max keys* (how many provisioned keys may
   exist, keys pending deletion included), and the settings copied onto every key it creates
   (size limit, upload limit, max upload, verbatim default, rendition profile; empty = the
   instance default, as on a key). The page shows the worst case, max keys × size limit: with
   either one empty, a leaked secret can commit unlimited storage.
2. **Generate secret.** It's shown once (`xpv_…`, only its hash is stored). Put it in the
   consuming project's server-side config. **Rotate secret** replaces it (the old one stops
   working at once), **Turn off** removes it. Keys already made keep working either way.

What the secret can do is create keys, nothing else: it can't read, change or delete any key.
The project manages each key with that key's own secret, and changing a provisioned key's
limits is yours, in Keys (filter: Provisioned). Changing the Provisioning settings later
doesn't touch existing keys. Created keys are logged, without secrets, to
`logs_path/keys_<YYYYMM>.log`.

Failed provisioning authentications are limited to 10 per 15 minutes per client IP; then that
IP gets 429 until the window ends, even with the right secret. Behind Cloudflare or another
proxy, every request comes from the proxy's address, so one attacker would lock everyone out:
set `client_ip_header` (`CF-Connecting-IP` for Cloudflare) in `magrathea.conf`. Only do that
when the origin is reachable through the proxy alone, since anyone reaching it directly can
send that header. `cron.php --process` purges the counts after a day.

**Offboarding needs `cron.php --run`** (section 7): a project offboards a tenant with
`DELETE /key`, and only `--run` carries the deletion out, 14 days later. Without that cron
line, provisioned keys and their files are never deleted.

### 7. Cron

Keys scheduled for deletion are removed after 14 days by `cron.php`. Run it daily as
`WEB_USER` (required wherever keys are provisioned: it's what carries offboarding out):

```cron
0 3 * * * php /var/www/explorer/api/src/cron.php --run >> /var/www/explorer/api/logs/cron.log 2>&1
```

`--dry-run` lists what's due without deleting anything.

**Processing queue (1.5.0).** Renditions of big images and of bulk imports are made by
`cron.php --process`. Run it every minute, as `WEB_USER`: the queue's latency is the cron
interval.

```cron
* * * * * php /var/www/explorer/api/src/cron.php --process >> /var/www/explorer/api/logs/cron.log 2>&1
```

A run takes up to `cron_process_max_files` jobs (20), oldest first, and starts none after
`cron_process_max_s` seconds (50: keep it under the interval). A run that finds the previous
one still going exits at once (lock in `processing_tmp_path`), so runs never pile up. A run
with nothing to do prints nothing. Each run also fails jobs whose last run was killed, deletes
finished jobs older than 30 days, removes job folders left in `processing_tmp_path` by
killed runs, purges failed-authentication counts older than a day and Idempotency-Key records
past `idempotency_retention_h`, retries storage deletes that failed (100 per run, 20 tries each;
the ones that keep failing show in admin → Processing), purges upload tokens a week past their
expiry, and works on recursive folder deletes for up to `folder_delete_max_s` (all of these run
without a processor too). `--max-files=N` and
`--max-seconds=T` override the config for one run, `--verbose` lists every job. The exit code is 1 only when the run itself fails (config,
database); failed jobs show in admin → Processing.

To give existing images new renditions (after changing a key's profile, or for the thumbnails
from before 1.5.0, which have no recorded size), queue them all, then let `--process` drain
the queue:

```sh
php /var/www/explorer/api/src/cron.php --regenerate --key=<key id> [--folder=<folder id>]
```

Ids are the admin's (Keys, Browser). Every image gets **new rendition URLs**: clients that
stored the old ones must read the files again.

**sha256 backfill (instances with files from before 1.5.0).** Files uploaded since 1.5.0 carry
the sha256 of their stored bytes; older ones have `sha256: null` until this runs once, as
`WEB_USER`, after the 1.5.0 migration:

```sh
php /var/www/explorer/api/src/cron.php --backfill-sha256 --verbose
```

It downloads each stored object back from storage (R2 egress is free), hashes it and drops it,
oldest first, one at a time. A run that finds another one going exits at once (lock in
`processing_tmp_path`). On a host that kills long processes (DreamHost), cap each run and let
cron repeat it until the summary says `0 still without a hash`, then remove the line:

```cron
*/10 * * * * php /var/www/explorer/api/src/cron.php --backfill-sha256 --max-seconds=240 >> /var/www/explorer/api/logs/cron.log 2>&1
```

`--max-files=N` caps a run by count instead. A file whose stored object is missing, or whose
size differs from its row, is reported as `FAILED` and keeps its null; every run retries it.
Missing objects show up on the admin's orphan check. The exit code is 1 when any file failed.

### 8. Smoke test

```sh
curl https://files.example.com/api/v1/version
curl -X POST -H "Authorization: Bearer <key-secret>" -F "file=@test.jpg" \
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

Then empty the API cache, which keeps the `/error-codes` map and the `/changelog` until it's
cleared: delete the `*.json` files in `cache_path` (`api/cache/` by default), or use the admin's
Cache page.

### Upgrading to 1.4.0 (MagratheaPHP 3)

1.4.0 moves to a new framework package, so the update above needs three changes, once:

1. `composer install` fails ("Required package platypustechnology/magratheaphp is not present
   in the lock file"): `composer.lock` isn't committed, so the instance's own lock still pins
   `magratheaphp2`. Swap the framework in the lock instead:

   ```sh
   (cd api && composer update --no-dev --optimize-autoloader platypustechnology/magratheaphp platypustechnology/magratheaphp2)
   ```

   Plain `composer install` works again for later updates.
2. If `git pull` refuses because of local changes to `api/src/setup.php` (1.4.0 deletes it),
   run `git checkout -- api/src/setup.php` and pull again.
3. Apply `1.4.0_magrathea3.sql` right away: until it runs, uploads with tags and the
   scheduled-deletion admin fail. It's harmless to 1.3.0, so it can also run before the pull.

Then check the instance. Both must end with no ERROR:

```sh
cd api && vendor/bin/magrathea check && vendor/bin/magrathea models:verify
```

`check` warns if the backups folder (`backups_path`, default `api/backups`) is missing;
`scripts/deploy.sh install`'s "Create runtime directories" step creates it. Every admin logs in again after the
upgrade.

### Upgrading to 1.5.0

1.5.0 needs more than a pull and a migration: a Python venv for the image processor, a cron
line every minute, new PHP extensions, and a one-way change to how key secrets are stored. Follow
[v1.5.0-migration.md](v1.5.0-migration.md) from the top; the instance has to be on 1.4.0 first.

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
