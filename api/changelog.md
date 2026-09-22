# Changelog

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
