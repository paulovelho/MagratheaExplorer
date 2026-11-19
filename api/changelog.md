# Changelog

## 1.5.0
unreleased
- **fix:** Security: an uploaded SVG could read any file on the server the PHP user can read.
  SVG metadata stripping parsed with entity substitution on, so an SVG declaring an external
  entity (`<!ENTITY x SYSTEM "file:///…">`) was stored with that file's contents inlined,
  `configs/` included. Substitution is off now. An SVG whose DTD declares anything but plain
  text entities (external or parameter entities, nested or character references, other
  declarations), or that libxml can't read up to its root element, is refused with 4151, on
  every upload path. Plain entities, as old Illustrator exports write them, still upload.
  After upgrading a 1.4.x instance, search the stored `.svg` files for `<!ENTITY` (attempts
  keep their DOCTYPE): if any turn up, rotate the DB password and the storage keys.
- **breaking:** `no_convert` is parsed as a real boolean. `1`, `true`, `yes` and `on` mean
  true; `0`, `false`, `no`, `off` and an empty value mean false (trimmed, case-insensitive;
  JSON `true`/`false`/`1`/`0` work too). Anything else is refused with 4009 (HTTP 400).
  Before, any non-empty value except `0` counted as true, so `no_convert=false` kept the
  original format: it converts to webp now. Every new request flag is parsed the same way.
- **improvement:** Verbatim storage. `verbatim=1` on `POST /files` stores the file byte for byte
  as sent: no metadata strip, no re-encode, so bit depth, ICC profile, DPI and EXIF (GPS
  included) are kept, and the download has the sender's sha256. Without the flag, the key's
  default applies (admin → Keys, "Images without verbatim flag"), else the instance's
  (`verbatim_default` in `magrathea.conf`, false when absent: nothing changes for existing
  keys). `verbatim=0` overrides a verbatim default. Bulk imports follow the key's default.
  Files get `verbatim`, and `GET /key` gets `verbatim_default` (the effective value).
- **breaking:** A non-verbatim image above the conversion limits is refused with 4131 (HTTP 413)
  before anything is stored. Defaults: 25 MB or 40 megapixels (`convert_max_bytes` and
  `convert_max_pixels` in `magrathea.conf`); a JPEG that has to be turned upright counts 1.5x
  its pixels. Stripping and re-encoding hold the whole image in PHP memory (~8 bytes per
  pixel), so the biggest of these uploads ended in a PHP fatal error; at `memory_limit = 512M`,
  images between 40 and roughly 60 MP still converted and are refused now. Send them with
  `verbatim=1`: verbatim images of any size get their renditions from the processor.
- **fix:** Photos with an EXIF orientation (portrait phone shots) were stored and thumbnailed
  sideways or mirrored: the re-encode wrote the pixels unrotated and dropped the tag. They're
  turned upright now, so their `width`/`height` are the upright ones. Files uploaded before
  1.5.0 don't change.
- **improvement:** Files have `sha256`: the SHA-256 of the stored bytes, lowercase hex. It's
  the sender's hash for verbatim images and every non-image file; a stripped or converted image
  has the hash of what's stored. Files from before 1.5.0 have `null` until
  `php cron.php --backfill-sha256` runs, which fetches each stored object back and hashes it
  (`--max-files`/`--max-seconds` cap a run). Not in public share responses.
- **improvement:** Per-key upload limit. A key can take bigger or smaller files than the rest of
  the instance: admin → Keys, "Max upload (MB)"; empty = the instance's `max_upload_size`
  (admin → App Config), so existing keys don't change. PHP's `upload_max_filesize` and
  `post_max_size` still cap every key, so `GET /key` gets `max_upload_size`, the limit that
  applies (bytes), and the key form and list flag a key PHP cuts short. `/settings` keeps
  showing the instance default. 4002's message says the limit now, and the file's size when
  known. Keys above 64 MB need PHP's limits raised: `docs/deploy.md`, "Large uploads".
