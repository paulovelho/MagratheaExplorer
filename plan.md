# MagratheaExplorer — Design

Finalized design, settled through discussion. See `implementation-plan.md` for the concrete build plan (file scaffold, framework mechanics, route list, build order).

Two corrections to blueprint.md: `swagger.yaml` lives at `src/swagger.yaml` in MagratheaImages3, not `src/api/swagger.yaml` (and MagratheaExplorer's own equivalent is named `openapi.yaml`, not `swagger.yaml`). And the R2 module in Images3 is **backup-only** — there is no existing read path through R2 anywhere in that codebase, so "storage as the primary backend" has no direct precedent to copy there; MagratheaExplorer uses it as the real primary storage path, not backup-only.

---

## 1. Framework conventions (mirrored from MagratheaImages3)

Same MagratheaPHP2 patterns throughout:

- Model + Control pairs per entity (`Key`/`KeyControl`, `File`/`FileControl`, `Folder`/`FolderControl`), with codegenerated `Base/` classes from `magrathea_objects.conf`.
- Feature-based folders under `src/api/features/`: `Key/`, `Folder/`, `File/`, `Storage/` (new — see §2).
- Routes registered imperatively in a `MagratheaExplorerApi` subclass; auth levels as route params; path params via `:name` tokens.
- Errors: `MagratheaApiException` thrown from anywhere, caught centrally, `error_codes.conf` mapping 4-digit codes → HTTP status (`4042` → 404) exactly like Images3.
- Admin: `MagratheaExplorerAdmin`, built the same way as `MagratheaImagesAdmin`, on the framework's built-in `Magrathea2\Admin` system (separate auth from API keys, backed by `_magrathea_users`) — developer-only tool, no separate admin product needed.

---

## 2. Storage abstraction

```
interface StorageAdapter {
    public function put(string $path, string $localTmpFile, array $meta): void;
    public function delete(string $path): void;
    public function url(string $path): string;
    public function exists(string $path): bool;
}
```

Two implementations, not three:

- **`LocalStorageAdapter`** — writes under a configured folder that a vhost serves directly.
- **`S3StorageAdapter`** — one class for both R2 and real S3, parametrized by `endpoint` / `region` / `pathStyle` / `bucket` / credentials. R2 is S3-compatible (same way Images3's `R2Client` already talks to it via `async-aws/s3`), so R2 is just "S3 with a Cloudflare endpoint and `region: auto`" — no separate `R2StorageAdapter` needed. Adding real AWS S3 later costs nothing beyond a config entry.

Config picks exactly one driver instance-wide (`storage.driver = local|s3`). Lives in the static file-based `Config` (env-sectioned INI), not the DB-backed `ConfigApp` — changing storage backend for a running instance is a deploy-time decision, not a web-form edit (same reasoning Images3 applies to `medias_path`).

**Serving: direct-from-storage, no PHP in the read path.**

- S3/R2: at upload time, set the object's `Content-Type` and `Content-Disposition` as object metadata (see §9), then return the direct bucket URL (or a custom domain in front of it). No PHP execution per view, zero server cost per view, R2 has zero egress fees. Security comes from the URL path being an unguessable random token (§3), not from access control — there isn't any at fetch time.
- Local storage: files sit in a folder a vhost points at directly, bypassing PHP for GETs, same as the S3/R2 path.

The alternative (always proxy bytes through PHP, like Images3 does for local files today) would buy revoke-without-delete and server-side view logging, at the cost of a PHP execution per view — not worth it given the cost priority.

---

## 3. URL obfuscation

The bug in Images3 this fixes: `GET /image/{public_key}/{id}/{size}` puts the public_key *in every image URL*, and `id` is sequential — one leaked image link exposes the public_key, and incrementing `id` enumerates every other file that key owns.

Fix: each file gets its own random, unguessable token, generated independently of the key and of any other file's token — knowing one file's URL gives zero information about any other file, even ones in the same folder or owned by the same key.

- Token: 21-character base62 (~125 bits of randomness, nanoid-style), unique-indexed `token` column on `files`.
- URL shape: `https://<bucket-or-domain>/<token>.<ext>` (S3/R2 object key = the token; extension included for MIME hinting, doesn't weaken obfuscation).
- No sequential ID ever appears in a URL.
- Thumbnails get their own independent token (not derived from the file's token, so knowing one never reveals the other).

---

## 4. Key / auth model

Single secret key per user, no public/private split. Images3 needs the split because its public key has to appear in every read URL (safe to embed) while the private key stays secret; MagratheaExplorer's URLs carry no key material at all (§3), so there's no "safe to expose" credential to separate out. One `uuid`, used for every authenticated operation (upload, delete, list, query) via `Authorization: Bearer <uuid>` — never as a query param.

### Schema

```sql
CREATE TABLE `keys` (
    `id` int(11) PRIMARY KEY AUTO_INCREMENT,
    `uuid` char(36) NOT NULL UNIQUE,        -- the secret credential itself (Authorization header)
    `name` varchar(255) NOT NULL UNIQUE,    -- human identifier set at creation, not secret
    `uses` int(11) NOT NULL DEFAULT 0,      -- lifetime upload counter, incremented per successful upload
    `usage_limit` int(11) NULL,             -- cap on `uses`; null = unlimited
    `total_size` bigint(20) NOT NULL DEFAULT 0, -- running total bytes currently stored, adjusted on upload/delete
    `usage_limit_mb` int(11) NULL,          -- cap on `total_size` (MB); null = unlimited
    `expiration` datetime NULL,
    `active` tinyint(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE `scheduled_deletions` (
    `id` int(11) PRIMARY KEY AUTO_INCREMENT,
    `key_id` int(11) NOT NULL,
    `requested_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `execute_at` datetime NOT NULL,          -- requested_at + 14 days
    `cancelled_at` TIMESTAMP NULL,
    FOREIGN KEY (`key_id`) REFERENCES `keys`(`id`)
);
```

Dropped from Images3's table: `private_key`/`public_key` (replaced by single `uuid`), `folder` (replaced by the `folders` table, §5).

Two independent, optionally-capped dimensions, both enforced at upload time and both reportable: upload count (`uses`/`usage_limit`) and storage size (`total_size`/`usage_limit_mb`). Both caps default to `NULL` (unlimited) — capping is the exception, not the default, for either dimension.

`uses` mirrors Images3's semantics exactly — a **lifetime** counter, incremented on upload, never decremented on delete (a key that hits `usage_limit` stays capped even after deleting old files, unless the limit is raised). `total_size`, by contrast, decrements on delete to stay meaningful as "how much am I storing right now" — otherwise `usage_limit_mb` would eventually block uploads on a key that's actually mostly empty. The two caps behave differently under delete by design: uses-cap is permanent pressure, size-cap is not.

Enforcement is cheap: both checks (`uses < usage_limit`, `total_size + incoming_size <= usage_limit_mb * 1024 * 1024`) run against the same key row already loaded for auth, no extra query. `total_size` is updated with a single `+=`/`-=` in the same transaction as the file insert/delete — no live `SUM` needed at request time.

Deletion flow: `DELETE /key` inserts a `scheduled_deletions` row (14-day `execute_at`); a key with a pending scheduled deletion behaves like an inactive key (no uploads) immediately, even before the 14 days are up — "changed your mind" and "actually deleting" share one code path. A cancel endpoint sets `cancelled_at`. A cron (mirroring Images3's `backup.php` cron-entry pattern) sweeps rows past `execute_at` with `cancelled_at IS NULL`, deletes every file under the key from storage, then the key row itself.

### Key creation

Key creation is an **authenticated admin action** (via the `Magrathea2\Admin` login), not a public secret-guarded endpoint — no `POST /key/create` with a shared secret. Images3's shared-secret approach is a single point of compromise with no per-caller revocation; since keys here are always provisioned by the operator for each external project (never self-served by an untrusted party), there's no scenario that needs a public creation endpoint.

### External-project usage

Standard server-to-server API key usage: the external project's backend holds the MagratheaExplorer key as a server-side secret and calls this API on the end user's behalf; the end user never sees it because the external project never sends it to their own frontend. MagratheaExplorer's side of this is just: accept the key via `Authorization: Bearer <uuid>` header, require TLS, nothing bespoke needed.

### Key caching — none needed

Images3's cache trick (regenerate a plain PHP file containing every key, `include`d for lookups) exists because Images3's file-*serving* endpoint is a PHP request handling every single file view — high volume, latency-sensitive. MagratheaExplorer's file serving is direct-from-storage (§2) — no PHP request happens on file view at all, so there's no key lookup on that path. The only remaining key lookups are on upload/delete/list/report endpoints, comparatively low volume. A plain indexed query, `SELECT * FROM keys WHERE uuid = ?`, is sub-millisecond and sufficient — no cache layer.

---

## 5. Folders and files

```sql
CREATE TABLE `folders` (
    `id` int(11) PRIMARY KEY AUTO_INCREMENT,
    `key_id` int(11) NOT NULL,
    `parent_id` int(11) NULL,               -- null = this row IS a key's root folder
    `name` varchar(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_sibling_name` (`key_id`, `parent_id`, `name`),
    FOREIGN KEY (`key_id`) REFERENCES `keys`(`id`),
    FOREIGN KEY (`parent_id`) REFERENCES `folders`(`id`)
);

CREATE TABLE `files` (
    `id` int(11) PRIMARY KEY AUTO_INCREMENT,
    `token` char(21) NOT NULL UNIQUE,       -- URL identifier, see §3
    `thumbnail_token` char(21) NULL UNIQUE,
    `key_id` int(11) NOT NULL,              -- denormalized off folder_id, see below
    `folder_id` int(11) NOT NULL,
    `name` varchar(255) NOT NULL,           -- original filename, display only
    `storage_path` varchar(255) NOT NULL,   -- actual object key / disk path
    `extension` varchar(16) NULL,
    `mime_type` varchar(127) NOT NULL,      -- detected via finfo, not trusted from client
    `file_type` enum('image','audio','video','document','other') NOT NULL,
    `size` int(11) NOT NULL,
    `width` int(11) NULL,                   -- images only
    `height` int(11) NULL,
    `duration` int(11) NULL,                -- audio/video, seconds
    `no_convert` tinyint(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`key_id`) REFERENCES `keys`(`id`),
    FOREIGN KEY (`folder_id`) REFERENCES `folders`(`id`)
);

CREATE TABLE `tags` (
    `id` int(11) PRIMARY KEY AUTO_INCREMENT,
    `name` varchar(100) NOT NULL UNIQUE
);

CREATE TABLE `file_tags` (
    `file_id` int(11) NOT NULL,
    `tag_id` int(11) NOT NULL,
    PRIMARY KEY (`file_id`, `tag_id`),
    FOREIGN KEY (`file_id`) REFERENCES `files`(`id`),
    FOREIGN KEY (`tag_id`) REFERENCES `tags`(`id`)
);
```

One unified `files` table for every type (image/audio/video/document/other), not split per type — a folder can hold mixed types, "all files for a key/folder" is the most common query shape, and tags need a single clean FK target rather than a polymorphic reference across multiple type tables. `width`/`height`/`duration` stay as plain nullable columns rather than separate 1:1 extension tables — the storage cost of unused NULLs is negligible and the upload pipeline is already type-dispatched by construction, so there's no realistic cross-contamination risk a split would prevent; the join it would cost on every read isn't worth it for three ints.

Notes on choices:

- `key_id` on `files` is redundant with `folder_id → folders.key_id` — deliberate denormalization. "I need all the files from [key]" is a core query shape; one extra indexed int column beats forcing a join through `folders` on the hottest read path.
- One root folder per key, auto-created on key creation (`parent_id = NULL`). Every other folder nests under it or a descendant.
- Tags as a proper many-to-many rather than a comma-separated column — cheap to query and avoids duplicate-tag drift (`"logo"` vs `"Logo"`).
- `no_convert` is a per-upload flag, set by the caller in the upload request, honored by the image pipeline (§6) to skip webp conversion for that file while metadata stripping still happens (those two concerns are independent — metadata is always stripped).

### Size rollups

Folder size is computed on demand (indexed `SUM(size)` scoped to the folder, recursive CTE or app-side walk for nested descendants) — always correct, no write overhead; folder-size reporting isn't frequent enough to justify precomputing it. Key-level size (`total_size` on `keys`, §4) is the one number kept as a precomputed running total, since that one is checked on every upload for quota enforcement — single row, no tree, cheap to keep exactly correct.

---

## 6. Upload pipeline

Shared first step for every file type: `finfo`-based MIME sniffing (never the client-supplied extension — same defensive stance as Images3's `UploadUrl`), size check against `ConfigApp.max_upload_size` (5MB default), then dispatch by detected type.

**Images** (`jpg/jpeg/png/webp/gif/svg/bmp`):
1. Strip metadata — Images3's `MetadataStripper` logic, close to verbatim (JPEG APP1/APP13/COM removal with synthetic Orientation-only EXIF re-injection, PNG ancillary chunk removal, WebP EXIF/XMP RIFF chunk removal, GIF comment block removal, SVG DOM cleanup). Portable code, no Images3-specific dependency.
2. Compress: re-encode at a high quality setting via GD (no new dependency — matches Images3, bare GD not Imagick). "Without losing quality" means high-quality re-encode + stripped metadata, not literal lossless magic.
3. Webp conversion: **on by default**, `no_convert` opts out. Never converted: SVG (vector, meaningless) or animated GIF (bare GD can't preserve animation — would need Imagick, deliberately avoided).
4. Thumbnail: crop-to-square via the same `ResampleCalculator` crop-to-fill math Images3 has, size from `ConfigApp.thumbnail_size` (200px default), generated once at upload time (no on-demand resizing), own independent token.

**Audio/video**: `getID3` (pure-PHP, no external binary) for duration/bitrate/etc. No transcoding in v1 — real video shrinking needs `ffmpeg`, a heavy CPU cost and an external binary that may not be installable on cheap/shared hosting. Revisit only if storage costs actually become a problem in practice.

**PDF**: no compression in v1, same external-binary reasoning (Ghostscript). Store as-is.

**Other files**: MIME-detected, stored as `file_type = 'other'`, no special processing. See §9 for the serving security note.

---

## 7. Admin UI

Mirrors Images3's `Magrathea2\Admin` pattern: `MagratheaExplorerAdmin` registering feature modules (`KeyAdmin` — including the only key-creation path, `FolderAdmin`/`FileAdmin` browser, `ScheduledDeletionAdmin` — view/cancel/force-execute the queue, `ConfigAdmin` for `ConfigApp` values). Same role `GeneratedFileManager`/`MediaManager` play in Images3, including orphan-file cleanup tooling.

---

## 8. Caching

- Key lookups: no cache layer, plain indexed query — see §4.
- No generated-file cache table needed — the only generated artifact (the thumbnail) is created once at upload time and gets its own permanent DB row + token, not regenerated per-request the way Images3's arbitrary `WxH` resizes are.

---

## 9. Error handling & a security note on serving "other" files

Error codes extend Images3's exact convention (`error_codes.conf`, `4XXX`/`5XXX` → HTTP status via `intval(code/10)`, `ErrorCodes::Instance()->ThrowException()`). Codes needed at minimum: key expired / inactive / not found, folder not found (or name collision), file not found, file too large, unsupported/dangerous MIME, storage backend error, quota exceeded, scheduled-deletion-already-pending / not-pending-cancel-attempt.

Because files are served directly from storage with no PHP in the request path, response headers (`Content-Type`, `Content-Disposition`) have to be set **once, at upload time**, as object metadata — no per-request opportunity to correct them later. This matters for stored-XSS: an SVG or mislabeled HTML file served inline with a browser-executable content-type is a classic vector (Images3 serves SVGs raw today — fine there since it's a trusted image-hosting use case, less fine here where "other files" from arbitrary uploads are in scope). Concretely: force `Content-Disposition: attachment` for `file_type = 'other'` and for SVG, and never trust the client-supplied filename/extension for the `Content-Type` sent — always the sniffed MIME. (`X-Content-Type-Options: nosniff` can't actually be persisted via S3 `PutObject` metadata — no such param exists — so that header is a CDN/vhost-level deploy concern, not something the storage layer can guarantee; `Content-Disposition: attachment` is the real, S3-native protection.)

---

## 10. Config

`ConfigApp` (DB-backed, admin-editable): `max_upload_size` (5MB default), `thumbnail_size` (200px default), `webp_conversion_default` (bool).

Static `Config` (file-based, deploy-time): storage driver + credentials (§2), DB credentials, `app_url`, timezone. Same split Images3 already uses — some values need to be safely tunable at runtime, others should require a deploy to change.

---

## 11. Dependencies & deployment

- `platypustechnology/magratheaphp2` (framework)
- `async-aws/s3` + `symfony/http-client` + `nyholm/psr7` (storage — same stack Images3 already vendors for R2 backup, now used as the primary path instead of a side one)
- `james-heinrich/getid3` (audio/video metadata)
- GD extension (bare, no new dependency — matches Images3)
- No Ghostscript/ffmpeg dependency in v1 (see §6)

Deployment mirrors Images3: bare Apache/Caddy in prod, docker-compose for dev only. `openapi.yaml` (OpenAPI 3.0.3) + a `skills.md` narrative companion, same dual-doc pattern Images3 uses, version field kept in sync with `src/version` per this project's `claude.md`.
