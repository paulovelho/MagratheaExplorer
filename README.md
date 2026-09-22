# Magrathea Explorer

Self-hosted, general-purpose file hosting API — images, audio, video, documents, and
anything else — built on the in-house **MagratheaPHP2** framework. A sibling to
[MagratheaImages3](../MagratheaImages3), but storage-agnostic (local disk, Cloudflare R2,
or AWS S3 — pick one per instance) and not limited to images.

Full background and design rationale live in `blueprint.md` (original requirements),
`plan.md` (finalized design decisions), and `implementation-plan.md` (build plan this
codebase followed). This README is the practical "how do I run this" entry point.

## How it's different from MagratheaImages3

| | Images3 | Explorer |
|---|---|---|
| Auth | private/public key pair | single bearer key (`Authorization: Bearer <uuid>`) |
| File types | images only | image / audio / video / document / other |
| URLs | `/image/{public_key}/{id}/...` — key and sequential id in every URL | `/{key-storage-uuid}/{token}.{ext}` — one directory per key, random per-file token, no bearer key, no sequential id |
| Resizing | on-demand `WxH`, disk-cached | fixed-size thumbnail, generated once at upload |
| Storage | local disk (R2 is backup-only) | local, S3, or R2 as the *primary* path |
| Key creation | `POST /key/create` + shared secret | admin panel only — no public endpoint |

See `docs/skills.md` for the full comparison and API mental model, and `docs/openapi.yaml`
for the machine-readable route contract.

## Requirements

- PHP 8.1+ with `gd`, `mysqli`, `dom`, `fileinfo`, `exif` extensions
- MariaDB/MySQL
- Composer
- One of: a local folder a vhost can serve directly, or an S3/R2 bucket + credentials

## Setup

1. **Install dependencies** (composer.json lives under `api/`, matching where
   `_inc.php`'s `../vendor/autoload.php` resolves to):
   ```
   cd api
   composer install
   ```

2. **Database**: create a database and load the schema.
   ```
   mysql -u<user> -p <dbname> < api/database/database.sql
   ```
   This includes both the framework's own `_magrathea_*` tables (users, config, logs,
   roles) and the project's own tables (`access_keys`, `folders`, `files`, `tags`,
   `file_tags`, `scheduled_deletions`, `shares`).

   `database.sql` is the **fresh-install** schema. To upgrade an instance that is already
   running, apply the files in `api/database/migrations/` instead — each is named for the
   version it brings the schema up to (e.g. `1.3.0_shares.sql`).

   > Note: the primary key table is named `access_keys`, not `keys` — `KEYS` is a
   > reserved SQL keyword, and the framework doesn't backtick-quote table names in its
   > `Insert()`/`Update()`/`Delete()` SQL. Confirmed against a live MariaDB instance
   > during the build.

3. **Config**: copy the two sample config files and fill in real values.
   ```
   cp api/configs/magrathea.conf.sample api/configs/magrathea.conf
   cp api/configs/storage.conf.sample api/configs/storage.conf
   ```
   - `magrathea.conf` — DB credentials, `app_url`, timezone (same shape as Images3's).
   - `storage.conf` — pick exactly one storage driver, instance-wide:
     - `driver = "local"` — set `local_path` (a folder your vhost serves directly) and
       `local_url` (its public base URL).
     - `driver = "s3"` — set `s3_endpoint`/`s3_bucket`/`s3_access_key`/`s3_secret_key`.
       R2 is just S3 with a Cloudflare account endpoint and `s3_region = "auto"`; real
       AWS S3 needs no separate adapter, just different config values.

   Both files are gitignored — never commit real credentials.

4. **Web server**: point your vhost's document root at `api/src/`. The JSON API only
   answers under `/api/v1/...` — `api/src/.htaccess` requires that prefix on every
   route, so `GET /api/v1/version` works but bare `GET /version` 404s. `/admin.php`
   (and any other real file directly under `api/src/`) stays reachable unprefixed,
   since it's served directly rather than routed. For local dev, PHP's built-in server
   works too (routes have to be hit via the raw
   `magrathea_control`/`magrathea_action`/`magrathea_params` GET params instead of
   pretty URLs, since `php -S` doesn't process `.htaccess`; the params themselves are
   unprefixed — no `api/v1`).

   `/app` (the admin app, built from `app/` into the repo-root `dist/`, committed to
   git) needs no vhost config at all: `api/src/app` is a symlink to `../../dist`,
   committed alongside it, and `api/src/.htaccess` sets `Options +FollowSymLinks` so
   it resolves even on hosts that don't enable that by default. "Point the docroot at
   `api/src/`" is the whole setup — nothing extra to configure for `/app` on bare
   Apache/shared hosting. (In the docker-compose dev stack, `docker/apache/site-dev.conf`
   instead `Alias`es `/app` straight at the bind-mounted `dist/`, which just shadows
   the symlink — same result either way.)

   One more path needs routing outside `api/src/`'s docroot, via vhost `Alias` (see
   `docker/apache/site-dev.conf` for the dev version):
   - `/storage` → `storage.conf`'s `local_path`, so `local_url` resolves. Only needed
     for the `local` storage driver, not s3/R2 — and not solvable with a committed
     symlink like `/app`, since `storage/` is a gitignored runtime directory, not a
     build artifact.

   For the `local` driver, also copy `api/storage/.htaccess` into that `local_path`
   directory on the server (it's excluded from `storage/`'s gitignore specifically so
   it ships in the repo, but it has to physically live next to the uploaded files, not
   under `api/src/`). It's what turns a download URL's `?disposition=&filename=` query
   string into a real `Content-Disposition` response header, so downloads save as the
   file's original name instead of its opaque `{token}.{ext}` storage key — see
   `StorageAdapter::url()`. Requires `mod_headers` enabled alongside `mod_rewrite`
   (both on by default on DreamHost and most shared hosts; `docker/Dockerfile` enables
   both for the docker-compose dev stack). Caddy-fronted instances get the same
   behavior from `docker/caddy/site.caddy`'s `/storage` block instead — no `.htaccess`
   involved there.

   `local_path` gets one subdirectory per key, named for that key's own (non-secret)
   storage uuid — `{local_path}/{key-storage-uuid}/{token}.{ext}` — created on demand by
   `LocalStorageAdapter::put()`, nothing to provision up front. The same `.htaccess` also
   sets `Options -Indexes` at `local_path`'s root (inherited by every key subdirectory),
   since a listing of one is now a complete inventory of that key's files.

