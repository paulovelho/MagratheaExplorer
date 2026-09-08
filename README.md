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
| URLs | `/image/{public_key}/{id}/...` — key and sequential id in every URL | `/{token}.{ext}` — random per-file token, no key, no id |
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

1. **Install dependencies** (composer.json lives under `src/`, matching where
   `_inc.php`'s `../vendor/autoload.php` resolves to):
   ```
   cd src
   composer install
   ```

2. **Database**: create a database and load the schema.
   ```
   mysql -u<user> -p <dbname> < database/database.sql
   ```
   This includes both the framework's own `_magrathea_*` tables (users, config, logs,
   roles) and the project's own tables (`access_keys`, `folders`, `files`, `tags`,
   `file_tags`, `scheduled_deletions`).

   > Note: the primary key table is named `access_keys`, not `keys` — `KEYS` is a
   > reserved SQL keyword, and the framework doesn't backtick-quote table names in its
   > `Insert()`/`Update()`/`Delete()` SQL. Confirmed against a live MariaDB instance
   > during the build.

3. **Config**: copy the two sample config files and fill in real values.
   ```
   cp src/configs/magrathea.conf.sample src/configs/magrathea.conf
   cp src/configs/storage.conf.sample src/configs/storage.conf
   ```
   - `magrathea.conf` — DB credentials, `app_url`, timezone (same shape as Images3's).
   - `storage.conf` — pick exactly one storage driver, instance-wide:
     - `driver = "local"` — set `local_path` (a folder your vhost serves directly) and
       `local_url` (its public base URL).
     - `driver = "s3"` — set `s3_endpoint`/`s3_bucket`/`s3_access_key`/`s3_secret_key`.
       R2 is just S3 with a Cloudflare account endpoint and `s3_region = "auto"`; real
       AWS S3 needs no separate adapter, just different config values.

   Both files are gitignored — never commit real credentials.

4. **Web server**: point your vhost's document root at `src/api/`. `.htaccess` handles
   the URL rewriting for Apache; for local dev, PHP's built-in server works too (routes
   have to be hit via the raw `magrathea_control`/`magrathea_action`/`magrathea_params`
   GET params instead of pretty URLs, since `php -S` doesn't process `.htaccess`).

   For a local-storage deployment, the storage folder needs to be reachable at the URL
   configured in `storage.conf`'s `local_url` — outside `src/api/`'s own docroot if
   you're running a single vhost, so route or symlink it accordingly.

5. **First admin user**: visit `/admin.php` in a browser — with no admin users yet, it
   shows a first-run setup form to create one. This is the *only* way to create a key;
   there is no public key-creation endpoint (a deliberate difference from Images3 — see
   `plan.md` §4).

6. **Cron**: schedule `php src/api/cron.php --run` (e.g. daily) to sweep keys whose
   14-day scheduled-deletion window has passed. `--dry-run` lists what's due without
   deleting anything; add `--verbose` to either for per-row output.

## Directory layout

```
composer.json / composer.lock / vendor/   -> src/  (see note above)
database/database.sql                     -> framework tables + project schema
docs/openapi.yaml, docs/skills.md          -> API contract + AI-agent guide
src/
  version, changelog.md                   -> kept in sync on every version bump (see claude.md)
  configs/                                -> magrathea.conf, storage.conf, magrathea_objects.conf
  api/
    _inc.php, index.php, admin.php,       -> entry points
    api.php, cron.php
    error-manager/                        -> ErrorCodes + error_codes.conf
    shared/                               -> ExplorerApiControl (bearer-key resolution), SystemApi
    admin/                                -> MagratheaExplorerAdmin, Browser (cross-key file/folder browser)
    features/
      Storage/                            -> StorageAdapter interface, Local + S3 implementations
      Key/                                -> Key, ScheduledDeletion, their admin pages
      Folder/                             -> Folder (virtual, nestable, per-key)
      File/                               -> File, Tag, and the whole upload pipeline
```

## Quick usage example

```
# Get a key's own info and usage
curl -H "Authorization: Bearer <key-uuid>" https://your-host/key
curl -H "Authorization: Bearer <key-uuid>" https://your-host/key/usage

# Upload a file
curl -X POST -H "Authorization: Bearer <key-uuid>" \
  -F "file=@photo.jpg" -F "tags=vacation,summer" \
  https://your-host/files

# List files in a folder, or by type/tag
curl -H "Authorization: Bearer <key-uuid>" "https://your-host/files?folder_id=5"
curl -H "Authorization: Bearer <key-uuid>" "https://your-host/files?file_type=audio"
curl -H "Authorization: Bearer <key-uuid>" "https://your-host/files?tag=logo"
```

Full route list, request/response shapes, and error codes: `docs/openapi.yaml` and
`GET /error-codes`.

## Versioning

`src/version`, `src/changelog.md`, and `docs/openapi.yaml`'s `info.version` are kept in
sync on every version bump (see `claude.md`).
