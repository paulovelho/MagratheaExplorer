# UUID identity + per-key storage — implementation plan

Implements `.claude/blueprint_refactor.md`:

1. UUIDs as the public identity of folders and files.
2. Every stored object lives under a directory belonging to the owning key.

---

## 0. Decisions

- `folders` and `files` get a `uuid` column. Int `id` stays the PK; every FK stays an int.
- `access_keys` gets a second uuid, **`storage_uuid`**, used only as the storage directory name.
  `access_keys.uuid` is the bearer credential and must never appear in a URL.
- Storage layout is flat per key: `{storage_uuid}/{token}.{ext}`. The base62 token stays the
  filename. The disk does not mirror the folder tree.
- Same layout on both drivers (local and S3/R2).
- Fresh start: `database.sql` rewritten, no backfill, no storage-move script. The running instance
  is wiped and re-imported. Re-created keys get new bearer uuids — every integration needs the new
  key at cutover.
- JSON fields and route params are renamed: `id` → `uuid`, `parent_id` → `parent_uuid`,
  `folder_id` → `folder_uuid`. Legacy names are not accepted.
- The Magrathea admin (`/admin.php`) stays on int ids. The Vue app (`/app`) converts fully.
- Version → `1.2.0`. Route prefix stays `api/v1`.

---

## 1. Constraints to respect while building

1. **Route params are lowercased.** `MagratheaApi::Run()` (`MagratheaApi.php:432`) lowercases the
   whole URL before dispatch. UUIDv7 is lowercase hex, so it survives. `File\TokenGenerator`'s
   base62 tokens are mixed-case and **must never become route params** — filenames only.
2. **`api/src/.htaccess` already accepts hyphens** in route segments (`([a-zA-Z0-9_-]+)`). No
   rewrite change needed.
3. **The framework mints UUIDv7 automatically** for any `dbValues` field declared `"uuid"` and left
   empty (`MagratheaModel.php:272`). No generator class, no collision loop. The property is
   populated on the object after `Insert()`.
4. **`Base/` classes are generated files.** Hand-edit them and mark each edit with a comment, as
   `FolderBase::GetParent()` already does. Regenerating through the admin silently drops the
   `dbValues["uuid"]` line and breaks inserts against the `NOT NULL` column.
5. **`Insert()` writes every declared field; unset ≠ SQL DEFAULT.** `files.thumbnail_path` must be
   set to `null` explicitly when there is no thumbnail.
6. **Array-form `Where` escapes string values** (`Query.php:571`), so `GetRowWhere(["uuid" => $x])`
   is injection-safe. Never interpolate a uuid into `GetSimpleWhere()`'s raw SQL — those call sites
   interpolate `(int)$folder->id` only.
7. **`LocalStorageAdapter::put()` already creates subdirectories** (`mkdir(..., recursive)`). No
   adapter change is needed for the new path depth.
8. **`api/storage/.htaccess` is inherited by subdirectories** and its rule (`RewriteRule ^ -`)
   matches any path, so `Content-Disposition` keeps working one level deeper.
9. Error code → HTTP status is `intval($code / 10)`. No new error codes: an unknown uuid reuses
   `4042` (folder) / `4043` (file).

---

## 2. Schema

`api/database/database.sql`, edited in place. Four new columns:

```sql
CREATE TABLE `access_keys` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`uuid` char(36) NOT NULL UNIQUE,
	`storage_uuid` char(36) NOT NULL UNIQUE,     -- NEW
	...
);

CREATE TABLE `folders` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`uuid` char(36) NOT NULL UNIQUE,             -- NEW
	`key_id` int(11) NOT NULL,
	...
);

CREATE TABLE `files` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`uuid` char(36) NOT NULL UNIQUE,             -- NEW
	`token` char(21) NOT NULL UNIQUE,
	`thumbnail_token` char(21) NULL UNIQUE,
	`thumbnail_path` varchar(255) NULL,          -- NEW
	...
);
```

