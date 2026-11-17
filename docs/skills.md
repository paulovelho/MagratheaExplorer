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

- **Key**: one bearer secret (`Authorization: Bearer <uuid>`) per consuming project. An
  operator creates keys in the admin panel (`/admin.php` → Keys). **No API route creates or
  rotates a key.** The key has full power over everything it owns: upload, rename, delete,
  share, and even scheduling its own deletion. No scopes, no read-only keys, no login, no refresh.
- **Folder**: a virtual container in the database only, nestable and owned by one key. Every key
  has a hidden **root folder**. Whenever a route takes `folder_uuid`/`parent_uuid`, leaving it
  out means "root". The root never shows up in a listing.
- **File**: one uploaded object, always inside exactly one folder. It's served **directly
  from storage** (local vhost, S3 or R2) at a URL that needs no auth. PHP isn't involved in
  reads, so headers like Content-Type and Content-Disposition are fixed at upload time.
- **Share**: an optional public link to one file or one folder subtree. The share's uuid
  is the credential.
- **Identifiers**: folders, files and shares are addressed by lowercase UUIDv7. Integer ids
  are never exposed.

**Files are not private.** Every file's `url` works for anyone who has it, until the file is
deleted. The only protection is a random 125-bit token in the path, under a per-key
directory. There are no signed or expiring URLs and no per-viewer access control. Don't store
anything here that needs per-user authorization at read time.

---

## Step 0: configuration and discovery

Give the consuming project two settings, kept server-side only:

```
EXPLORER_API_URL=https://explorer.example.com/api/v1
EXPLORER_API_KEY=<key uuid from the operator>
```

Then check that the server is reachable and read its limits:

```
GET /version        → { api, version, environment, magrathea_version }
GET /settings       → { app_name, max_upload_size: "5242880", max_upload_size_formatted: "5 MB", thumbnail_size: 200 }
GET /health-check   → { health: "ok", time, database: "ok" | "fail" }
GET /key            → confirms the key works: { name, uses, usage_limit, total_size, usage_limit_mb, expiration, active, created_at }
```

`max_upload_size` is the configured cap. The real cap may be lower: PHP's
`upload_max_filesize`/`post_max_size` also apply. Reject larger files on the client anyway,
so users get a clear message instead of a 4002.

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
  `4151` → 415, `5001` → 500).
- `message` can carry a detail after a colon: `"Missing required field: name"`.
- An unhandled server error has no `code`, only `{ "message": "Internal Server Error" }`.

---

## Auth

```
Authorization: Bearer <key-uuid>
```

Required on everything except `/version`, `/health-check`, `/settings`, `/changelog`,
`/error-codes`, and the two public `/shared/...` routes.

**Every auth failure looks the same:** a missing header, an unknown key, an inactive key and
an expired key all give HTTP 401 with `code: 401` and the message
`Authorization Failed! (IsAuthenticated = false)`. Codes 4011/4012/4013/4041 appear in
`/error-codes` but never reach a client. If you need to know *why* a key fails, an operator
has to look in the admin panel.

CORS is open (`*`). **Don't use that as a reason to call the API from a browser** with
the key. Anyone who reads the key from the page can delete every file. Keep the key on your
backend.

---

## Request bodies

| Route kind | Content-Type |
|---|---|
| `POST /files` (upload) | `multipart/form-data` |
| other POST / PUT | `application/x-www-form-urlencoded` (recommended) or `application/json` |

**JSON trap:** JSON is only detected when the `Content-Type` header *ends in* `json`. A
`Content-Type: application/json; charset=utf-8` body gets parsed as a form, every field
reads as missing, and you get a 4001. Use form-urlencoded, like the first-party app, or send
exactly `application/json`.

---

## Workflow 1: Upload a file

```
POST /files
Authorization: Bearer <key>
Content-Type: multipart/form-data

file=<binary>                 required, field name must be "file", one file per request
folder_uuid=<uuid>            optional, defaults to the key's root folder
tags=invoice,2026             optional, comma-separated
no_convert=1                  optional, images only, keep the original format
```

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
  "width": 1920,
  "height": 1080,
  "duration": null,
  "no_convert": false,
  "url": "https://files.example.com/<key-storage-uuid>/Xk3pQ9vT2mLr8sWn4bYc7.webp",
  "thumbnail_url": "https://files.example.com/<key-storage-uuid>/a8Ff2LkQ0pZr6TnY1wVx3.jpg",
  "tags": ["invoice", "2026"],
  "created_at": "2026-11-17 03:22:10"
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
- **Images:** EXIF/IPTC/XMP metadata is always stripped, and the image is re-encoded to
  **webp** unless `no_convert=1`. SVG and animated GIF are never converted. A square
  crop-to-fill thumbnail of `thumbnail_size` px is built **once, at upload**. SVG has no
  thumbnail (`thumbnail_url: null`). There's no on-demand resizing of any kind.
