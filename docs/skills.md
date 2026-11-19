---
name: magrathea-explorer-api
description: How to authenticate and integrate with the MagratheaExplorer API (general-purpose file hosting for images, audio, video, documents and anything else; folders, tags, direct storage URLs, public share links, key usage). Use when a service needs to upload, list, serve or delete files through MagratheaExplorer, or when writing a client for its /api/v1.
---

# MagratheaExplorer API: Skills Guide for AI Agents

This guide shows AI agents how to integrate another project with the MagratheaExplorer API.
The full contract is `docs/openapi.yaml` in the MagratheaExplorer repo (OpenAPI 3.0, every
route, envelope included). Use that file for exact field types. This guide covers how the
API behaves and what tends to go wrong.

Every route is relative to `{app_url}/api/v1`. For example, `GET /files` means
`GET https://explorer.example.com/api/v1/files`. A bare `/files` without the prefix is a 404.

---

## Mental model

- **Key**: one bearer secret (`Authorization: Bearer <secret>`, a UUID) per consuming project,
  or per tenant of one. An operator creates keys in the admin panel (`/admin.php` → Keys), or a
  backend creates them with `POST /provision/keys` (Workflow 8). The secret is shown **once**,
  when the key is created or rotated: only its hash is stored, so nobody can look it up later,
  the operator included. The key has full power over everything it owns: upload, rename, delete,
  share, rotate its own secret, and even schedule its own deletion. No scopes, no read-only
  keys, no login, no refresh.
- **Folder**: a virtual container in the database only, nestable and owned by one key. Every key
  has a hidden **root folder**. Whenever a route takes `folder_uuid`/`parent_uuid`, leaving it
  out means "root". The root never shows up in a listing.
- **File**: one uploaded object, always inside exactly one folder. It's served **directly
  from storage** (local vhost, S3 or R2) at a URL that needs no auth. PHP isn't involved in
  reads, so headers like Content-Type and Content-Disposition are fixed at upload time.
- **Rendition**: a derivative of an image (a thumbnail, a preview), made by the server from
  the key's **rendition profile**, a list of sizes and formats the operator sets per key
  (default: one 200 px square `thumb`). Renditions are public URLs like the original, carry no
  metadata, and don't count toward the key's usage. Small images get them during the upload;
  big ones a minute or two later (`processing.status: pending`, then `ready`).
- **Share**: an optional public link to one file or one folder subtree. The share's uuid
  is the credential.
- **Identifiers**: folders, files and shares are addressed by lowercase UUIDv7. Integer ids
  are never exposed.
- **Timestamps**: every datetime in a response is ISO 8601 in UTC, ending in `Z`
  (`2026-11-17T06:22:10Z`, whole seconds). `new Date(value)` parses it as is. Before 1.5.0 they
  were the server's local time with no offset.

**Files are not private.** Every file's `url` works for anyone who has it, until the file is
deleted. The only protection is a random 125-bit token in the path, under a per-key
directory. There are no signed or expiring URLs and no per-viewer access control. Don't store
anything here that needs per-user authorization at read time.

---

## Step 0: configuration and discovery

Give the consuming project two settings, kept server-side only:

```
EXPLORER_API_URL=https://explorer.example.com/api/v1
EXPLORER_API_KEY=<key secret, from the operator or from POST /provision/keys>
```

Then check that the server is reachable and read its limits:

```
GET /version        → { api, version, environment, magrathea_version }
GET /settings       → { app_name, max_upload_size: "5242880", max_upload_size_formatted: "5 MB", thumbnail_size: 200, source_url_enabled: false }
GET /health-check   → { health: "ok", time, database: "ok" | "fail" }
GET /key            → confirms the key works: { name, uses, usage_limit, total_size, usage_limit_mb, verbatim_default, max_upload_size: 157286400, rendition_profile: [...], expiration, active, created_at }
```

The upload limit is `GET /key`'s `max_upload_size`: bytes, for this key. Keys can have their own
limit, so it can differ from `/settings`' `max_upload_size`, which is only the instance default.
It already includes PHP's `upload_max_filesize`/`post_max_size`. Reject larger files on the
client anyway, so users get a clear message instead of a 4002.

`GET /key`'s `rendition_profile` lists the renditions this key's images get, e.g.
`[{ name: "thumb", width: 300, height: 300, fit: "contain", format: "webp", quality: 85 }, { name: "preview", width: 1200, ... }]`.
Only the operator changes it (admin → Keys). `/settings`' `thumbnail_size` is the instance
default's `thumb`, which a key with its own profile doesn't use.

---

## The envelope (read this first)

**Every** response is wrapped, on success and on failure:

```json
{ "success": true,  "data": { ...payload... } }
{ "success": false, "data": { "type": "exception", "code": 4042, "message": "Folder not found", "error": null, "debug_level": "..." } }
```

- Unwrap `data`. Every schema shown below is the `data` payload.
- Treat `success !== true` as failure even with a 2xx status.
- The HTTP status comes from the code: 4-digit codes are divided by 10 (`4031` → 403,
  `4151` → 415, `5001` → 500), 5-digit codes by 100 (`40010` → 400).
- `message` can carry a detail after a colon: `"Missing required field: name"`.
- An unhandled server error has no `code`, only `{ "message": "Internal Server Error" }`.

---

## Auth

```
Authorization: Bearer <key-uuid>
```

Required on everything except `/version`, `/health-check`, `/settings`, `/changelog`,
`/error-codes`, the two public `/shared/...` routes and `POST /upload/{token}` (Workflow 9).

**Every auth failure looks the same:** a missing header, an unknown key, an inactive key and
an expired key all give HTTP 401 with `code: 401` and the message
`Authorization Failed! (IsAuthenticated = false)`. Codes 4011/4012/4013/4041 appear in
`/error-codes` but never reach a client. If you need to know *why* a key fails, an operator
has to look in the admin panel.

CORS is open (`*`). **Don't use that as a reason to call the API from a browser** with
the key. Anyone who reads the key from the page can delete every file. Keep the key on your
backend. For uploads straight from a browser, mint an upload token (Workflow 9).

`POST /provision/keys` takes a different credential, the provisioning secret (`xpv_…`), and
no other route takes it (Workflow 8).

---

## Request bodies

| Route kind | Content-Type |
|---|---|
| `POST /files` with `file` (upload) | `multipart/form-data` |
| `POST /files` with `source_url` (Workflow 1) | `application/json`, `application/x-www-form-urlencoded` or `multipart/form-data` |
| other POST / PUT | `application/x-www-form-urlencoded` or `application/json` |