`storage_uuid` carries a SQL comment in the file stating that `uuid` is the bearer credential and
`storage_uuid` is the public directory name, and that the two must never be swapped.

Unchanged: `files.token`, `files.thumbnail_token`, and every FK (`files.folder_id`,
`folders.parent_id`, `*.key_id`, `file_tags.*`).

---

## 3. Storage layout

| | before | after |
|---|---|---|
| file | `{token}.{ext}` | `{storage_uuid}/{token}.{ext}` |
| thumbnail | `{thumbnail_token}.{jpg\|png}` | `{storage_uuid}/{thumbnail_token}.{jpg\|png}` |

```
/data/storage/.htaccess
/data/storage/018f2a3b-7c41-7a02-9f3e-1d2c4b5a6e7f/gK3mZp0QwLxV7nB2cRtYs.webp
/data/storage/018f2a3b-7c41-7a02-9f3e-1d2c4b5a6e7f/Qw8fLn2XvB0mKjT5rYzAe.webp   <- thumbnail
```

On S3/R2 the identical string is the object key prefix.

`Key::StoragePathFor()` is the only place a storage path is assembled.

---

## 4. API surface

### Routes (`api/src/api.php`)

| before | after |
|---|---|
| `GET folders` (`?parent_id=`) | `GET folders` (`?parent_uuid=`) |
| `POST folders` (body `parent_id`) | `POST folders` (body `parent_uuid`) |
| `GET/PUT/DELETE folder/:id` | `GET/PUT/DELETE folder/:uuid` |
| `GET folder/:id/size` | `GET folder/:uuid/size` |
| `POST files` (field `folder_id`) | `POST files` (field `folder_uuid`) |
| `GET files` (`?folder_id=`) | `GET files` (`?folder_uuid=`) |
| `GET/PUT/DELETE file/:id` | `GET/PUT/DELETE file/:uuid` |
| `POST file/:id/tags` | `POST file/:uuid/tags` |
| `DELETE file/:id/tags/:tag` | `DELETE file/:uuid/tags/:tag` |

Segment counts are unchanged. The `:uuid` name is what `GetParamsFromRoute()` keys on, so
controllers read `$params["uuid"]`.

Route hint strings also change: `"GET: parent_uuid=?"` and
`"GET: folder_uuid=?, file_type=?, tag=?"`.

### Response shapes

```jsonc
// Folder
{
  "uuid": "018f2a3b-7c41-7a02-9f3e-1d2c4b5a6e7f",
  "parent_uuid": "018f2a3b-...",   // null for the root folder
  "name": "Photos",
  "is_root": false,
  "created_at": "2026-09-22 14:03:11"
}

// File
{
  "uuid": "018f2a44-...",
  "folder_uuid": "018f2a3b-...",
  "name": "photo.jpg",
  "extension": "webp",
  "mime_type": "image/webp",
  "file_type": "image",
  "size": 84213,
  "width": 1200, "height": 800, "duration": null,
  "no_convert": false,
  "url": "...", "thumbnail_url": "...",
  "tags": ["vacation"],
  "created_at": "..."
}
```

`id`, `folder_id` and `parent_id` are removed from responses, not kept alongside.

`storage_uuid` is not exposed by the API. It appears in the admin only (§5.9).

**Known behaviour:** a caller sending the old `?folder_id=` / `folder_id=` parameter gets the key's
root folder with HTTP 200, because the unrecognized parameter falls through to the documented
default. Accepted — there is no deployed client. Path params still 404 correctly.

---

## 5. PHP changes

### 5.1 `api/src/features/Key/Base/KeyBase.php` (generated — hand-edit)

- Add `$storage_uuid` to the property list.
- Add `$this->dbValues["storage_uuid"] = "uuid";` after the `uuid` line.
- Comment: declared `"uuid"` so `Insert()` mints it; separate from `uuid` because `uuid` is the
  bearer credential and this one goes in public URLs.

### 5.2 `api/src/features/Key/Key.php`

