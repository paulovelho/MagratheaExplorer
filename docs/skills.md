# Magrathea Explorer — Skills Guide for AI Agents

This document teaches AI agents how to correctly interact with the MagratheaExplorer API.
The full machine-readable contract is in `docs/openapi.yaml`.

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
  Every key gets an auto-created root folder (`parent_id = null`) at creation time.
- **File** — one uploaded object. A file always belongs to exactly one folder, and its key is
  denormalized onto the row for fast "all files for this key" queries. Every file gets its own
  URL served **directly from storage** (local vhost, S3, or R2) — there is no PHP in the read path,
  so an uploaded file's headers (Content-Type/Content-Disposition) are fixed at upload time and
  never change afterward.
- **URL obfuscation** — file URLs never contain the key or a sequential id. Each file (and each
  thumbnail, independently) gets its own random 21-character token; knowing one file's URL reveals
  nothing about any other file, even ones in the same folder or owned by the same key.

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

1. **Upload**: `POST /files` (multipart), fields `file` (required), `folder_id` (optional,
   defaults to the key's root folder), `no_convert` (images only), `tags` (comma-separated names).
   Response includes `url` and, for images that could be rasterized, `thumbnail_url`.
2. **List/query**:
   - `GET /files?folder_id=X` — files in one folder
   - `GET /files?file_type=audio` — all audio files for the key
   - `GET /files?tag=logo` — files carrying a tag
   - `GET /key/usage` — `uses`/`usage_limit`/`total_size`/`usage_limit_mb` (no live SUM — `total_size`
     is a precomputed running total, always correct)
   - `GET /folder/:id/size` — recursive subtree byte total for one folder (computed on demand)
3. **Delete a file**: `DELETE /file/:id` — removes it from storage and the database, and reduces
   the key's `total_size` immediately.
4. **Delete a key**: `DELETE /key` — schedules deletion 14 days out, does **not** delete anything
   yet. `POST /key/cancel-deletion` undoes it within that window. After 14 days a cron job deletes
   the key and every file it owns, permanently.

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
| URL | `/image/{public_key}/{id}/...` (key + sequential id in every URL) | `/{token}.{ext}` (random per-file token, no key, no id) |
| Resizing | on-demand `WxH` in the URL, disk-cached | fixed-size thumbnail, generated once at upload |
| Storage | local disk (R2 is backup-only) | local, S3, or R2 as the primary path (pick one per instance) |
| Key creation | `POST /key/create` + shared secret | admin panel only, no public endpoint |
