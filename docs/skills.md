# Magrathea Explorer — Skills Guide for AI Agents

This document teaches AI agents how to correctly interact with the MagratheaExplorer API.
The full machine-readable contract is in `docs/openapi.yaml`.

All routes below are relative to the API base path `/api/v1` (e.g. `GET /version` means
`GET https://your-host/api/v1/version`) — see `docs/openapi.yaml`'s `servers.url`. The
separate PHP admin panel (`/admin.php`) and the new Angular/Vue admin app (`/app`, not
built yet) live outside this prefix.

---

## Mental model

MagratheaExplorer is a **general-purpose file hosting** service (images/audio/video/documents/other),
not an image-only service. The core concepts are:

- **Key** — a single secret credential (`Authorization: Bearer <uuid>`) that represents one external
  caller/project. There is no public/private key split (unlike MagratheaImages3) and **no public
  key-creation endpoint** — keys are provisioned by the operator through the admin panel only.
  A key optionally caps two independent things: upload count (`usage_limit`) and total stored bytes
  (`usage_limit_mb`). Both default to unlimited.
- **Folder** — a virtual (DB-only, not a real filesystem path) container, nestable, scoped to one key.
  Every key gets an auto-created root folder (`parent_uuid = null`) at creation time. Folders and
  files are addressed everywhere in this API by **uuid** (a UUIDv7); the internal integer id is
  never exposed.
- **File** — one uploaded object. A file always belongs to exactly one folder, and its key is
  denormalized onto the row for fast "all files for this key" queries. Every file gets its own
  URL served **directly from storage** (local vhost, S3, or R2) — there is no PHP in the read path,
  so an uploaded file's headers (Content-Type/Content-Disposition) are fixed at upload time and
  never change afterward.
- **Share** — an opt-in public link to one file or one folder, readable with no key at all. The
  share's own uuid *is* the credential: holding the link is the entire access check. A folder share
  is browsable recursively but clamped to the shared subtree. Shares never expire — a link lives
  until its owner deletes it. See **Sharing** below.
- **URL obfuscation** — file URLs never contain the key's bearer uuid or a sequential id. Each file
  (and each thumbnail, independently) gets its own random 21-character token, and every key's
  objects live under a separate storage directory named for that key's own (non-secret) storage
  uuid; knowing one file's URL reveals nothing about any other file's name or content, even ones in
  the same folder.

  A **share uuid is a third kind of opaque identifier**, alongside the per-file storage token and
  the folder/file uuids. Unlike those two it is a bearer credential: anyone holding it can read the
  shared subtree. Treat it like a password in anything you build — don't log it, don't put it in a
  page someone else can read.

---

## Step 0 — discover the server

```
GET /version
GET /settings
```

`/settings` returns the max upload size and default thumbnail size:
```json
{ "max_upload_size": "5242880", "max_upload_size_formatted": "5 MB", "thumbnail_size": 200 }
```

```
GET /changelog      -- 5 most recent versions parsed from changelog.md
GET /error-codes    -- full map of error codes -> messages (see Error shape below)
```

---

## Auth

Every route except the system endpoints above requires:
```
Authorization: Bearer <key-uuid>
```

There is no login flow and no token refresh — the uuid itself is the long-lived credential.
A 401 means the header is missing/malformed; a 403 on `/key` routes never happens (a key can
always read its own info); a 403 on upload means a quota (uses or size) was hit.

A key with a **pending scheduled deletion** (see below) behaves exactly like an inactive key —
uploads are blocked immediately, even though the actual deletion is still up to 14 days away.

---

## Typical flow