```php
/** Public, non-secret directory this key's objects live under -- never `uuid`, which is the bearer credential. */
public function StorageDir(): string { return $this->storage_uuid; }

/** The one place a storage path is assembled. */
public function StoragePathFor(string $token, string $extension): string {
    return $this->storage_uuid."/".$token.".".$extension;
}
```

`Normalize()` gets no new line, plus a one-line comment saying `storage_uuid` is auto-generated
(its docblock otherwise implies every field must be defaulted there).

### 5.3 `api/src/features/Folder/Base/FolderBase.php` (generated — hand-edit)

Add `$uuid` to the property list; `$this->dbValues["uuid"] = "uuid";` after `id`. Same comment.

### 5.4 `api/src/features/File/Base/FileBase.php` (generated — hand-edit)

Add `$uuid` and `$thumbnail_path` to the property list;
`$this->dbValues["uuid"] = "uuid";` and `$this->dbValues["thumbnail_path"] = "string";`.

### 5.5 `api/src/features/Folder/FolderControl.php`

- **New** `GetForKeyByUuid(Key $key, string $uuid): Folder` — `GetRowWhere(["uuid" => $uuid,
  "key_id" => $key->id])`, throws `4042` on miss.
- **New** `UuidFor(?int $id): ?string` — `null` in, `null` out; otherwise one `GetRowWhere`.
- **Keep** `GetForKey(Key $key, int $id)` — used by `ImportAdmin` and `BrowserAdmin`.
- **Signature change** `Create(Key $key, ?Folder $parent, string $name)` and
  `GetOrCreate(Key $key, ?Folder $parent, string $name)` — take a resolved parent instead of an int
  id, keeping the internal `$parent = $parent ?? self::GetRoot($key)` fallback.
- Unchanged: `CreateRoot`, `GetRoot`, `Rename`, `AssertNotRoot`, `AssertEmpty`, `ComputeSize`.

### 5.6 `api/src/features/File/FileControl.php`

- **New** `GetForKeyByUuid(Key $key, string $uuid): File` — throws `4043` on miss.
- **Keep** `GetForKey(Key $key, int $id)` — used by `BrowserAdmin::DeleteFile()`.
- `DeleteFileAndStorage()` — delete `$file->thumbnail_path` instead of recomputing it.
- **Delete** the now-unused private `ThumbnailExtension()`.

### 5.7 `api/src/features/File/UploadPipeline.php`

In `Handle()` (around lines 97–130):

- `$storagePath = $key->StoragePathFor($fileToken, $finalExtension);`
- `$thumbStoragePath = $thumbnailToken !== null ? $key->StoragePathFor($thumbnailToken, $thumbnailExtension) : null;`
  — computed once, before the `put()`, reused for both the `put()` and the row.
- `$file->thumbnail_path = $thumbStoragePath;` (explicit `null` when absent).
- `$file->uuid` is left unset; add a comment that `Insert()` mints it.

Nothing else in the pipeline changes.

### 5.8 `api/src/features/Folder/FolderApi.php` and `api/src/features/File/FileApi.php`

Single-resource actions resolve by uuid:

```php
$folder = FolderControl::GetForKeyByUuid($key, (string)$params["uuid"]);
```

`FolderApi`:

- `GetAll()` — resolve `?parent_uuid=` to a `Folder` (or `GetRoot($key)`), then
  `GetSimpleWhere("`key_id` = ".(int)$key->id." AND `parent_id` = ".(int)$parent->id)`. Pass
  `$parent` into `FolderView()`.
- `Create()` — body `parent_uuid`, resolved before `FolderControl::Create()` (new signature).
- `FolderView(Folder $folder, ?Folder $parent = null)` — `parent_uuid` is `$parent?->uuid` when
  supplied, else `FolderControl::UuidFor($folder->parent_id)`, else `null`.

`FileApi`:

- `Upload()` — `$post["folder_uuid"]`.
- `GetAll()` — `?folder_uuid=`, resolved, filtered on `(int)$folder->id`; pass the folder into
  `FileView()`.