- **improvement:** Image properties and renditions. Images are read and resized by a separate
  processor (pyvips/libvips, outside PHP's memory; `docs/deploy.md`, "Image processor"), from
  the stored bytes, verbatim or not. Files get `properties` (dimensions, EXIF orientation,
  alpha channel and opacity, declared DPI, bit depth, color space, ICC profile, animation),
  `processing` (`{status: none|pending|ready|failed, error}`) and `renditions`
  (`{name: {url, width, height, mime_type, size}}`). What a key's images get is its rendition
  profile (admin → Keys, up to 6 sizes in webp, png or jpg, 2048 px at most; `GET /key`'s
  `rendition_profile`); without one, a single `thumb` like the 1.4.x thumbnail. Renditions are
  upright, sRGB, never upscaled and carry no metadata (no GPS, even from a verbatim original).
  They don't count toward a key's usage. Images up to 16 MB and 40 MP get them during the
  upload; bigger ones, and bulk imports, are queued for `cron.php --process`. Admin →
  Processing shows the queue and its failures.
- **breaking:** Thumbnails come from the processor: an instance without `processor_command`
  makes none (new images get `processing.status: none`, `thumbnail_url: null`). An image above
  the inline limits (16 MB / 40 MP) has `thumbnail_url: null` in its upload response and gets it
  a minute or two later: poll `GET /file/{uuid}` until `processing.status` isn't `pending`.
  `thumbnail_url` is now the `thumb` rendition's URL. A BMP stored as BMP (verbatim or
  `no_convert`) gets no thumbnail: the processor has no BMP reader. Existing thumbnails keep
  their URLs.
- **improvement:** The default thumbnail is center-cropped (portraits were cropped from the
  top) and a transparent image's jpg thumbnail is flattened onto white (it was black). Same
  name, size and format as before. Existing thumbnails don't change until regenerated.
- **new:** `POST /file/{uuid}/renditions` makes a file's renditions again from the key's current
  profile, in the background, with new URLs (the old ones stop working once the new ones are
  made). Also `php cron.php --regenerate --key=ID [--folder=ID]` for a whole key, and a
  "regenerate" button in admin → Browser.
- **db:** `access_keys.verbatim_default` (NULL = the instance default),
  `access_keys.max_upload_size` (bytes, NULL = the instance default), `files.verbatim` and
  `files.sha256`. `access_keys.rendition_profile` (JSON, NULL = the instance default),
  `files.properties`, `files.processing_status` and `files.processing_error`, and the tables
  `file_renditions` and `processing_jobs`. The migration turns every existing thumbnail into a
  `thumb` rendition (same URL, null `width`/`height`/`size` until regenerated);
  `files.thumbnail_token`/`thumbnail_path` are no longer used. Upgrading needs
  `api/database/migrations/1.5.0_pipeline.sql`, which holds every 1.5.0 schema change
  (`docs/v1.5.0-migration.md`).
- **fix:** JSON request bodies are recognized by their media type. `application/json;
  charset=utf-8` used to be parsed as a form, so every field read as missing (4001); it works
  now, and so does any `+json` type. A JSON body that doesn't parse, or isn't an object (a
  string, number or list), is refused with 40010 (HTTP 400) instead of reading as empty. A body
  other than an upload is refused above 1 MB (413). A `POST /files` that isn't
  `multipart/form-data` got 4002 (File too large) whatever its size: it gets 4001 (`file`) now.
- **fix:** `/changelog` returns whole entries (it cut each one at its first line) of every type:
  `breaking`, `db` and `upgrade` entries were left out.
- **db:** `files.size` is a bigint. As a 32-bit int it stopped at 2 GiB: a bigger file was put in
  storage and then its row failed to insert (a 500, the object left behind in storage), or,
  without MySQL's strict mode, it was saved with a size of 2147483647. In
  `api/database/migrations/1.5.0_pipeline.sql`. Its ALTER rebuilds `files` (writes wait
  meanwhile): run it off-peak on a large instance.