- **Downloads:** `file_type: other` and SVG are always served as `attachment` (stored-XSS
  protection), everything else `inline`. The original filename is kept for downloads.
- `name` is the client's original filename, extension included (`photo.jpg`, even though
  it's stored as `.webp`). `size` is the **stored** size, after conversion.

`no_convert` trap: any non-empty value except `0` counts as true, so `no_convert=false`
means **true**. Send `1` or leave the field out.

---

## Workflow 2: Organize with folders

```
GET    /folders?parent_uuid=<uuid>     direct children (omit parent_uuid for root's children)
POST   /folders                        name=<string>&parent_uuid=<uuid?>
GET    /folder/{uuid}                  one folder (works for root too: is_root=true, name="root")
PUT    /folder/{uuid}                  name=<new name>       (rename only, no moving)
DELETE /folder/{uuid}                  only when EMPTY, never root
GET    /folder/{uuid}/size             { size } = bytes in the whole subtree
```

Folder `data`: `{ uuid, parent_uuid, name, is_root, created_at }`.

- Names are trimmed and **unique among siblings**. A duplicate gives 4004 (400).
- **No lookup by name and no path addressing.** To find "invoices/2026", list the
  children of root, match the name, list that folder's children, and so on. Store folder uuids in
  your own database rather than resolving names on every request.
- **No recursive delete.** A folder with subfolders or files gives 4003. Delete its files, then
  its subfolders deepest-first.
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
GET /files?folder_uuid=<uuid>&file_type=<type>&tag=<name>
GET /file/{uuid}
```

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
- Delete removes the object, its thumbnail, its tags and **its shares**, and lowers the key's
  `total_size`. After that, the file's URLs stop working. A 4043 on delete means the file is
  already gone, so treat it as success in cleanup code.

---

## Workflow 5: Serve files in your app

- **Use `url` / `thumbnail_url` exactly as returned.** Don't build, parse or rewrite them.
  The storage base depends on the instance's driver, and the local driver adds
  `?disposition=…&filename=…`.
- **Store `uuid` in your own database.** You can also cache `url`/`thumbnail_url`: the path never
  changes while the file exists. On the local driver the query string follows the display
  name, so a rename changes it, and the old URL still works.
- They're plain public URLs. Put them straight into `<img src>`, `<audio>`, `<video>` or download
  links. No auth header needed.
- Need other image sizes? The API won't make them. Use CSS or `srcset` with the original, or
  host images in MagratheaImages instead.

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
  name. `SharedFile` is a File without `folder_uuid`, `no_convert` or `tags`.
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
```

- `uses` counts **lifetime uploads** and never goes down. `usage_limit` caps lifetime uploads,
  not files currently stored, so deleting files doesn't free count. `total_size` is the live
  number of bytes stored and does go down on delete. `usage_limit_mb` caps it, in MiB.
  `null` means unlimited for both.
- The quota check runs before processing, against the **raw** upload size.
- While a deletion is pending: uploads fail with **4014 (HTTP 401)**, and every public share
  link of the key gives 4045. Everything else keeps working, so the key can still list and
  clean up. After `execute_at` a cron job deletes the key and everything it owns.
- **Never call `DELETE /key` from integration code** unless the user explicitly asked to
  decommission the key.

---

## Error codes