- `FileView(File $file, ?Folder $folder = null)` — emits `uuid` / `folder_uuid`; `thumbnail_url`
  becomes `$storage->url($file->thumbnail_path)`, removing the inlined png/jpg ternary.

### 5.9 Magrathea admin (`/admin.php`) — key screens only

`api/src/features/Key/admin/form.php` — add a disabled input next to the existing UUID field,
labelled **"Storage dir (public)"**, showing `$key->storage_uuid`.

No other admin file changes.

### 5.10 Storage adapters — empty key directories

- `StorageAdapter` — add `public function removeDirectory(string $path): void;`, documented as
  best-effort: never throws for an absent or non-empty directory.
- `LocalStorageAdapter` — `@rmdir($this->fullPath($path))`. Never recursive.
- `S3StorageAdapter` — no-op, with a docblock explaining S3 has no directories.
- `KeyControl::DeleteKeyNow()` — call it after the file loop and before `$key->Delete()`, while
  `$key->storage_uuid` is still readable.

---

## 6. App (`app/`)

- **`api/folders.js`** — `fetchFolders(parentUuid)` → `?parent_uuid=`; `fetchFolder(uuid)`,
  `renameFolder(uuid, name)`, `deleteFolder(uuid)`; `createFolder(name, parentUuid)` → body
  `parent_uuid`.
- **`api/files.js`** — `fetchFiles(folderUuid)` → `?folder_uuid=`;
  `fetchFile/renameFile/deleteFile(uuid)`; `uploadFile()` → `formData.append('folder_uuid', …)`.
- **`views/ExplorerView.vue`** — line 25 becomes `const folderUuid = computed(() => props.uuid || null)`.
  `Number('018f2a3b-…')` is `NaN`, so this one fails silently if missed. Keep the existing comment
  about vue-router resolving an omitted optional segment to `''`. Rename `folderId` → `folderUuid`
  throughout; `folder.id`/`file.id` → `.uuid` (lines 68, 73, 76, 82, 91, 104, 113).
- **`router/index.js`** — `/folder/:id?` → `/folder/:uuid?`. With `props: true` this renames the
  prop, so `defineProps` becomes `{ uuid: { type: String, default: undefined } }`.
- **`composables/useBreadcrumbs.js`** — crumbs become `{ uuid, name }`; `rebuild()` walks
  `folder.parent_uuid`. Update the example in the module comment on line 6.
- **`components/Breadcrumbs.vue`** — `:key="crumb.uuid ?? 'root'"`.
- **`components/FileList.vue`** — template keys → `.uuid` (lines 28, 41, 50, 72, 78, 92, 106, 113,
  127).
- Run `./script/build.sh` and commit the regenerated `dist/`.

---

## 7. Vhost / `.htaccess`

- `api/storage/.htaccess` — add `Options -Indexes`, with a comment: the storage root now has one
  directory per key, and a listing of one is a complete inventory of that key's files. This file
  ships in the repo and is copied to `local_path`, so this is what reaches production.
- `docker/apache/site-dev.conf` — `<Directory /var/www/storage>`: `Options Indexes FollowSymLinks`
  → `Options -Indexes FollowSymLinks`.
- `docker/caddy/site.caddy` — no change; add one comment in the `handle_path /storage/*` block
  noting `file_server` does not list directories by default.
- `api/src/.htaccess` — amend the "Bump this prefix (v1 -> v2) the day the route table needs a
  breaking change" comment to record that the prefix was deliberately left at `v1` for the uuid
  change.

---

## 8. Docs and version

- `docs/openapi.yaml` — `Folder`: `id`→`uuid` (`type: string, format: uuid`),
  `parent_id`→`parent_uuid`. `File`: `id`→`uuid`, `folder_id`→`folder_uuid`. All four `{id}` path
  params → `{uuid}`. Query/body params renamed. `info.version` → `1.2.0`.