- **breaking:** Every datetime the API returns is ISO 8601 in UTC: `created_at`,
  `"2026-11-17 03:22:10"` before (the server's `timezone`, no offset), is
  `"2026-11-17T06:22:10Z"` now. That's `created_at` on keys, folders, files and shares,
  `expiration` (`GET /key`), `execute_at` (`DELETE /key`), `last_viewed_at` and the health
  check's `time`. Same field names, still `/api/v1`. A client that appended the server's offset
  itself has to stop; `new Date(value)` reads the new format as is. The admin keeps showing local
  times.
- **new:** Key provisioning. A consuming project's backend can create keys itself, one per
  tenant: `POST /provision/keys` with `{"name": …}` and the provisioning secret, which the
  operator generates in admin → Provisioning (shown once). The new key comes back with its
  secret. Its settings (size and upload limits, verbatim default, rendition profile) are the
  ones set on that page, copied at creation; the request can't set any. Creating keys is all the
  secret does: each key is used, rotated and offboarded with its own secret, and its limits stay
  the operator's. *Max keys* caps how many exist (4033). Names are unique (4094). Failed
  authentications are limited to 10 per 15 minutes per client IP (4291, HTTP 429, with
  `Retry-After`); behind a proxy, set `client_ip_header` in `magrathea.conf`.
- **new:** `POST /key/rotate-secret`: a new secret for the calling key, returned once. The old
  one stops working immediately. The admin has a "Rotate secret" button too.
- **breaking:** Key secrets are stored as sha256 hashes, not in plaintext, so a database dump no
  longer hands out every key. Every key keeps working with the secret it has. But the admin
  shows a secret only once, right after the key is created or rotated: a lost secret can't be
  looked up any more, only rotated. Dumps from before the migration still hold every secret:
  delete them once you won't roll back.
- **improvement:** admin → Keys is paged (50 per page, newest first), can be filtered to keys
  made in the admin or provisioned ones, and refuses a key without a name or with another
  key's name (both used to fail with a database error, or save an empty name). A limit of 0
  stays 0 on a new key (it became "no limit"), and anything but a whole number is refused
  instead of saved as 0.
- **fix:** Saving a key in the admin cleared its expiration: the date field showed nothing for
  a stored expiration, and the save wrote that nothing back.
- **db:** `access_keys.uuid` (the plaintext secret) is replaced by `access_keys.secret_hash`;
  `access_keys.provisioned`; tables `provisioning` (one row) and `auth_failures`. In
  `api/database/migrations/1.5.0_pipeline.sql`. Take a dump first: it's the only way back.
- **new:** `Idempotency-Key` header on `POST /files` and `POST /provision/keys`, so a retry
  can't make a second file or key. A retry with the same key after the first request succeeded
  gets what it made, as it is now (same uuid, `uses` unchanged), with `Idempotent-Replayed:
  true`; while the first is still running, 4091 (409); with a different request (another file,
  folder, tags, flags or name), 4221 (422). A failed request doesn't count: its retry runs again.
  Keys are per credential, up to 255 printable ASCII characters (else 40011), kept at least
  24 h (`idempotency_retention_h` in `magrathea.conf`). A replayed `POST /provision/keys` returns
  the same key with a **new** secret: only the first one's hash was stored, and that response
  never arrived. Without the header, nothing changes.
- **fix:** An upload whose database insert failed left its object in storage, and a failure
  right after the insert left a file the key's `uses` and `total_size` didn't count. The row and
  both counters are written in one transaction now, and the object is removed when it fails.
- **db:** Table `idempotency_keys`, in `api/database/migrations/1.5.0_pipeline.sql`. Purged by
  `cron.php --process`.
- **new:** Uploads from a URL. `POST /files` takes `source_url` (in a JSON or form body) instead
  of `file`: the server downloads it and stores it like an upload, with the same fields, flags,
  limits and `Idempotency-Key`. **Off unless `source_url_enabled = "true"`** in `magrathea.conf`
  (40012 otherwise): it lets key holders make the server open outbound connections. Only
  `https://` on port 443 (`source_url_ports` adds more), to hosts whose every address is public
  (private, loopback, link-local and reserved ranges are refused, IPv6 included), at most 3
  redirects held to the same rules, no proxy, no cookies. Refused URLs are 40012 (400), a host
  name that doesn't exist included (the same message as a private one, so the server's internal
  names can't be probed); a failed download (an upstream status other than 200, a DNS lookup
  that failed, TLS, a timeout) is 5021 (502). `GET /settings` says whether it's on
  (`source_url_enabled`). The size limit
  is `GET /key`'s `max_upload_size`, checked against the `Content-Length` before downloading;
  the whole download gets `source_url_timeout_s` (90 s). The file's name: `name`, else the
  response's `Content-Disposition`, else the URL's last segment. Needs ext-curl
  (`docs/deploy.md`, "Uploads from a URL"). Neither `file` nor `source_url`, or both, is a 4001
  "file or source_url (exactly one)" (it said "file").