A body is JSON when its media type is `application/json` or ends in `+json`, whatever the
parameters and case (`application/json; charset=utf-8` is fine). It has to be an object:
invalid JSON, or a JSON string, number or list, gets **40010**. Bodies other than uploads stop
at 1 MB (413).

---

## Workflow 1: Upload a file

```
POST /files
Authorization: Bearer <key>
Content-Type: multipart/form-data

file=<binary>                 required, field name must be "file", one file per request
                              (or source_url instead: see "Uploading from a URL" below)
name=<display name>           optional, defaults to the file part's filename
folder_uuid=<uuid>            optional, defaults to the key's root folder
tags=invoice,2026             optional, comma-separated
verbatim=1                    optional, store the bytes exactly as sent (default: GET /key's verbatim_default)
no_convert=1                  optional, images only, keep the original format (still re-encoded)
```

Boolean fields (`verbatim`, `no_convert`) take `1 true yes on` or `0 false no off` (or empty),
case-insensitive. Anything else is refused with 4009 (400).

```bash
curl -s -H "Authorization: Bearer $EXPLORER_API_KEY" \
  -F "file=@./report.pdf" -F "folder_uuid=$FOLDER" -F "tags=invoice,2026" \
  "$EXPLORER_API_URL/files"
```

Response `data` (a **File**):

```json
{
  "uuid": "0196b1d3-5c2e-7a10-8f3b-6d9e2a4c7b15",
  "folder_uuid": "0196b1d2-7f3e-7c4a-9b1e-2f6a8d4c1e90",
  "name": "photo.jpg",
  "extension": "webp",
  "mime_type": "image/webp",
  "file_type": "image",
  "size": 48213,
  "sha256": "3a7bd3e2360a3d29eea436fcfb7e44c735d117c42d1c1835420b6b9942dd4f1b",
  "width": 1920,
  "height": 1080,
  "duration": null,
  "no_convert": false,
  "verbatim": false,
  "properties": {
    "width": 1920, "height": 1080, "orientation": null, "has_alpha_channel": false, "is_opaque": true,
    "dpi_x": 72, "dpi_y": 72, "bit_depth": 8, "color_space": "srgb", "has_icc_profile": false,
    "animated": false, "frame_count": 1
  },
  "processing": { "status": "ready", "error": null },
  "url": "https://files.example.com/<key-storage-uuid>/Xk3pQ9vT2mLr8sWn4bYc7.webp",
  "renditions": {
    "thumb": { "url": "https://files.example.com/<key-storage-uuid>/a8Ff2LkQ0pZr6TnY1wVx3.jpg", "width": 200, "height": 200, "mime_type": "image/jpeg", "size": 9120 }
  },
  "thumbnail_url": "https://files.example.com/<key-storage-uuid>/a8Ff2LkQ0pZr6TnY1wVx3.jpg",
  "tags": ["invoice", "2026"],
  "created_at": "2026-11-17T06:22:10Z"
}
```

What the server does with an upload:

- **The type is sniffed from the content** (finfo), never taken from the filename or the
  client's Content-Type. The result is one of `image | audio | video | document | other`.
  - image: jpg, png, webp, gif, bmp, svg
  - audio: mp3, m4a, wav, ogg, flac, aac (`duration` in seconds, or null)
  - video: mp4, mov, webm, avi, mkv (`duration` in seconds, or null)
  - document: pdf, doc, docx, xls, xlsx, txt, csv
  - other: anything else. `extension` is then the MIME subtype (`zip`, `octet-stream`, ...)
- **Refused with 4151 (415):** executables and scripts (`x-msdownload`, `x-executable`,
  `x-sh`, PHP, `java-archive`), or content whose type can't be detected. Also SVGs whose DTD
  declares anything but plain text entities (`<!ENTITY name "text">`), and SVGs that aren't
  well-formed up to their root element.
- **Images, `verbatim=1`:** stored byte for byte as sent, under the sniffed extension:
  bit depth, ICC profile, DPI and **EXIF, GPS included**, all kept. The download from `url` has
  the same sha256 as the file you sent. The sniffing and the 4151 refusals above still apply.
  Strip location data yourself before uploading if the file will be public.
- **Images, otherwise:** EXIF/IPTC/XMP metadata is stripped, and the image is re-encoded to
  **webp** unless `no_convert=1`. `no_convert` keeps the format, not the bytes: the pixels
  still go through GD, which writes 8 bits per channel and no ICC profile, DPI or EXIF, and
  recompresses JPEGs at quality 90. A rotated JPEG (EXIF orientation) is stored upright.
  SVG and animated GIF are never converted, only stripped. Converting holds the whole image in
  memory, so an image above the instance's conversion limits (25 MB or 40 megapixels by
  default; a JPEG that has to be turned upright counts 1.5x its pixels) is refused with
  **4131 (413)**: send it with `verbatim=1`.
- **Properties and renditions (images only):** the server reads the image's properties and
  makes the key's renditions from the **stored** bytes.
  - Up to 16 MB and 40 megapixels (instance settings), that happens during the upload: the
    response has `processing.status: "ready"` and every rendition.
  - Bigger images come back `pending`, with `renditions: null`, `thumbnail_url: null`, and the
    properties that can be read from the headers (`is_opaque` is null for an image with an
    alpha channel until then). The server makes the renditions in the background, usually
    within a minute or two. **Poll `GET /file/{uuid}`** (every 10-30 s) until the status isn't
    `pending`. Bulk imports by the operator are always `pending`.
  - `failed`: `processing.error` says why. The original is stored and served as usual; the
    renditions that were made are kept. `POST /file/{uuid}/renditions` retries it. `pending`
    with an `error` means a run failed and a retry is coming.
  - `none`: no renditions for this file: not an image, SVG (never rasterized; its
    `properties` still has its size), BMP stored as BMP, a key profile of `[]`, or an
    instance without a processor.
  - `properties` is never null: keys that are absent were never computed (files from before
    1.5.0 only have `width`/`height`), and a null key was computed and isn't known. A DPI the
    file doesn't declare is null, never a guessed 72. `width`/`height` are as stored, before
    EXIF orientation; renditions are upright.
  - Renditions: upright, sRGB, never upscaled, no EXIF/GPS/XMP/ICC (they're public even when
    the original keeps its GPS), first frame of an animation. `contain` keeps the whole image,
    `cover` center-crops. `thumbnail_url` is `renditions.thumb.url`.
  - There's no on-demand resizing: only the sizes in the key's profile exist.