- `docs/skills.md` — API mental model and every example.
- `README.md` — Quick usage example (`?folder_id=5` → `?folder_uuid=<uuid>`); directory-layout
  section; the `local` driver setup step (subdirectories under `local_path`, `.htaccess` at its
  root now also carrying `-Indexes`).
- `api/version` → `1.2.0`, no trailing newline.
- `api/changelog.md` — new entry stating plainly that this is a **breaking API change**: folders
  and files are addressed by uuid; `id`/`parent_id`/`folder_id` are gone from responses.

---

## 9. Commit order

1. Schema + model declarations — `database.sql`, the three `Base/` classes.
2. Storage layout — `Key::StoragePathFor()`, `UploadPipeline`, `FileControl`.
3. Empty-directory cleanup — `StorageAdapter` + both implementations + `DeleteKeyNow()`.
4. Control-layer uuid lookups — `GetForKeyByUuid`, `UuidFor`, the `Create`/`GetOrCreate` signature
   change, `ImportPipeline`'s two call sites.
5. API surface — `FolderApi`, `FileApi`, `api.php`. **Breaking commit**; the app is broken until 7.
6. Admin — `storage_uuid` on the key form.
7. App — `app/src/*` plus the rebuilt `dist/`.
8. Vhost hardening — `api/storage/.htaccess`, `docker/apache/site-dev.conf`, the two comments.
9. Docs + version bump.

---

## 10. Test checklist

Manual — there is no test suite in this repo.

**Setup**
1. Drop and reload the database from the rewritten `database.sql`.
2. `/admin.php` → create a key. `uuid` and `storage_uuid` are both populated and different.
3. The key's root folder row has a `uuid`.

**Storage**
4. Upload an image. On disk: `{local_path}/{storage_uuid}/{token}.webp` plus its thumbnail in the
   same directory; nothing at the storage root.
5. `files.storage_path` and `files.thumbnail_path` both carry the `{storage_uuid}/` prefix.
6. Open the returned `url` → renders inline. Download it → saves under the original filename.
7. Upload a `file_type=other` → `Content-Disposition: attachment` still fires.
8. `GET /storage/{storage_uuid}/` → 403, not a listing.

**API identity**
9. Every folder/file response has `uuid` and `parent_uuid`/`folder_uuid`, and no `id`, `parent_id`
   or `folder_id`.
10. Folder CRUD by uuid: create nested, rename, list children via `?parent_uuid=`, `/size`, delete.
11. `GET /folders` with no `parent_uuid` → the key's root children.
12. A second key's folder uuid → 404 / code `4042`.
13. A garbage uuid → 404, not a 500.
14. An uppercase uuid in a path param still resolves.
15. Tag attach/detach by file uuid.
16. `GET /files?folder_uuid=…&file_type=image&tag=x` — filters compose.

**Lifecycle**
17. Delete a file → both objects gone, `access_keys.total_size` decremented.
18. Admin force-delete a key → every object gone and the `{storage_uuid}/` directory removed.
19. Admin → Browser → orphan check reports nothing on a healthy instance.
20. Admin → Import: import a small tree; folders get uuids, files land under the key directory,
    re-running reuses the tree.

**App**
21. Login, navigate, breadcrumbs, create/rename/delete folder, upload with progress, rename/delete
    file, thumbnails render.
22. Deep link `/app/folder/{uuid}` in a fresh tab → breadcrumbs rebuild from `parent_uuid`.
23. Hard-refresh inside a nested folder.

---

## 11. Follow-ups and out of scope

- **`.claude/plan_share.md` is written against int ids.** Land this refactor first, then update its
  §4.5/§4.6 public response shapes to `uuid`/`folder_uuid` and reword §5.4's title. The `shares`
  table keeps int FKs; the subtree clamp is unchanged.
- Out of scope: folder move/reparent; uuids in the admin browse/import/duplicates screens; changes
  to token generation, the image pipeline, quota accounting, scheduled deletions or tags; a storage
  migration tool; orphan-checking `thumbnail_path`.