5. **First admin user**: visit `/admin.php` in a browser — with no admin users yet, it
   shows a first-run setup form to create one. This is the *only* way to create a key;
   there is no public key-creation endpoint (a deliberate difference from Images3 — see
   `plan.md` §4).

6. **Cron**: schedule `php api/src/cron.php --run` (e.g. daily) to sweep keys whose
   14-day scheduled-deletion window has passed. `--dry-run` lists what's due without
   deleting anything; add `--verbose` to either for per-row output.

## Directory layout

`api/` and `app/` are independent top-level projects, each with its own `src/`:

```
docs/openapi.yaml, docs/skills.md          -> API contract + AI-agent guide
api/                                        -> JSON API project (document root: api/src/)
  composer.json / composer.lock / vendor/  -> see note above
  version, changelog.md                    -> kept in sync on every version bump (see claude.md)
  configs/                                 -> magrathea.conf, storage.conf, magrathea_objects.conf
  database/database.sql                    -> framework tables + project schema (fresh installs)
  database/migrations/                     -> per-version upgrade scripts for a running instance
  cache/, logs/, storage/                  -> runtime dirs (gitignored, mounted into the container)
                                                storage/ holds one subdirectory per key
                                                ({key-storage-uuid}/{token}.{ext}), driver-agnostic
  src/                                     -> served at /api/v1 (.htaccess enforces the prefix)
    _inc.php, index.php, admin.php,        -> entry points (admin.php stays unprefixed, e.g. /admin.php)
    api.php, cron.php
    error-manager/                         -> ErrorCodes + error_codes.conf
    shared/                                -> ExplorerApiControl (bearer-key resolution), SystemApi
    admin/                                 -> MagratheaExplorerAdmin, Browser (cross-key file/folder browser)
    features/
      Storage/                             -> StorageAdapter interface, Local + S3 implementations
      Key/                                 -> Key, ScheduledDeletion, their admin pages
      Folder/                              -> Folder (virtual, nestable, per-key)
      File/                                -> File, Tag, and the whole upload pipeline
      Share/                               -> Share links: owner API, public API, admin page
app/                                        -> Vue 3 + Vite admin app source (see script/build.sh)
dist/                                       -> app/'s build output, committed to git; served at /app
                                                via api/src/app (a committed symlink to ../../dist)
```

Routes the app serves under `/app` (all client-side, resolved by `app/public/.htaccess`'s
SPA fallback):

```
/app/folder/{folder-uuid}?          -> the file explorer (authenticated, key in localStorage)
/app/shares                         -> "Shared links": every share link this key owns
/app/s/{share-uuid}                 -> the PUBLIC share page: no key, no login, and no link
/app/s/{share-uuid}/{folder-uuid}      back into /app or to the owner's identity
```

## Quick usage example

```
# Get a key's own info and usage
curl -H "Authorization: Bearer <key-uuid>" https://your-host/api/v1/key
curl -H "Authorization: Bearer <key-uuid>" https://your-host/api/v1/key/usage

# Upload a file
curl -X POST -H "Authorization: Bearer <key-uuid>" \
  -F "file=@photo.jpg" -F "tags=vacation,summer" \
  https://your-host/api/v1/files

# List files in a folder, or by type/tag
curl -H "Authorization: Bearer <key-uuid>" "https://your-host/api/v1/files?folder_uuid=<folder-uuid>"
curl -H "Authorization: Bearer <key-uuid>" "https://your-host/api/v1/files?file_type=audio"
curl -H "Authorization: Bearer <key-uuid>" "https://your-host/api/v1/files?tag=logo"

# Share a folder, then open it with no credentials at all
curl -X POST -H "Authorization: Bearer <key-uuid>" \
  -d "folder_uuid=<folder-uuid>" https://your-host/api/v1/shares
curl https://your-host/api/v1/shared/<share-uuid>
```

Full route list, request/response shapes, and error codes: `docs/openapi.yaml` and
`GET /api/v1/error-codes`.

## Versioning

`api/version`, `api/changelog.md`, and `docs/openapi.yaml`'s `info.version` are kept in
sync on every version bump (see `claude.md`).