- **improvement:** `POST /files` takes `name`, the file's display name, for an upload too (the
  file part's filename when absent; over 255 characters or with control characters, 40013).
  `tags` can be a list in a JSON body; a value that's neither a string nor a list of strings is
  40013 (a form's `tags[]=` used to be a 500). A tag named `0` is no longer dropped.
- **new:** `POST /files/lookup` with `{"uuids": [...]}` (1 to 100) returns those of the key's
  files, from any folders, in the order asked: one request to poll the processing status of a
  batch of uploads. Unknown, malformed and other keys' uuids are left out, without an error.
  Over 100 is a 40013. `GET /files` no longer runs one tag query per listed file.
- **new:** `POST /file/{uuid}/rotate` gives a file and its renditions new URLs and deletes the old
  objects; the response has the new URLs, which callers that store URLs must save. **It doesn't
  revoke an old URL cached by a CDN**: objects are cached as immutable for a year, and an R2
  custom domain is behind Cloudflare's cache, so a URL fetched through it can keep working there
  until it's purged. A processing run that finishes in the middle makes it a 4093 (409, retry).
- **improvement:** Storage deletes that may fail without failing the request (objects replaced by
  a rotation or by new renditions, an upload whose row didn't save) are retried by
  `cron.php --process` instead of being left in storage: 100 a run, up to 20 times each, and
  admin → Processing lists the ones that keep failing.
- **db:** Table `storage_garbage`, in `api/database/migrations/1.5.0_pipeline.sql`.
- **new:** Direct uploads from a browser, without the key. The key's backend mints a single-use
  token with `POST /upload-tokens` (folder, `max_size`, `file_types`, `expires_in` up to 15
  minutes, `verbatim`, `no_convert`, `tags`), the browser posts the file to its `upload_url`
  (`POST /upload/{token}`, no auth, no CORS preflight) and gets the File, and the backend
  confirms with `GET /upload-tokens/{id}`. A refused upload uses the token up (the reason is in
  the GET); a server error leaves it usable. Errors 4046 (unknown token), 4092 (already used),
  4101 (expired). Expiry is checked when the upload arrives. Behind Cloudflare's proxy,
  request bodies stop at 100 MB (`docs/deploy.md`).
- **db:** Table `upload_tokens`, in `1.5.0_pipeline.sql`. Purged a week after expiry by
  `cron.php --process`.
- **new:** Recursive folder delete: `DELETE /folder/{uuid}?recursive=1` deletes the folder and
  everything under it. The subtree is gone for the API at once (404 on lookups, listings,
  uploads and its public shares; its name is free again). Up to 200 files it's deleted in the
  request (`{"deleted": true}`); a bigger one answers `{"deleted": false, "pending": true}` and
  `cron.php --process` deletes it, 15 s worth per run (`folder_delete_inline_files`,
  `folder_delete_max_s` in `magrathea.conf`). Its files keep answering `GET /file/{uuid}` until
  then. Without `recursive`, nothing changes.
- **fix:** Two deletes of the same file at the same time lowered the key's `total_size` twice.
- **db:** `folders.deleting_at`, in `1.5.0_pipeline.sql`.

## 1.4.0
2026-11-17
- **improvement:** Runs on MagratheaPHP 3 (`platypustechnology/magratheaphp`, namespace
  `Magrathea`), up from MagratheaPHP2 2.3.4. API routes and response shapes don't change,
  apart from the items below; int and boolean fields keep their 2.x JSON types.
- **fix:** Removed `api/src/setup.php`, the 2.x setup wizard. On 2.3 it had no lock, so any
  instance served an open wizard (with its SQL and user-reset actions) at `/setup.php`. First-run
  admin setup is still `magrathea.php`.
- **fix:** Admin actions that change data (key save and force-delete, scheduled-deletion cancel
  and force-execute, share/file/folder delete, orphan purge, duplicate purge, import) refuse
  anything but POST, so a GET link can't trigger them past the admin's CSRF check.
- **fix:** Timestamps now read back in the configured `timezone`: MySQL's session timezone
  follows PHP's. On a server whose MySQL runs in another zone, every `created_at` moves to local
  time (3 hours earlier for UTC vs `America/Sao_Paulo`), matching what create responses already
  returned. Rows of `scheduled_deletions.requested_at`/`cancelled_at` written before 1.4.0 read
  shifted by that offset. `db_sync_timezone = false` in the environment's section of
  `magrathea.conf` keeps the old behavior.
- **breaking:** An unknown API route answers HTTP 404 (it was HTTP 200 with `success: false`).
  The explorer app already handles both.
- **breaking:** Every admin has to log in again after the upgrade, and admin users with
  `active = 0` can no longer log in.
- **db:** `tags` and `scheduled_deletions` get `created_at`/`updated_at`, which MagratheaPHP 3
  needs on every model table: without them, every tag lookup (so every upload with tags) and
  every scheduled-deletion query fails. Additive only -- no backfill. Upgrading an instance on
  1.3.0 needs `api/database/migrations/1.4.0_magrathea3.sql`; a fresh install gets it from
  `database.sql`.
- **upgrade:** A plain `composer install` fails on an existing instance: its `composer.lock`
  (untracked) still pins `magratheaphp2`. Run, once,
  `(cd api && composer update --no-dev --optimize-autoloader platypustechnology/magratheaphp platypustechnology/magratheaphp2)`
  -- see "Updating an instance" in `docs/deploy.md`.

## 1.3.0
2026-09-22
- **new:** Share links. Any file or folder can be given a public link (`/app/s/{share-uuid}`)
  that opens with no login. A folder link is browsable recursively but clamped to the shared
  subtree -- anything outside it 404s. Links never expire; they live until the owner deletes
  them, and a dead or unknown link is a plain 404 with one message. The public payload carries
  no key uuid, key name or view count. Shared files are served by the same direct storage URLs
  the owner already gets, so **deleting a share stops the share page but does not invalidate a
  storage URL someone already copied** -- only deleting the file does that.
- **new:** A "Shared links" section at `/app/shares` listing every link the key owns with its
  view count and last-viewed date, plus a per-item Share dialog in the file list. Operators get
  a cross-key view of every link in the admin panel under **Shares**.
- **new:** Five API routes: `POST /shares`, `GET /shares`, `DELETE /share/:uuid` (authenticated),
  and the public `GET /shared/:uuid` and `GET /shared/:uuid/folder/:folder_uuid`.
- **db:** One new table, `shares`. Additive only -- no `ALTER`, no backfill. Upgrading an
  instance already on 1.2.0 needs `api/database/migrations/1.3.0_shares.sql`; a fresh install
  gets it from `database.sql`.

## 1.2.0
2026-09-22
- **breaking:** Folders and files are now addressed by UUID everywhere in the public API,
  not by database id. Every folder/file response now carries `uuid` (and `parent_uuid` /
  `folder_uuid`) instead of `id` / `parent_id` / `folder_id` -- those fields are gone, not
  kept alongside. Route params changed to match: `/folder/:uuid`, `/file/:uuid`, etc.
  Existing clients using the old int-based params/fields need to update; there is no
  compatibility shim.
- **breaking:** Storage layout changed to one directory per key
  (`{key-storage-uuid}/{token}.{ext}`) on both the local and S3/R2 drivers, keyed off a
  new, non-secret `storage_uuid` distinct from the key's bearer credential. This is a
  fresh start, not a migration: reload `database.sql` (wipes existing keys/folders/files)
  and re-import stored files from scratch. Every key gets a brand-new bearer uuid on
  re-creation -- every integration needs the new key applied at cutover.

## 1.1.1
2026-09-19
- **new:** Admin duplicate-finder tool: lists files that collide on (key, folder, name) -- the shape a re-run import can produce -- grouped side by side, with a one-click purge that keeps the newest copy of each group and deletes the rest.
- **new:** Downloaded files keep their original filename instead of the opaque storage token, via a proper `Content-Disposition` header (both local storage, through a new `.htaccess`/Caddy rule, and S3/R2, via object metadata).
- **new:** Custom favicon for both the app and the admin panel.

## 1.1.0
2026-09-09
- **new:** Admin-only folder import tool: recursively mirrors a directory already on the server's filesystem into a chosen key/folder, running every file through the normal upload pipeline (MIME sniffing, image re-encode/thumbnail/metadata strip). Re-running an import reuses the folder tree it already created. Bypasses the key's usage_limit/usage_limit_mb caps (still blocked by inactive/expired/pending-deletion).

## 1.0.0
2026-09-08
- **new:** Initial release: key/folder/file management, local + S3/R2 storage, image processing pipeline (metadata stripping, webp conversion, thumbnails), scheduled key deletion, tags.