| Code | HTTP | Meaning | What to do |
|---|---|---|---|
| 401 | 401 | Auth failed (missing/bad/inactive/expired key) | Check the key and the `Bearer ` prefix. Ask the operator |
| 404 | 404 | Not a valid endpoint | Missing `/api/v1` prefix, wrong method, or typo |
| 4001 | 400 | Missing required field (`: name`, `: file`, `: file_type`, …) | Check the field name and body encoding (JSON trap) |
| 4002 | 400 | File too large | Compare with `/settings` and PHP limits |
| 4003 | 400 | Folder not empty | Empty it first |
| 4004 | 400 | Folder name already exists here | Reuse the existing one (ensure-folder pattern) |
| 4005 | 400 | Cannot delete root folder | |
| 4006 | 400 | Deletion already pending | |
| 4007 | 400 | No pending deletion to cancel | |
| 4008 | 400 | Invalid (empty) tag name | |
| 4014 | 401 | Key has a pending scheduled deletion (on upload) | Cancel the deletion or use another key |
| 4031 | 403 | Upload count limit reached | Lifetime cap, so deleting won't help. Ask the operator |
| 4032 | 403 | Storage size limit reached | Delete files or ask for a bigger `usage_limit_mb` |
| 4042 | 404 | Folder not found (or another key's) | |
| 4043 | 404 | File not found (or another key's) | Treat as "already deleted" in cleanup code |
| 4044 | 404 | Tag not found | |
| 4045 | 404 | Share not found / link not valid | |
| 4151 | 415 | Unsupported or dangerous file type | Executables and scripts are never accepted |
| 5001 | 500 | Storage backend error | Retry later, then report to the operator |
| 5002 | 500 | Image processing failure | Try `no_convert=1`, or the file is corrupt |
| 5003 | 500 | Token generation exhausted | Retry |
| 5004 | 500 | Incorrect configuration | Report to the operator |

Something owned by another key always looks like "not found", never 403. `GET /error-codes`
returns the live map.

---

## Client snippets

**PHP (ext-curl, no dependencies)**

```php
final class ExplorerClient {
	public function __construct(private string $baseUrl, private string $key) {}

	/** @return mixed the envelope's `data` */
	public function request(string $method, string $path, ?array $fields = null, bool $multipart = false) {
		$ch = curl_init(rtrim($this->baseUrl, "/")."/".ltrim($path, "/"));
		$headers = ["Authorization: Bearer ".$this->key];
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

	public function upload(string $localPath, string $originalName, ?string $folderUuid = null, array $tags = []): array {
		$fields = ["file" => new \CURLFile($localPath, null, $originalName)];
		if($folderUuid) $fields["folder_uuid"] = $folderUuid;
		if($tags) $fields["tags"] = implode(",", $tags);
		return $this->request("POST", "files", $fields, true);
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

async function call<T>(method: string, path: string, body?: FormData | Record<string, string>): Promise<T> {
  const headers: Record<string, string> = { Authorization: `Bearer ${KEY}` };
  let payload: BodyInit | undefined;
  if (body instanceof FormData) payload = body;                       // multipart: let fetch set the boundary
  else if (body) { payload = new URLSearchParams(body); headers['Content-Type'] = 'application/x-www-form-urlencoded'; }
  const res = await fetch(`${BASE}/${path.replace(/^\//, '')}`, { method, headers, body: payload });
  const env = await res.json().catch(() => null);
  if (!env || env.success !== true) {
    throw new ExplorerError(res.status, env?.data?.code ?? null, env?.data?.message ?? `HTTP ${res.status}`);
  }
  return env.data as T;
}

// e.g. new Blob([await fs.readFile(path)]) or a File from an incoming multipart request
export function uploadFile(file: Blob, filename: string, folderUuid?: string, tags: string[] = []) {
  const form = new FormData();
  form.append('file', file, filename);
  if (folderUuid) form.append('folder_uuid', folderUuid);
  if (tags.length) form.append('tags', tags.join(','));
  return call<ExplorerFile>('POST', 'files', form);
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
  size: number; width: number | null; height: number | null; duration: number | null;
  no_convert: boolean; url: string; thumbnail_url: string | null; tags: string[]; created_at: string;
}
```

---

## Integration checklist

- [ ] Key and base URL come from server-side config or env. The key never reaches a browser bundle.
- [ ] Browser uploads go **through your backend**: it receives the file and re-posts it as
      multipart to `/files`.
- [ ] The base URL includes `/api/v1`.
- [ ] Responses are unwrapped from `{ success, data }`, and `success` is checked.
- [ ] POST/PUT bodies are form-urlencoded, or JSON with exactly `Content-Type: application/json`.
- [ ] File size is checked against `/settings` before upload.
- [ ] Your database stores the file `uuid` (plus `url`/`thumbnail_url` if you want).
      Nothing is derived from URLs.
- [ ] Folder uuids are stored, not resolved by name on every request.
- [ ] Deleting your entity calls `DELETE /file/{uuid}`, with 4043 treated as success.
- [ ] Error handling branches on `data.code`, not on the message text.
- [ ] Timestamps are parsed as `YYYY-MM-DD HH:MM:SS` in the server's timezone (no offset, not ISO 8601).

---

## What the API does NOT do

Key creation or rotation · read-only or scoped keys · private, signed or expiring file URLs ·
on-demand resizing · upload from a remote URL · several files per request · moving or
copying files or folders · recursive folder delete · lookup by name or path · key-wide
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
| Resizing | on-demand `WxH` in the URL, disk-cached | one fixed square thumbnail, made at upload |
| Storage | local disk (R2 is backup-only) | local, S3 or R2 as the primary store (one per instance) |
| Key creation | `POST /key/create` + shared secret | admin panel only |
| Response | varies per route | always `{ success, data }` |
