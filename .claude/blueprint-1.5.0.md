# MagratheaExplorer: Blueprint for Turbopod

Changes that Turbopod needs from MagratheaExplorer. This document was written from the API guide (the `magrathea-explorer-api` skill), not from Explorer's code. Every "Today" line describes the guide's view of current behavior, so check each one against the code. Where the two disagree, the code wins, and the change is sized against the code.

How to use this document:
- Each item has a **Today** line (current behavior as the guide describes it) and **Required** bullets (the behavior Turbopod relies on). Some also have a **Suggested shape** (routes and field names) and a **Why** line.
- Names, routes and error codes are suggestions. The **Required** behavior isn't: if you change it, agree the change first (see §8).
- Explorer is a general-purpose file host. Every feature here is defined for **all** file types (images, audio, video, documents, ISOs, archives, anything). Image-specific behavior lives in per-type handlers. A type with no handler gets a defined "nothing to do" result, never an error.

---

## 1. The consumer: Turbopod

Turbopod is a multi-tenant PHP SaaS for print-on-demand sellers. It stores two kinds of files in Explorer:

- **Print files:** large lossless PNGs with alpha. Usually 10–60 MB and up to about 100 MP for rugs and posters. They must arrive at the print platforms exactly as the seller uploaded them.
- **Mockups:** display images of products.

How Turbopod uses Explorer:

- **Platforms download files from plain public HTTPS URLs**, sometimes days later, while they retry curation (Reserva's `art_url`, Printify's image `src`). Printful caches files by URL, so a changed file needs a new URL. Explorer's permanent, unguessable public URLs already fit all of this. Keep them as they are.
- **Turbopod calls Explorer from its backend only**, mostly from cron jobs that retry after timeouts and lost connections.
- **Turbopod stores Explorer UUIDs and URLs in its own database.** It doesn't use Explorer's tags, listing or search.
- **One Explorer key per workspace (tenant)**, once keys can be created through the API (§4.1).
- **Its sellers' interface needs previews of designs**: a small grid thumbnail and a large preview, both keeping transparency. A white design for dark shirts must not turn into a white square on white.

---

## 2. Rules for every change

- **Backward compatible by default.** Existing keys and consumers see no change unless they opt in, per key or per request. The only exceptions are listed explicitly: the boolean fix in §3.1 and the timestamps in §4.4.
- **It must run on DreamHost shared hosting:**
  - no root and no daemons, so background work runs from cron only;
  - a process watcher kills processes that use too much memory or CPU;
  - PHP limits are set through `.user.ini` / `phprc`.

  R2 is the recommended primary storage, so DreamHost only handles uploads and processing, not serving.
- **Stream everything.** Hashing, storing, fetching and downloading never load a whole file into PHP memory. Videos and ISOs can be gigabytes.
- **Uploaded content is hostile.** Every handler that parses a file runs with the security settings in §3.4.7.
- **Every change updates `docs/openapi.yaml`, the skill guide, `/error-codes` and the changelog.**

---

## 3. Tier 1: required before Turbopod's upload phase (P3)

### 3.1 Verbatim storage

**Today:** images are re-encoded to webp unless `no_convert=1`. EXIF/IPTC/XMP metadata is "always stripped", so even with `no_convert=1` the stored bytes may differ from the upload. Also, any non-empty value except `0` counts as true, so `no_convert=false` means true.

**Required:**
- A per-upload flag, suggested name `verbatim=1`. The stored original is **byte-for-byte the uploaded file**, for every file type: no re-encoding, no stripping, no normalization. It implies `no_convert`.
- A per-key setting `verbatim_default` (bool, default `false`). When it's true, uploads are verbatim unless the request sends `verbatim=0`. Turbopod's keys set it to true, so a forgotten flag can't damage a print file.
- **Proper boolean parsing for every flag** (`no_convert`, `verbatim` and the new ones):
  - `1`, `true`, `yes`, `on` mean true;
  - `0`, `false`, `no`, `off` and empty mean false;
  - anything else is a 400.

  This changes behavior for callers that send `no_convert=false`, who were getting the opposite of what they asked for.