- **Downloads:** `file_type: other` and SVG are always served as `attachment` (stored-XSS
  protection), everything else `inline`. The original filename is kept for downloads.
- `name` is the `name` field, else the client's original filename, extension included
  (`photo.jpg`, even though it's stored as `.webp`). `size` is the **stored** size, after conversion.
- `sha256` is the hash of the **stored** bytes (lowercase hex), the ones `url` serves. It
  matches your local `sha256sum` only when nothing re-encoded the file: verbatim images and
  every non-image file. Use it to verify a download, or to recognize a file you already sent
  (with `verbatim=1`). `null` on files uploaded before 1.5.0 until the instance backfills them.
- `verbatim` in the response is what the upload resolved to (the flag, else the key's
  default). `no_convert` is always true on a verbatim file. Non-image files are stored as sent
  either way.

### Uploading from a URL: `source_url`

Instead of sending the bytes, send a URL and the server downloads the file itself. Useful for
files that already live somewhere public (or behind a presigned S3/R2 URL), and for files
bigger than a proxy lets through as a request body (Cloudflare: 100 MB).

```bash
curl -s -H "Authorization: Bearer $EXPLORER_API_KEY" -H "Content-Type: application/json" \
  -H "Idempotency-Key: $JOB_ID" \
  -d '{"source_url": "https://bucket.example.r2.cloudflarestorage.com/print/0042.tif?X-Amz-Signature=…", "verbatim": true, "tags": ["print", "2026"]}' \
  "$EXPLORER_API_URL/files"
```

- **Off by default.** The instance has to turn it on: `GET /settings`' `source_url_enabled`
  says whether it did. Until then it's **40012** "uploads from a URL aren't enabled on this
  instance".
- **The body:** `source_url` instead of `file` (exactly one of the two, else **4001**), plus the
  same `name`, `folder_uuid`, `verbatim`, `no_convert` and `tags` as an upload. JSON, a form or
  multipart all work. In JSON, `tags` can be a list and the flags real booleans.
- **The response is the same File** as an upload's, and the file goes through everything an
  upload does: sniffing, `verbatim`, conversion, renditions, the key's caps.
- **What URLs are accepted** (anything else is **40012**, and the message says which rule):
  - `https://` only, on port 443 (an instance can allow more ports), with no `user:pass@`;
  - a public host: every address the name resolves to has to be public, so a name that points
    at a private, loopback or link-local address is refused, even next to public ones. IP
    literals likewise. A name that doesn't exist (a typo) gets the same refusal, on purpose:
    the message never tells "doesn't exist" from "private", so nobody can map the server's
    internal names. `localhost`, single-label names (`intranet`) and IPv4 in anything but
    four decimal numbers (`2130706433`, `0x7f.1`) are refused outright;
  - up to **3 redirects**, each held to the same rules (a redirect to `http://` is refused).
- **What the server needs from the other end** (anything else is **5021**, HTTP 502, and the
  message says what happened: `upstream 404`, `DNS lookup failed for …`, `TLS certificate invalid`,
  `timed out after 90 s`, `too slow …`):
  - a `200` (or `203`) after the redirects;
  - a valid TLS certificate for the host;
  - no `Content-Encoding` (the server asks for `identity`, and refuses gzip and the like, so
    the stored bytes are what was served);
  - the whole download within the instance's timeout (**90 s** by default), at least 1 KB/s
    over any 30 s, and a connection within 10 s.
- **Size:** the same `GET /key` `max_upload_size` as an upload (**4002**). A `Content-Length`
  over it, or over what's left of the key's storage cap (**4032**), is refused before anything
  is downloaded; without one, the download is cut at the limit.
- **The file's name:** `name`, else the response's `Content-Disposition` filename, else the
  last path segment of the URL it came from (after redirects, URL-decoded), else `download`.
  The upstream's Content-Type is ignored, as a client's is.
- **What's sent to the other end:** a GET with `User-Agent: MagratheaExplorer/<version>`.
  No cookies, no credentials, nothing from your request. To share a private object, give a
  presigned URL; its signature is in the query string, which is kept as is.
- **5021 is worth retrying** (the other end may come back); 40012 isn't (the URL itself is
  refused). With an `Idempotency-Key` the retry is safe, as below.

### Retrying an upload: `Idempotency-Key`

A timeout or a dropped connection doesn't tell you whether the file was stored. A blind retry
can store it twice, and the copy uses quota until someone finds it. With an `Idempotency-Key`
header, retrying is safe:

```
POST /files
Authorization: Bearer <key>
Idempotency-Key: 0196b1d3-5c2e-7a10-8f3b-6d9e2a4c7b15
```

- **One key per logical upload**, made and **saved before the first try** (with the job, the
  queue message or the row the file belongs to), and sent unchanged on every retry. A UUID is
  fine: any 1 to 255 printable ASCII characters work, case-sensitive. Anything else is
  **40011**.
- **The first request succeeded:** the retry gets that file as it is now (same `uuid`, the
  current `processing`), with the response header `Idempotent-Replayed: true`. Nothing new is
  stored, `uses` doesn't move, and the key's caps aren't checked again. Deleted since: 4043.
- **The first request is still running:** **4091** (409). Back off and retry with the same key:
  you get the file once it's done, or a fresh run if the first one died (after a few minutes).