1. **Upload**: `POST /files` (multipart), fields `file` (required), `folder_uuid` (optional,
   defaults to the key's root folder), `no_convert` (images only), `tags` (comma-separated names).
   Response includes `url` and, for images that could be rasterized, `thumbnail_url`.
2. **List/query**:
   - `GET /files?folder_uuid=X` — files in one folder
   - `GET /files?file_type=audio` — all audio files for the key
   - `GET /files?tag=logo` — files carrying a tag
   - `GET /key/usage` — `uses`/`usage_limit`/`total_size`/`usage_limit_mb` (no live SUM — `total_size`
     is a precomputed running total, always correct)
   - `GET /folder/:uuid/size` — recursive subtree byte total for one folder (computed on demand)
3. **Delete a file**: `DELETE /file/:uuid` — removes it from storage and the database, and reduces
   the key's `total_size` immediately.
4. **Delete a key**: `DELETE /key` — schedules deletion 14 days out, does **not** delete anything
   yet. `POST /key/cancel-deletion` undoes it within that window. After 14 days a cron job deletes
   the key and every file it owns, permanently.

---

## Sharing

A share hands out a link that anyone can open without a key:

```
POST   /shares            file_uuid=<uuid>     # XOR folder_uuid -- exactly one, else 4001
GET    /shares            ?file_uuid= / ?folder_uuid=    # this key's links, newest first
DELETE /share/:uuid
```

`POST /shares` returns the link itself as `url` (`{app_url}/app/s/{share-uuid}`), plus
`target_type`, `target_name`, `views` and `last_viewed_at`. Sharing the same target twice is
allowed and yields two independent links. Sharing the key's root folder is allowed; it reports as
`"All files"`, never the internal row name `"root"`.

The two public routes take **no `Authorization` header at all**:

```
GET /shared/:uuid                          # the share's landing payload
GET /shared/:uuid/folder/:folder_uuid      # browse into a folder share
```

A file share returns `{ "type": "file", "name", "file": {...} }`; a folder share returns
`{ "type": "folder", "name", "path": [...], "folders": [...], "files": [...] }`, where `path` is a
breadcrumb starting **at the share root** — never a folder above it. File entries are a strict
subset of the normal file shape (no `folder_uuid`, `no_convert` or `tags`).

Things to get right when building against this:

- **The public payload never names the owner.** No `key_id`, no key uuid, no key name, no `views`.
  Don't try to correlate a share back to a key from the public side; you can't, by design.
- **Everything that can go wrong on a public route returns the same 404.** An unknown uuid, a
  deleted share, and a share whose key is inactive, expired or pending deletion are all `4045`
  with one message: *"This link is not valid."* A folder outside the shared subtree, above it, or
  belonging to another key is all `4042`. Show one generic message; there is nothing more to tell.
- **Deleting a share does NOT invalidate file URLs that were already copied.** Shared files are
  served by the same direct-from-storage URLs the owner gets, with no share in the read path.
  Deleting the share stops the share *page*; the only thing that stops an already-copied file URL
  is deleting the file. Don't tell a user otherwise.
- **`views` counts successful public loads only**, never a 404, and is visible to the owner only.

---

## Image processing

Every image upload: metadata (EXIF/IPTC/XMP/comments) is always stripped, regardless of any other
option. By default the stored image is also re-encoded to webp; pass `no_convert=1` to keep the
original format (metadata stripping still happens). SVG and animated GIF are never converted to
webp (SVG is already vector; converting an animated GIF through bare GD would destroy the
animation). A square crop-to-fill thumbnail (`thumbnail_size` from `/settings`) is generated once,
at upload time — there's no on-demand resizing of any kind, unlike MagratheaImages3.

---

## Error shape

```json
{ "success": false, "data": { "code": 4041, "message": "Key not found" } }
```

The HTTP status is derived from the code: `intdiv(code, 10)` for 4-digit codes (`4041` → `404`).
Fetch the full map from `GET /error-codes`.

---

## What's different from MagratheaImages3

| | Images3 | Explorer |
|---|---|---|
| Auth | private/public key pair | single bearer key |
| File types | images only | image/audio/video/document/other |
| URL | `/image/{public_key}/{id}/...` (key + sequential id in every URL) | `/{key-storage-uuid}/{token}.{ext}` (per-key directory, random per-file token, no bearer key, no sequential id) |
| Resizing | on-demand `WxH` in the URL, disk-cached | fixed-size thumbnail, generated once at upload |
| Storage | local disk (R2 is backup-only) | local, S3, or R2 as the primary path (pick one per instance) |
| Key creation | `POST /key/create` + shared secret | admin panel only, no public endpoint |