- Verbatim still runs type sniffing and the dangerous-type refusal (4151). It skips transformations only.
- Renditions (§3.4) are still generated from a verbatim original. They're separate objects and never alter it.
- The docs must say that verbatim keeps EXIF data, GPS included. A caller who asks for verbatim gets it.

**Acceptance:** upload a 16-bit RGBA PNG with an ICC profile and a pHYs chunk with `verbatim=1`, download it from its `url`, and its sha256 equals the local file's.

### 3.2 sha256 on every file

**Required:**
- Every File response includes `sha256`: lowercase hex, computed while streaming the **stored original**. On a verbatim upload it equals the client's hash.
- For existing files, `sha256` is `null` until it's computed. A backfill in cron is nice to have.

**Why:** Turbopod checks that what's stored is exactly what it sent, and uses the hash for versioning and de-duplication.

### 3.3 Upload size limit per key

**Today:** `max_upload_size` is instance-wide (the guide's example shows 5 MB), and PHP's own limits may be lower.

**Required:**
- A per-key setting `max_upload_size` (bytes; `null` means the instance default). `GET /key` returns the **effective** limit, and `/settings` keeps the instance default.
- It's enforced on every upload source: multipart uploads (§3.1), URL fetches (§4.3, enforced while streaming) and direct uploads (§5.1).
- The server's PHP limits (`upload_max_filesize`, `post_max_size`, `max_execution_time`, `max_input_time`) must allow the largest per-key limit in use. The docs say how to set them on DreamHost. `memory_limit` must not need to grow, because uploads stream.

**Why:** Turbopod needs 100–150 MB per file, and other consumers can stay at 5 MB.

### 3.4 Processing pipeline: properties and renditions

**Today:** one thumbnail per image, 200 px square, cropped to fill, built synchronously at upload. The guide's example shows a `.jpg`, so it has no alpha. SVG has no thumbnail. There is no other size and no resizing. The File carries `width`, `height` and `duration`.

**Goal:** one generic pipeline that, for every stored file, **extracts properties** and **generates renditions** according to the file type. The work runs through an isolated processor that can be replaced.

#### 3.4.1 The processor: an isolated, replaceable executable

- **It's a separate process.** Explorer calls it through `proc_open` with an argv array, never a shell string. It sends a JSON job on stdin and reads a JSON result on stdout:
  - exit `0` means it processed the file, possibly with some rendition errors;
  - any other exit code means it failed.
- **The engine is chosen in the instance config** (the command and its arguments). Replacing the engine means changing config, not Explorer's code. The first implementation is a PHP CLI script using Imagick with resource limits. Later engines can drop in without Explorer noticing: pyvips through `pip install --user "pyvips[binary]"`, a native binary on a VPS, a remote service.
- **It's a pure function: one file in, files and properties out.**
  - It reads only `input` and writes only inside `out_dir`.
  - It has no network access, no database, no storage access and no Explorer code.
  - Explorer stores its outputs, records them and deletes the temporary files.
- **Explorer enforces limits from outside.** It kills the process after a wall-clock timeout and passes the memory limits in the job. A timeout, crash or kill becomes `failed`, never a stuck job. Being a separate process matters on DreamHost: the process watcher kills the heavy child, not Explorer's cron run.

Job (stdin), suggested shape:

```json
{
  "input": "/home/explorer/tmp/job-123/original",
  "file_type": "image",
  "mime_type": "image/png",
  "out_dir": "/home/explorer/tmp/job-123/out",
  "properties": true,
  "renditions": [
    {"name": "thumb",   "width": 300,  "height": 300,  "fit": "contain", "format": "webp", "quality": 85},
    {"name": "preview", "width": 1200, "height": 1200, "fit": "contain", "format": "webp", "quality": 85}
  ],
  "limits": {"memory_mb": 128, "max_pixels": 200000000}
}
```

Result (stdout), suggested shape:

```json
{
  "properties": {"width": 4500, "height": 5400, "has_alpha_channel": true, "is_opaque": false, "dpi_x": 300, "dpi_y": 300},
  "renditions": [
    {"name": "thumb", "path": "/home/explorer/tmp/job-123/out/thumb.webp", "width": 250, "height": 300, "mime_type": "image/webp", "size": 18342}
  ],
  "errors": {"preview": "time limit exceeded"}
}
```

#### 3.4.2 Handlers by file type

| `file_type` | Properties | Renditions | Needed by Turbopod |
|---|---|---|---|
| image (jpg, png, webp, gif, bmp) | §3.4.3 | per the key's profile | **yes, now** |
| image (svg) | declared width/height, if any | none, unless the handler sanitizes SVG (§3.4.7) | no |
| video | width, height, duration (+ codec, frame rate, optional) | a poster frame through the profile (ffmpeg) | no (optional) |
| audio | duration | none | no |
| document | `page_count` for PDF | first page of a PDF through the profile (optional) | no (optional) |
| other (zip, iso, …) | none | none | no |

- When no handler is available for a type, either because it was never built or because its tool (ffmpeg, Ghostscript) isn't installed, processing status is `none`. That is not an error.
- The rendition profile (§3.4.4) applies to whatever visual output a handler produces: the image itself, a video's poster frame, a PDF's first page.

#### 3.4.3 Properties

- The File gets a `properties` object with type-specific fields. Every field is nullable, where `null` means unknown or not computed yet. The top-level `width`, `height` and `duration` stay for compatibility.
- **Image properties, with exact meanings:**
  - `width`, `height`: pixels as stored, before EXIF orientation. `orientation`: the EXIF orientation (1–8), or `null`.
  - `has_alpha_channel`: the format or color type carries alpha.
  - `is_opaque`: `true` when there's no alpha channel or every pixel is fully opaque; `false` when at least one pixel isn't. It's `null` until computed. **Why:** a PNG with an alpha channel but no transparent pixels prints as a solid rectangle, a classic print-on-demand mistake that Turbopod warns sellers about. `has_alpha_channel` alone can't catch it.
  - `dpi_x`, `dpi_y`: from pHYs, JFIF or EXIF resolution, or `null` when the file doesn't declare one. **Never default to 72.**
  - `bit_depth` (per channel), `color_space` (`srgb`, `gray`, `cmyk`, …), `has_icc_profile`, `animated`, `frame_count`.
- Video properties (optional): `width`, `height`, `duration`, `video_codec`, `frame_rate`. Document properties (optional): `page_count`.
- **Cheap properties should come back in the upload response.** These are the ones read from headers: dimensions, alpha channel, DPI, bit depth, ICC presence. **Expensive ones may arrive later** with processing: `is_opaque` needs a full pass over the pixels.

#### 3.4.4 Renditions and rendition profiles

- **A rendition profile is a per-key setting.** It lists rendition specs, each with:
  - `name`: a slug;
  - `width`, `height`: a bounding box, never upscaled;
  - `fit`: `contain` scales down to fit inside the box and keeps the aspect ratio, with no crop and no padding; `cover` crops to fill the box, as today's thumbnail does;
  - `format`: `webp`, `png` or `jpg`;
  - `quality`.
- **The default profile for every existing key reproduces today's thumbnail exactly** (`thumb`, 200×200, `cover`, the current format), so existing consumers see no change.
- Turbopod's keys will use something like `thumb` (300×300, contain, webp) and `preview` (1200×1200, contain, webp).
- **Transparency:** webp and png renditions keep it. A jpg rendition flattens onto a background color set in the profile (default white).
- **EXIF orientation** is applied to renditions. Renditions are converted to sRGB when the source has an ICC profile, because they're for screens.
- **Response shape:**
  - `renditions: { "thumb": {url, width, height, mime_type, size}, … }`: `{}` when there are none, `null` while they're pending.
  - `thumbnail_url` stays as an alias of the `thumb` rendition's URL, or `null` when the profile has no `thumb`.
- **Renditions are stored and served like files:** public, a random token per object, served straight from storage. They are deleted together with the file and rotate with it (§5.2). They don't appear as separate files in listings.
- **Changing a key's profile doesn't touch existing files.** `POST /file/{uuid}/renditions` regenerates one file's renditions from the current profile. It's idempotent and sets the status back to `pending`. A bulk regeneration per key or folder through cron is nice to have.
- **Decompression-bomb guard:** when the header's width × height is above the instance's `max_pixels`, the pixel work is skipped and processing ends `failed` with that reason. The original is still stored.

#### 3.4.5 When processing runs, and its status

- **The upload response must not wait for heavy work.** Anything that decodes every pixel, or runs ffmpeg or Ghostscript, runs in Explorer's cron queue. Processing inline is allowed as an optimization for small files, below configurable byte and pixel thresholds.
- The File gets `processing: {status, error}`:
  - `none`: nothing to do for this type or profile;
  - `pending`: queued or running;
  - `ready`: every rendition succeeded;
  - `failed`: at least one rendition failed. `renditions` still holds the ones that succeeded, and `error` says what failed.
- **The queue is bounded and resumable:**
  - each cron run handles at most N files or T seconds;
  - a file stuck in processing past its lease is retried;
  - after the maximum number of attempts it's `failed`;
  - each attempt overwrites its own outputs, so retries are idempotent.
- Consumers poll `GET /file/{uuid}`, or use the batch lookup in §5.4. There are no webhooks.

#### 3.4.6 Contract fixtures

Every processor implementation must pass the same set of fixtures. Properties must match exactly. Renditions are checked on dimensions, format and whether alpha is present, not pixel by pixel.

- PNG RGBA with transparent pixels: `has_alpha_channel=true`, `is_opaque=false`.
- PNG RGBA with every pixel opaque: `has_alpha_channel=true`, `is_opaque=true`.
- PNG RGB: `has_alpha_channel=false`, `is_opaque=true`.
- 16-bit PNG: `bit_depth=16`.
- PNG with pHYs at 300 DPI: `dpi_x=dpi_y=300`. PNG without pHYs: `null`.
- PNG with an ICC profile: `has_icc_profile=true`, and the renditions are sRGB.
- JPEG with EXIF orientation 6: the renditions are rotated.
- Animated GIF: `animated=true`, `frame_count`, and the rendition is the first frame.
- A large image near `max_pixels` finishes within the memory limit. A header above `max_pixels` is refused without being decoded.
- A truncated or corrupt file fails cleanly: a non-zero exit with an error message, no crash, no partial output used.
- Optional handlers bring their own fixtures: an MP4 for the poster frame, a PDF for page count and the first page.

#### 3.4.7 Security for handlers

- **Imagick:**
  - a restrictive `policy.xml` that disables MVG, MSL, URL, HTTP/HTTPS, EPHEMERAL and TEXT, plus PS/EPS/PDF unless the PDF handler needs them;
  - resource limits for memory, map and disk, with the temp path inside the account;
  - the decoder forced to match the sniffed type (`png:/path`), so a file can't pass for another format.
- **ffmpeg:** `-protocol_whitelist file`, input from a local temporary file only, and playlist and concat formats refused. HLS playlists are a known path to reading local files and making server-side requests.
- **SVG:** never rasterized with external entities or references enabled.
- **Ghostscript** (only if the PDF handler is built): `-dSAFER`.

---

## 4. Tier 2: each one removes significant work or risk on Turbopod's side

Turbopod's own design depends on §4.1 and §4.2, so report whether they're feasible **before** the rest (§8).

### 4.1 Key provisioning API

**Today:** keys are created only in the admin panel. The key is the bearer secret, and a key can schedule its own deletion.

**Why:** Turbopod creates a workspace without any manual step and gives it its own key. Explorer then enforces tenant isolation and per-workspace storage quotas (`usage_limit_mb`), and offboarding is one call with the existing 14-day grace period.

**Required:**
- **A new credential: the provisioner.** The operator creates it in the admin panel, one per consuming project.
  - It can only manage keys. It can't upload, list or read files.
  - It sees and manages **only the keys it created**, so one project's provisioner can't touch another project's keys.
- **Keys get a non-secret identifier** (`id`, a UUID) separate from the bearer secret, so that management routes and logs never carry secrets.
- **Provisioning routes live under their own prefix** (e.g. `/provision/...`), so the two kinds of credential never mix.
- Failed provisioner authentications are rate-limited.

**Suggested shape:**

```
POST   /provision/keys                    name, usage_limit, usage_limit_mb, max_upload_size, expiration,
                                          verbatim_default, rendition_profile   (+ Idempotency-Key, §4.2)
                                          → key with `id` and `secret` (the secret is returned only here and on rotation)
GET    /provision/keys                    keys created by this provisioner
GET    /provision/keys/{id}               settings + usage (uses, total_size, …)
PUT    /provision/keys/{id}               limits, profile, verbatim_default, active, expiration
DELETE /provision/keys/{id}               schedule deletion (same semantics as DELETE /key: 14 days)
POST   /provision/keys/{id}/cancel-deletion
POST   /provision/keys/{id}/rotate-secret → new secret; the old one stops working immediately
```

### 4.2 Idempotency-Key on writes

**Today:** a retried upload creates a second file.

**Why:** Turbopod uploads from cron jobs that retry after timeouts and lost connections. Without this, every retry after a success Turbopod never saw leaves an orphan file that uses quota.

**Required:**
- `POST /files` (every source: multipart, URL, direct upload) and `POST /provision/keys` accept an `Idempotency-Key` header. `POST /folders` and `POST /shares` are optional.
- The pair (credential, Idempotency-Key) is unique for a retention window of at least 24 hours.
- **A retry after the first request completed** returns the same response (the same File UUID). It creates no new object and doesn't increment `uses`.
- **A retry while the first request is still in progress** gets a 409.
- **The same key with a different payload** (a different sha256 or different parameters) gets a 409 or 422 "idempotency key reused with a different request".

### 4.3 Upload from a URL

**Today:** there's no way to upload from a URL. Files must be sent as multipart.

**Why:** Turbopod's imports take files from platform URLs, and some of those expire after 72h. Without this, every file travels platform → Turbopod → Explorer, through shared hosting, twice.

**Required:**
- `POST /files` accepts `source_url` in place of `file`. The two are mutually exclusive. It also takes an optional `name` (the display name; otherwise it comes from `Content-Disposition` or the URL path), `folder_uuid`, `tags`, `verbatim` and `Idempotency-Key`.
- It works for every file type, with the same sniffing, refusal, limits and processing as an upload.
- **Protection against server-side request forgery:**
  - HTTPS only;
  - resolve the host, refuse private, loopback, link-local, CGNAT, multicast and reserved addresses (IPv4 and IPv6), and connect to the IP that was checked, so a DNS change between check and connect (DNS rebinding) doesn't work;
  - at most 3 redirects, each one checked again;
  - the size limit enforced while streaming, aborting above the effective maximum;
  - connect and total timeouts;
  - the body streamed to a temporary file;
  - no credentials forwarded.
- Separate errors for "URL refused" (400) and "fetch failed" (502, with the upstream status in the message).
- Synchronous behavior, within a configurable total timeout, is fine: Turbopod calls it from background jobs.

### 4.4 Timestamps in ISO 8601 UTC

**Today:** `YYYY-MM-DD HH:MM:SS` in the server's timezone, with no offset.

**Required:** every datetime field (`created_at`, `last_viewed_at`, `execute_at`, `expiration`, the new ones) can be read unambiguously as UTC, e.g. `2026-11-17T03:22:10Z`.

This **breaks existing consumers.** List them, then either switch everywhere (with a changelog entry and, if needed, an API version bump), or keep the old fields and add UTC ones (e.g. `created_at_utc`). Turbopod only needs one unambiguous form.

---

## 5. Tier 3: useful later, not blocking

### 5.1 Direct uploads with one-time tokens

**Why:** it removes the double hop of large files through the consumer's PHP, which matters for bulk uploads of print files. It needs §3.4.3, so the consumer can validate the file after it's uploaded.

**Required:**
- `POST /upload-tokens` (key auth) takes `folder_uuid`, `max_size` (at most the key's limit), the allowed `file_types`, an expiry (short, e.g. 15 minutes or less), and optionally `verbatim` and `tags`. Each token is single use. It returns `{token, upload_url, expires_at}`.
- The browser sends a multipart POST to `upload_url` without the key. Processing is the same as for `POST /files`.
- The consumer's backend confirms the upload through `GET /upload-tokens/{token}` (key auth), which returns the status and the file's UUID. The backend never trusts a UUID sent by the browser.

### 5.2 URL rotation

`POST /file/{uuid}/rotate` gives the original and every rendition a new random token. The old URLs stop working, and it returns the updated File. The docs must note that CDN caches may keep serving the old URL for a while.

**Why:** a leaked URL can be revoked without deleting the file.

### 5.3 Recursive folder delete

`DELETE /folder/{uuid}?recursive=1` deletes the whole subtree: files, renditions, shares and subfolders. A large tree is handled in cron, with the folder marked as pending deletion. It's lower priority once §4.1 exists.

### 5.4 Batch lookup

`POST /files/lookup` takes `uuids[]` (at most 100) and returns the ones the key owns. Unknown UUIDs are left out.

**Why:** to poll the processing status of many files in one call.

### 5.5 JSON body detection

Parse the media type, so that `application/json; charset=utf-8` is read as JSON.

---

## 6. Not wanted

- **Signed or expiring URLs, and anything like MagratheaImages' secure mode.** Platforms must fetch files from plain public HTTPS URLs, sometimes days later.
- **On-demand resizing through the URL.** It puts PHP back on the read path, and with R2/S3 as primary storage every cache miss would download the full original. Fixed renditions (§3.4.4) replace it.
- **Sequential or guessable identifiers of any kind.**
- **Webhooks.** Polling is enough.
- **Previews of office documents** (LibreOffice) **and audio waveforms.**
- **Changes to tags, search or pagination for Turbopod's sake.** Turbopod doesn't use them.

---

## 7. Open questions to settle against the code

1. Does `no_convert=1` re-encode or strip anything today? That decides how big §3.1 is.
2. How is today's thumbnail made: which format, which library, synchronously or not?
3. Do renditions count toward `total_size` / `usage_limit_mb`? The recommendation is yes, because storage is storage, with usage split between originals and renditions.
4. Which consumers exist today, and which of them break with the boolean fix (§3.1) and the timestamps (§4.4)?
5. Are Imagick, ffmpeg and Ghostscript available on the target DreamHost server? Before building §3.4, run this test from cron: resize a PNG of about 105 MP with alpha into a 1200 px webp, under the memory limits. If the process watcher kills it, the first engine has to be pyvips (`pyvips[binary]`).
6. Which storage driver does the instance Turbopod uses run on? R2 is recommended.

---

## 8. What to send back to Turbopod

- **For each item:** its state in the code today (exists / partial / missing), an effort estimate, and every deviation from this document. Names and routes can change freely. **Required** behavior changes only with agreement.
- **Feasibility of §4.1 and §4.2 first,** because they change Turbopod's design before its P3 spec is written.
- **The results of the DreamHost test** (§7, question 5).
- **When the work is done:** the updated `docs/openapi.yaml`, skill guide and `/error-codes`. Turbopod's integration will be written against them, not against this document.

**Priority:** Tier 1 (§3) before Turbopod's P3. Tier 2 next, §4.1 and §4.2 first. Tier 3 when convenient.