- **The same key with a different request:** **4221** (422). "The same request" means the same
  bytes, file name (`name`, else the file part's filename), `folder_uuid`, `tags` (in any order)
  and flags as sent: `verbatim=1` and `verbatim=true` match, but leaving `verbatim` out doesn't
  match `verbatim=0`. For `source_url`: the same URL (scheme and host in any case, `:443` or
  not, the fragment ignored) and `name` as sent, instead of the bytes and the file name: the
  retry of a completed URL upload downloads nothing. A key reused between a `file` and a
  `source_url` upload is a 4221 too. A 4221 is a bug on your side: one key reused for two files.
- **The first request failed** (any 4xx or 5xx): nothing is remembered, so the retry runs
  again, and gets the same 4xx if its cause is still there.
- Keys are per Explorer key (another key's are separate) and remembered at least 24 h after the
  first try. Never reuse one, even after that.
- Without the header nothing changes: every request is a new upload.

The retry loop of a cron job:

1. Make the Idempotency-Key and store it with the job before the first attempt.
2. Send it on every attempt.
3. 200 → done, replayed or not. 409, 5xx, a timeout or a connection error → retry later, with
   backoff. 422 → stop: fix the client. Any other 4xx → stop: the request itself is wrong
   (4002 too large, 4031/4032 over a cap, 4151 refused type…).

---

## Workflow 2: Organize with folders

```
GET    /folders?parent_uuid=<uuid>     direct children (omit parent_uuid for root's children)
POST   /folders                        name=<string>&parent_uuid=<uuid?>
GET    /folder/{uuid}                  one folder (works for root too: is_root=true, name="root")
PUT    /folder/{uuid}                  name=<new name>       (rename only, no moving)
DELETE /folder/{uuid}                  only when EMPTY, never root
DELETE /folder/{uuid}?recursive=1      the folder and EVERYTHING under it, never root
GET    /folder/{uuid}/size             { size } = bytes in the whole subtree
```

Folder `data`: `{ uuid, parent_uuid, name, is_root, created_at }`.

- Names are trimmed and **unique among siblings**. A duplicate gives 4004 (400).
- **No lookup by name and no path addressing.** To find "invoices/2026", list the
  children of root, match the name, list that folder's children, and so on. Store folder uuids in
  your own database rather than resolving names on every request.
- **Deleting a tree:** `DELETE /folder/{uuid}?recursive=1`. Without it, a folder with
  subfolders or files gives 4003.
  - The whole subtree disappears from the API at once (lookups, listings, uploads into it,
    public shares of it: 404), and its name is free in its parent right away.
  - Up to 200 files (instance setting): deleted in the request, `{ deleted: true }`.
  - More: `{ deleted: false, pending: true }`, and a background job deletes it, a bounded
    amount per minute (a huge tree takes a while; `total_size` drops as it goes). Until then its
    files still answer `GET /file/{uuid}` and the lookup: don't read that as "not deleted".
  - It deletes files, renditions and shares for good. To offboard a whole tenant, `DELETE /key`
    instead (the root folder can't be deleted: 4005).
- No move or copy for folders or files.

**Idempotent "ensure folder" pattern:**

```
POST /folders name=X parent_uuid=P
  → 200: use data.uuid
  → 4004: GET /folders?parent_uuid=P, pick the one with name === X
```

---

## Workflow 3: List and fetch files

```
GET  /files?folder_uuid=<uuid>&file_type=<type>&tag=<name>
GET  /file/{uuid}
POST /files/lookup        { "uuids": ["<uuid>", ...] }     up to 100, any folders
```

`POST /files/lookup` returns the key's files among `uuids`, **in the order you asked**, same
shape as `GET /files`. Unknown, deleted, malformed and other keys' uuids are simply left out
(no error), so diff the response against your list to find the missing ones. 1 to 100
distinct uuids (as JSON, or form `uuids[]=`): more is a 40013. It's the cheap way to poll
`processing.status` for a batch of uploads (see `waitForRenditions` in the snippets).

**`GET /files` is always limited to ONE folder**: `folder_uuid`, or root when it's left out.
`file_type` and `tag` only narrow *that folder's* files. `GET /files?file_type=audio`
returns the audio files **in root**, not all audio files of the key. There's no key-wide
search, no recursion, no pagination and no guaranteed order. An invalid `file_type` gives
4001. An unknown `tag` gives an empty list.

---

## Workflow 4: Rename, tag, delete a file

```
PUT    /file/{uuid}                  name=<new display name>
POST   /file/{uuid}/tags             name=<tag>
DELETE /file/{uuid}/tags/{tag}
POST   /file/{uuid}/renditions       no body: make the renditions again
POST   /file/{uuid}/rotate           no body: new URLs, the old ones deleted (Workflow 5)
DELETE /file/{uuid}
```

- Rename changes the display name only, never the content, folder or URL path. An empty
  `name` is **silently ignored** (200, nothing changes).
- Tags: attaching creates the tag if needed, and attaching twice is a no-op. Both tag routes
  return the updated **File**. Tags are shared instance-wide and effectively case-insensitive.
  The router **lowercases the URL path**, and a name with `/ ? # &` can't go in the
  DELETE path. **Use lowercase slug tags (`[a-z0-9_-]`).**
- Detaching a tag that exists but isn't on the file is a no-op (200). A tag name nobody has
  ever used gives 4044.
- Delete removes the object, its renditions, its tags and **its shares**, and lowers the key's
  `total_size`. After that, the file's URLs stop working. A 4043 on delete means the file is
  already gone, so treat it as success in cleanup code.
- `POST /file/{uuid}/renditions` makes an image's renditions again from the key's **current**
  profile, in the background: it returns the File with `processing.status: "pending"`, then
  poll. Calling it twice leaves one run. The new renditions have **new URLs**, and once they're
  made the old rendition URLs stop working: store the new ones. If the run fails, the old
  renditions stay. Use it after the operator changes the key's profile, for files from before
  1.5.0 (their `thumb` has null `width`/`height`/`size`), or to retry a `failed` file. A
  non-image comes back unchanged (`none`). 5004 means the instance has no processor.

---

## Workflow 5: Serve files in your app

- **Use `url` / `thumbnail_url` exactly as returned.** Don't build, parse or rewrite them.
  The storage base depends on the instance's driver, and the local driver adds
  `?disposition=…&filename=…`.
- **Store `uuid` in your own database.** You can also cache `url`: the path never changes while
  the file exists, unless you rotate it (below). On the local driver the query string follows the display name, so a rename
  changes it, and the old URL still works. Rendition URLs (`renditions.*.url`,
  `thumbnail_url`) are stable too, **until the renditions are regenerated**, which gives new
  ones: after a regenerate, store the new URLs (or don't cache them and read the File).
- Wait for `processing.status` to leave `pending` before reading `renditions`: they're null
  until then.
- **Rotating a file's URLs** (a URL leaked, or a customer wants old links dead):
  `POST /file/{uuid}/rotate`, no body. It copies the original and every rendition to new random
  paths, deletes the old objects and returns the File with the new `url`, `renditions` and
  `thumbnail_url`. **Replace every URL you stored with the ones in the response**: the old ones
  404 at the origin from then on. Same uuid, bytes, name, tags; shares keep working. A 4093
  (409) means a processing run finished in the middle: nothing changed, call it again. Calling
  it twice just rotates twice.
- **Rotation is not revocation behind a CDN.** Objects are cached as `immutable` for a year.
  An old URL that was ever fetched through the CDN (an R2 custom domain sits behind
  Cloudflare) can keep being served from the edge **for up to a year** unless the operator
  purges it in the CDN. Nothing in the API purges. Don't promise a user that a rotated link is
  dead.
- They're plain public URLs. Put them straight into `<img src>`, `<audio>`, `<video>` or download
  links. No auth header needed.
- Need other image sizes? Ask the operator to add them to the key's rendition profile (up to
  6 sizes, 2048 px at most), then regenerate the existing files. There's no on-demand
  resizing: for arbitrary sizes, host images in MagratheaImages instead.

---

## Workflow 6: Share links

Owner side (authenticated):

```
POST   /shares                         file_uuid=<uuid>   OR   folder_uuid=<uuid>   (exactly one, else 4001)
GET    /shares?file_uuid=&folder_uuid=  this key's links, newest first
DELETE /share/{uuid}
```

Share `data`: `{ uuid, url, target_type, target_name, file_uuid, folder_uuid, views, last_viewed_at, created_at }`.
`url` is `{app_url}/app/s/{share-uuid}`, the first-party public page, ready to hand out.
Links never expire. Sharing the same target twice gives two independent links. The root folder
can be shared too, using any root file's `folder_uuid` or a top-level folder's `parent_uuid`.
It shows as `"All files"`.

Public side (**no** `Authorization` header). Use these to render a share inside your own app:

```
GET /shared/{share_uuid}                            → { type: "file", name, file: SharedFile }
                                                    | { type: "folder", name, path, folders, files }
GET /shared/{share_uuid}/folder/{folder_uuid}       → browse inside a folder share
```

- `path` is a breadcrumb that starts **at the share root**. Folders and files are sorted by
  name. `SharedFile` is a File without `folder_uuid`, `no_convert`, `verbatim`, `sha256`, `tags`,
  `properties`, `processing` or `renditions`: a share shows the thumbnail only (`thumbnail_url`).
- Every failure is the same 404: an unknown, deleted or owner-disabled link gives 4045, and
  a folder outside the subtree gives 4042. Show one generic "link not valid" message.
- Each successful public GET counts one `view`, including folder browsing.
- **Deleting a share doesn't revoke file URLs that were already copied.** Only deleting
  the file does that. Don't tell users otherwise.
- A share uuid is a bearer credential. Don't log it or show it to people who shouldn't have it.

---

## Workflow 7: Key usage and scheduled deletion

```
GET  /key/usage            → { uses, usage_limit, total_size, usage_limit_mb }
DELETE /key                → { scheduled: true, execute_at }     (14 days out; 4006 if already pending)
POST /key/cancel-deletion  → { cancelled: true }                 (4007 / HTTP 400 if nothing pending)
POST /key/rotate-secret    → { secret }                          (the new secret; the old one fails right away)
```

- `uses` counts **lifetime uploads** and never goes down. `usage_limit` caps lifetime uploads,
  not files currently stored, so deleting files doesn't free count. `total_size` is the live
  number of bytes stored and does go down on delete. `usage_limit_mb` caps it, in MiB.
  `null` means unlimited for both.
- The quota check runs before processing, against the **raw** upload size.
- Renditions don't count: `total_size` and `usage_limit_mb` only ever see originals.
- While a deletion is pending: uploads fail with **4014 (HTTP 401)**, and every public share
  link of the key gives 4045. Everything else keeps working, so the key can still list and
  clean up. After `execute_at` a cron job deletes the key and everything it owns.
- **Never call `DELETE /key` from integration code** unless the user explicitly asked to
  decommission the key.
- `POST /key/rotate-secret` returns the new secret once, and the secret the request used stops
  working on the next request: no grace period. Save the new one before dropping the old. If
  the response is lost, the key is locked out until the operator rotates it in the admin.

---

## Workflow 8: Provision keys (one key per tenant)

For a backend that gives each of its tenants (workspaces, accounts) a key of its own, without
a manual step. The operator turns it on in admin → Provisioning: they generate the
**provisioning secret** (`xpv_` + 64 hex characters, shown once) and set what every
provisioned key gets (storage and upload limits, verbatim default, rendition profile), plus
how many provisioned keys may exist.

```
POST /provision/keys   Authorization: Bearer xpv_…   Idempotency-Key: <uuid>   { "name": "workspace-42" }
→ { secret, name, uses, usage_limit, total_size, usage_limit_mb, verbatim_default,
    max_upload_size, rendition_profile, expiration, active, created_at }
```

- `name` is the only field: anything else is refused with **40013** naming it. The settings
  are the operator's, copied onto the key when it's created; changing a key's limits later is
  done by the operator, in the admin.
- `secret` is the new key's bearer secret, **shown only in this response**. Store it with the
  tenant right away (encrypted, server-side). Lost = the operator rotates it.
- Creating keys is **all** the provisioning secret does. It can't list, read, change or delete
  keys, not even the ones it made. Each key is managed with its own secret, which you have
  anyway to upload: `GET /key` and `/key/usage` for usage, `POST /key/rotate-secret` to rotate,
  `DELETE /key` to offboard the tenant (14 days of grace, `POST /key/cancel-deletion` undoes it).
- Names are unique across the instance: **4094** (HTTP 409) when one is taken. Use something
  that can't collide, like your tenant's id.
- **4033** (HTTP 403): the instance's limit of provisioned keys is reached. Keys pending
  deletion still count until the cron deletes them. Ask the operator.
- A wrong or missing secret is the generic 401 (`Authorization Failed! (IsProvisioning =
  false)`). After 10 failures in 15 minutes from one client IP, every request from it gets
  **4291** (HTTP 429) with `Retry-After` (seconds) until the window ends, the right secret
  included. A 401 here means a config problem: don't retry it in a loop.
- **Retries: send an `Idempotency-Key`**, as for uploads (Workflow 1): one per tenant, saved
  before the first try. If the response is lost, the retry with the same key gets the same key
  back, with `Idempotent-Replayed: true` and a **new `secret`**. Only the first secret's hash was
  stored, so it can't be sent again: the key's secret is rotated, and the first one (which never
  reached you) stops working. So **retry only after a failure**: a duplicate of a request whose
  response did arrive kills the secret you stored. While the first is still running: 4091. The
  same Idempotency-Key with another `name`: 4221. The key offboarded since: 4041.
- Without the header, a lost response leaves a key whose secret you don't have, and the retry
  gets 4094. Treat 4094 after a timeout as "created, secret lost": ask the operator to delete
  or rotate that key.
- The provisioning secret is a backend secret: never in a browser, never in client code, never
  in a log. To rotate it, the operator generates a new one; the old one stops working at once
  (keys it made keep working).

---

## Workflow 9: Browser uploads (direct, without the key)

For a big file a user picks in the browser, without it passing through your backend. The key
still never reaches the browser: your backend mints a **single-use upload token**, the browser
posts the file with it, and your backend confirms.

```
1. backend:  POST /upload-tokens      Authorization: Bearer <key>
             { "folder_uuid": "...", "max_size": 157286400, "file_types": ["image"], "expires_in": 900,
               "verbatim": true, "tags": "print" }                      every field optional
             → { id, token, upload_url, expires_at }
2. browser:  POST {upload_url}        multipart, only a `file` part, no Authorization header
             → the File (owner view), so the page can show `thumbnail_url` right away
3. backend:  GET /upload-tokens/{id}  Authorization: Bearer <key>
             → { id, status: pending|uploading|used|failed|expired, expires_at, file, error }
```

- Defaults: the key's root folder, `max_size` = `GET /key`'s `max_upload_size` (also the
  ceiling), any `file_types` (`image audio video document other`, by what the content sniffs
  as), `expires_in` 900 s (60 to 900), `verbatim`/`no_convert` = the key's defaults, no tags.
  Any other field is 40013.
- **Give the browser `upload_url` only.** Store the token's `id` with the user's pending
  upload. `token` is a secret like the key, scoped to one upload: don't log it.
- **Confirm in step 3, by `id`.** The File the browser reports back could be anything; `status:
  used` with `file.uuid` is what you trust. Then store `file.uuid` as for any upload.
- **Single use.** A second upload with the same token gets **4092** (409).
  - A refusal (any 4xx: too large, a type the token doesn't allow, the key's quota) uses the
    token up: `status: failed`, `error: {code, message}`. Mint a new token if the user picks
    another file.
  - A server error (5xx) leaves it `pending`: the browser can retry with the same `upload_url`
    while it's valid.
  - A request with no `file` part is a 4001 and doesn't use it up.
- **Expiry is checked when the upload arrives**, i.e. when the whole body is in: a 150 MB
  upload that starts at minute 14 of 15 is refused at minute 16 with **4101** (410). Size
  `expires_in` for the slowest upload you expect; there's no grace period.
- **CORS**: a `fetch`/`XMLHttpRequest` POST with a `FormData` body and no custom headers is a
  simple request: no preflight, and the API answers `Access-Control-Allow-Origin: *`. Don't add
  headers (no `Idempotency-Key` here: the token already makes it single use).
- **Cloudflare**: when the API host is proxied by Cloudflare, request bodies stop at **100 MB**
  (Free and Pro plans): a bigger file gets a 413 from the edge, before the server sees it, and
  the token stays unused. For bigger browser uploads the operator has to make the API host
  DNS-only. Ask before relying on it.
- Tokens are deleted a week after they expire: confirm before that.

```ts
// browser side: upload_url came from your backend
async function directUpload(uploadUrl: string, file: File): Promise<ExplorerFile> {
  const form = new FormData();
  form.append('file', file, file.name);
  const res = await fetch(uploadUrl, { method: 'POST', body: form });   // no headers
  const env = await res.json().catch(() => null);
  if (!env || env.success !== true) throw new Error(env?.data?.message ?? `HTTP ${res.status}`);
  return env.data;   // display only: the backend confirms with GET /upload-tokens/{id}
}
```

---

## Error codes

| Code | HTTP | Meaning | What to do |
|---|---|---|---|
| 401 | 401 | Auth failed (missing/bad/inactive/expired key) | Check the key and the `Bearer ` prefix. Ask the operator |
| 404 | 404 | Not a valid endpoint | Missing `/api/v1` prefix, wrong method, or typo |
| 4001 | 400 | Missing required field (`: name`, `: file or source_url (exactly one)`, `: file_type`, …) | Check the field name and the body's `Content-Type` |
| 4002 | 400 | File too large | Above `GET /key`'s `max_upload_size` (the message says the limit) |
| 4003 | 400 | Folder not empty | Empty it first |
| 4004 | 400 | Folder name already exists here | Reuse the existing one (ensure-folder pattern) |
| 4005 | 400 | Cannot delete root folder | |
| 4006 | 400 | Deletion already pending | |
| 4007 | 400 | No pending deletion to cancel | |
| 4008 | 400 | Invalid (empty) tag name | |
| 4009 | 400 | Invalid boolean value (`: verbatim`, `: no_convert`) | Send `1`/`0`, `true`/`false`, `yes`/`no` or `on`/`off` |
| 40010 | 400 | Invalid JSON body (the message says why) | Send a JSON object, or a form |
| 40011 | 400 | Invalid Idempotency-Key (empty, over 255 characters, or not printable ASCII) | Send 1 to 255 printable ASCII characters, e.g. a UUID |
| 40012 | 400 | Source URL refused (`: only https URLs are accepted`, `: the host doesn't resolve to a public address` (a private one, or none: a typo too), `: more than 3 redirects`, `: uploads from a URL aren't enabled on this instance`, …) | Don't retry: the URL breaks a rule (Workflow 1, "Uploading from a URL") |
| 40013 | 400 | Invalid field value (`: name (…)`, `: tags (…)`, `: source_url (…)`, `: uuids (at most 100)`, or a field `POST /provision/keys` doesn't take) | Read the detail: it names the field |
| 4014 | 401 | Key has a pending scheduled deletion (on upload) | Cancel the deletion or use another key |
| 4031 | 403 | Upload count limit reached | Lifetime cap, so deleting won't help. Ask the operator |
| 4032 | 403 | Storage size limit reached | Delete files or ask for a bigger `usage_limit_mb` |
| 4033 | 403 | Provisioning key limit reached (`POST /provision/keys`) | Offboard unused tenants, or ask the operator |
| 4042 | 404 | Folder not found (or another key's) | |
| 4043 | 404 | File not found (or another key's) | Treat as "already deleted" in cleanup code |
| 4044 | 404 | Tag not found | |
| 4045 | 404 | Share not found / link not valid | |
| 4046 | 404 | Upload token not found (unknown, purged, or another key's) | Mint a new one |
| 4091 | 409 | A request with this Idempotency-Key is still in progress | Back off and retry with the same key |
| 4092 | 409 | Upload token already used (or refused before, or another upload with it is running) | Read `GET /upload-tokens/{id}`; mint a new token |
| 4093 | 409 | File changed during rotation (`POST /file/{uuid}/rotate`) | Nothing changed: call it again |
| 4094 | 409 | Key name already exists (`POST /provision/keys`) | Pick a name that can't collide (your tenant id) |
| 4101 | 410 | Upload token expired before the upload arrived | Mint a new one with a longer `expires_in` |
| 413 | 413 | Payload Too Large: request body over 1 MB (any body but an upload) | Nothing here takes that much: check what you send |
| 4131 | 413 | Image too large to convert | Send it with `verbatim=1` |
| 4151 | 415 | Unsupported or dangerous file type | Executables and scripts are never accepted |
| 4221 | 422 | Idempotency-Key reused with a different request | A bug: one Idempotency-Key per operation |
| 4291 | 429 | Too many failed authentications (`POST /provision/keys`) | Wait `Retry-After` seconds, and fix the secret |
| 5001 | 500 | Storage backend error | Retry later, then report to the operator |
| 5002 | 500 | Image processing failure | Try `verbatim=1`, or the file is corrupt |
| 5003 | 500 | Token generation exhausted | Retry |
| 5004 | 500 | Incorrect configuration (also: `POST /file/{uuid}/renditions` on an instance without a processor) | Report to the operator |
| 5021 | 502 | Source URL fetch failed (`: upstream 404`, `: timed out after 90 s`, `: TLS certificate invalid`, …) | The other end failed: retry later with the same Idempotency-Key, or fix what the message says |

Something owned by another key always looks like "not found", never 403. `GET /error-codes`
returns the live map.

---

## Client snippets

**PHP (ext-curl, no dependencies)**

```php
final class ExplorerClient {
	public function __construct(private string $baseUrl, private string $key) {}

	/** @return mixed the envelope's `data` */
	public function request(string $method, string $path, ?array $fields = null, bool $multipart = false, ?string $idempotencyKey = null) {
		$ch = curl_init(rtrim($this->baseUrl, "/")."/".ltrim($path, "/"));
		$headers = ["Authorization: Bearer ".$this->key];
		if($idempotencyKey !== null) $headers[] = "Idempotency-Key: ".$idempotencyKey;
		curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true]);
		if($fields !== null) {
			// multipart: pass the array as-is (CURLFile inside). Otherwise form-urlencode it.
			curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $fields : http_build_query($fields));
			if(!$multipart) $headers[] = "Content-Type: application/x-www-form-urlencoded";
		}
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		$body = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$json = is_string($body) ? json_decode($body, true) : null;
		if(!is_array($json) || ($json["success"] ?? false) !== true) {
			$code = $json["data"]["code"] ?? $status;
			throw new \RuntimeException($json["data"]["message"] ?? "Explorer request failed (HTTP $status)", (int)$code);
		}
		return $json["data"];
	}

	/** $idempotencyKey: saved with the job before the first try, the same on every retry (Workflow 1) */
	public function upload(string $localPath, string $originalName, ?string $folderUuid = null, array $tags = [], ?string $idempotencyKey = null): array {
		$fields = ["file" => new \CURLFile($localPath, null, $originalName)];
		if($folderUuid) $fields["folder_uuid"] = $folderUuid;
		if($tags) $fields["tags"] = implode(",", $tags);
		return $this->request("POST", "files", $fields, true, $idempotencyKey);
	}

	/** The server downloads $sourceUrl (Workflow 1, "Uploading from a URL"). */
	public function uploadFromUrl(string $sourceUrl, ?string $name = null, ?string $folderUuid = null, array $tags = [], ?string $idempotencyKey = null): array {
		$fields = ["source_url" => $sourceUrl];
		if($name !== null) $fields["name"] = $name;
		if($folderUuid) $fields["folder_uuid"] = $folderUuid;
		if($tags) $fields["tags"] = implode(",", $tags);
		return $this->request("POST", "files", $fields, false, $idempotencyKey);
	}

	public function delete(string $fileUuid): void {
		try { $this->request("DELETE", "file/".$fileUuid); }
		catch(\RuntimeException $e) { if($e->getCode() !== 4043) throw $e; } // already gone
	}
}
```

**Node 18+ / TypeScript (global fetch, FormData, Blob)**

```ts
const BASE = process.env.EXPLORER_API_URL!; // .../api/v1
const KEY = process.env.EXPLORER_API_KEY!;

export class ExplorerError extends Error {
  constructor(public status: number, public code: number | null, message: string) { super(message); }
}

async function call<T>(method: string, path: string, body?: FormData | URLSearchParams | Record<string, string>, idempotencyKey?: string): Promise<T> {
  const headers: Record<string, string> = { Authorization: `Bearer ${KEY}` };
  if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey;
  let payload: BodyInit | undefined;
  if (body instanceof FormData) payload = body;                       // multipart: let fetch set the boundary
  else if (body) {
    payload = body instanceof URLSearchParams ? body : new URLSearchParams(body);
    headers['Content-Type'] = 'application/x-www-form-urlencoded';
  }
  const res = await fetch(`${BASE}/${path.replace(/^\//, '')}`, { method, headers, body: payload });
  const env = await res.json().catch(() => null);
  if (!env || env.success !== true) {
    throw new ExplorerError(res.status, env?.data?.code ?? null, env?.data?.message ?? `HTTP ${res.status}`);
  }
  return env.data as T;
}

// e.g. new Blob([await fs.readFile(path)]) or a File from an incoming multipart request.
// idempotencyKey: saved with the job before the first try, the same on every retry (Workflow 1).
export function uploadFile(file: Blob, filename: string, folderUuid?: string, tags: string[] = [], idempotencyKey?: string) {
  const form = new FormData();
  form.append('file', file, filename);
  if (folderUuid) form.append('folder_uuid', folderUuid);
  if (tags.length) form.append('tags', tags.join(','));
  return call<ExplorerFile>('POST', 'files', form, idempotencyKey);
}

// The server downloads the URL (Workflow 1, "Uploading from a URL").
export function uploadFromUrl(sourceUrl: string, opts: { name?: string; folderUuid?: string; tags?: string[] } = {}, idempotencyKey?: string) {
  const body: Record<string, string> = { source_url: sourceUrl };
  if (opts.name) body.name = opts.name;
  if (opts.folderUuid) body.folder_uuid = opts.folderUuid;
  if (opts.tags?.length) body.tags = opts.tags.join(',');
  return call<ExplorerFile>('POST', 'files', body, idempotencyKey);
}

export async function ensureFolder(name: string, parentUuid?: string): Promise<string> {
  try {
    const f = await call<{ uuid: string }>('POST', 'folders', parentUuid ? { name, parent_uuid: parentUuid } : { name });
    return f.uuid;
  } catch (e) {
    if (!(e instanceof ExplorerError) || e.code !== 4004) throw e;
    const kids = await call<{ uuid: string; name: string }[]>('GET', `folders${parentUuid ? `?parent_uuid=${parentUuid}` : ''}`);
    return kids.find((k) => k.name === name.trim())!.uuid;
  }
}

export interface ExplorerFile {
  uuid: string; folder_uuid: string; name: string; extension: string; mime_type: string;
  file_type: 'image' | 'audio' | 'video' | 'document' | 'other';
  size: number; sha256: string | null; width: number | null; height: number | null; duration: number | null;
  no_convert: boolean; verbatim: boolean;
  properties: ExplorerProperties;
  processing: { status: 'none' | 'pending' | 'ready' | 'failed'; error: string | null };
  url: string;
  renditions: Record<string, ExplorerRendition> | null;   // null while pending
  thumbnail_url: string | null; tags: string[];
  created_at: string;   // ISO 8601, UTC: "2026-11-17T06:22:10Z"
}

// absent = never computed, null = computed and not known
export interface ExplorerProperties {
  width?: number; height?: number; duration?: number;
  orientation?: number | null; has_alpha_channel?: boolean | null; is_opaque?: boolean | null;
  dpi_x?: number | null; dpi_y?: number | null; bit_depth?: number | null; color_space?: string | null;
  has_icc_profile?: boolean | null; animated?: boolean | null; frame_count?: number | null;
}

export interface ExplorerRendition {
  url: string; width: number | null; height: number | null; mime_type: string; size: number | null;
}

// Poll until processing is done: renditions are null while pending.
export async function waitForRenditions(uuid: string, timeoutMs = 300_000): Promise<ExplorerFile> {
  for (const start = Date.now(); ; ) {
    const file = await call<ExplorerFile>('GET', `file/${uuid}`);
    if (file.processing.status !== 'pending') return file;
    if (Date.now() - start > timeoutMs) throw new Error(`renditions of ${uuid} still pending`);
    await new Promise((r) => setTimeout(r, 15_000));
  }
}

// The same for many files: one POST /files/lookup per 100 uuids per round. A uuid missing
// from the response was deleted (or never existed): it's dropped from the result.
export async function waitForManyRenditions(uuids: string[], timeoutMs = 300_000): Promise<ExplorerFile[]> {
  const done = new Map<string, ExplorerFile>();
  let waiting = [...new Set(uuids)];
  for (const start = Date.now(); waiting.length > 0; ) {
    const still: string[] = [];
    for (let i = 0; i < waiting.length; i += 100) {
      const batch = waiting.slice(i, i + 100);
      const form = new URLSearchParams();
      batch.forEach((u) => form.append('uuids[]', u));
      const files = await call<ExplorerFile[]>('POST', 'files/lookup', form);
      for (const f of files) {
        if (f.processing.status === 'pending') still.push(f.uuid);
        else done.set(f.uuid, f);
      }
    }
    waiting = still;
    if (waiting.length === 0) break;
    if (Date.now() - start > timeoutMs) throw new Error(`${waiting.length} file(s) still pending`);
    await new Promise((r) => setTimeout(r, 15_000));
  }
  return uuids.flatMap((u) => (done.has(u) ? [done.get(u)!] : []));
}
```

---

## Integration checklist

- [ ] Key and base URL come from server-side config or env. The key never reaches a browser bundle.
- [ ] Browser uploads go **through your backend** (it receives the file and re-posts it as
      multipart to `/files`), or use an upload token (Workflow 9) and are confirmed with
      `GET /upload-tokens/{id}`, never by what the browser reports.
- [ ] The base URL includes `/api/v1`.
- [ ] Responses are unwrapped from `{ success, data }`, and `success` is checked.
- [ ] POST/PUT bodies are form-urlencoded, or a JSON object sent as `application/json`.
- [ ] File size is checked against `GET /key`'s `max_upload_size` before upload (not `/settings`,
      which is only the instance default).
- [ ] Your database stores the file `uuid` (plus `url`/`thumbnail_url` if you want).
      Nothing is derived from URLs.
- [ ] Code that needs renditions waits for `processing.status` to leave `pending`, and handles
      `failed` and `none`.
- [ ] Folder uuids are stored, not resolved by name on every request.
- [ ] Deleting your entity calls `DELETE /file/{uuid}`, with 4043 treated as success.
- [ ] Error handling branches on `data.code`, not on the message text.
- [ ] Timestamps are parsed as ISO 8601 UTC (`2026-11-17T06:22:10Z`), not as local time.
- [ ] Provisioning: the provisioning secret is backend-only; each key's `secret` is stored the
      moment `POST /provision/keys` returns; tenants are offboarded with `DELETE /key` and their
      own key.
- [ ] Uploads and key creations that get retried send an `Idempotency-Key`, saved before the
      first try and sent on every retry; 409 is retried later, 422 is treated as a bug.
- [ ] `source_url` uploads: the instance has them on (`/settings`' `source_url_enabled`); the URL is https on a public host (or a
      presigned one); 5021 is retried later, 40012 is not; downloads fit in the instance's
      timeout (90 s by default).

---

## What the API does NOT do

Managing keys by id (listing them, changing their limits: that's the operator's) · reading a
key's secret after it was shown · read-only or scoped keys · private, signed or expiring file URLs ·
on-demand resizing · upload from a URL that isn't https on a public host (plain `http`,
private networks) · several files per request · moving or
copying files or folders · lookup by name or path · key-wide
search · recursive listing · pagination · share expiry · webhooks. If an integration needs
one of these, it has to work around it on the client side, or the feature has to be added
to MagratheaExplorer.

---

## Differences from MagratheaImages3

| | Images3 | Explorer |
|---|---|---|
| Auth | private/public key pair | single bearer key |
| File types | images only | image / audio / video / document / other |
| URL | `/image/{public_key}/{id}/...` (key + sequential id) | `{storage-base}/{key-storage-uuid}/{token}.{ext}` (random per-file token) |
| Resizing | on-demand `WxH` in the URL, disk-cached | fixed renditions per key (up to 6 sizes), made at upload or in the background |
| Storage | local disk (R2 is backup-only) | local, S3 or R2 as the primary store (one per instance) |
| Key creation | `POST /key/create` + shared secret | admin panel, or `POST /provision/keys` + the provisioning secret (settings are the operator's) |
| Response | varies per route | always `{ success, data }` |
