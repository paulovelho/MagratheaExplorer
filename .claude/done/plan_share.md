# Share feature — implementation plan

Written against the working tree after the uuid-identity refactor (`api/version` = 1.2.0,
uncommitted). Nothing below is implemented yet.

## What it does

- Share one **file** or one **folder**. Creating a share mints a row in a new `shares` table and
  returns a link: `https://host/app/s/{share-uuid}`.
- Opening that link needs **no login**. A file share shows one file; a folder share is browsable
  **recursively, clamped to the shared subtree** — anything outside it 404s.
- **No expiry.** A link lives until the owner deletes the share row. A dead or unknown uuid is a
  plain 404, with one message: *"This link is not valid."*
- The public payload carries **no key uuid and no key name**. Folder and file uuids are included —
  they are opaque and grant nothing on their own; §4.3 is the actual gate.
- Files are served by the **same direct storage URLs** the owner already gets. Deleting a share
  stops the share page; a storage URL someone already copied keeps working. Deleting the file is
  the only thing that stops those.
- The owner manages links in a new **Shared links** section at `/app/shares`, plus a per-item
  dialog in the file list.

---

## 1. Gotchas verified in this codebase

### 1.1 Route params are lowercased — harmless here, but know why

`MagratheaApi::Run()` lowercases the whole URL before dispatch. `Uuid::V7()` builds from
`dechex()` + `bin2hex()`, both lowercase, so every uuid survives untouched (and `char(36)` under a
`_ci` collation would match regardless). Already documented in `plan_refactor.md` §1.1.

The rule it enforces: **`File\TokenGenerator`'s base62 tokens must never become route params.**
They are storage filenames only. Nothing in this plan puts one in a URL path.

### 1.2 `api/src/.htaccess` supports at most `control/action/params...`

`^api/v1/(seg)/(seg)/(rest)$` → `magrathea_control` / `magrathea_action` / `magrathea_params`, and
`index.php` rejoins them with `/` before re-splitting. Segments already accept hyphens
(`[a-zA-Z0-9_-]+`), so uuids pass. The 4-segment route `shared/:uuid/folder/:folder_uuid` works:
`control=shared`, `action={uuid}`, `params=folder/{folder_uuid}`. Verified against `CompareRoute()`.

### 1.3 Route matching is positional, first-match-wins

`FindRoute()` returns the first route in insertion order whose segment count and literals match,
within one HTTP method. `GET share/:uuid` and `GET shared/:uuid` do not collide (different literal
at position 0), but two routes under the same prefix would — hence the public surface lives at
**`shared/`** and the owner surface at **`share/` + `shares`**.

### 1.4 No FK is `ON DELETE CASCADE`

`KeyControl::DeleteKeyNow()` documents this. A `shares` row will **block deleting its target**
unless every delete path removes shares first — §5 lists all five call sites.

### 1.5 The SPA deep-link fallback already exists

`app/public/.htaccess` (copied to `dist/.htaccess` by Vite, committed) rewrites any non-file path
under `/app/` to `index.html`; `docker/caddy/site.caddy` does the same with `try_files`. A cold
load of `/app/s/{uuid}` works on Apache and Caddy already. **No vhost change needed.**

### 1.6 `Insert()` writes every declared `dbValues` field

An unset PHP property is written as-is; the SQL `DEFAULT` is never consulted (see
`Key::Normalize()`'s docblock). Every column needs an explicit value in PHP — **except** a field
declared `"uuid"`, which `Insert()` mints itself when left empty, and which is populated back onto
the object afterwards.

### 1.7 Error code → HTTP status is `intval($code / 10)`

`MagratheaApi::ReturnApiException()` maps a 4-digit code that way, so the one new code `4045` →
**HTTP 404**.

### 1.8 `Base/` classes are generated files

Per `plan_refactor.md` §1.4: hand-edit them and mark each edit with a comment. Regenerating
`ShareBase` through the admin silently drops the `dbValues["uuid"]` line and breaks every insert
against the `NOT NULL` column.

---

## 2. Schema — one new table

```sql
CREATE TABLE `shares` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`uuid` char(36) NOT NULL UNIQUE COMMENT 'The share link itself. Public but unguessable -- holding it IS the access check.',
	`key_id` int(11) NOT NULL,
	`file_id` int(11) NULL,
	`folder_id` int(11) NULL,
	`views` int(11) NOT NULL DEFAULT 0,
	`last_viewed_at` datetime NULL,
	`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	KEY `idx_shares_key` (`key_id`),
	KEY `idx_shares_file` (`file_id`),
	KEY `idx_shares_folder` (`folder_id`),
	CONSTRAINT `chk_share_target` CHECK (
		(`file_id` IS NOT NULL AND `folder_id` IS NULL) OR
		(`file_id` IS NULL AND `folder_id` IS NOT NULL)
	),
	FOREIGN KEY (`key_id`) REFERENCES `access_keys`(`id`),
	FOREIGN KEY (`file_id`) REFERENCES `files`(`id`),
	FOREIGN KEY (`folder_id`) REFERENCES `folders`(`id`)
);
```

- **`uuid` is both the row's public identity and the link secret** — one value, no second token
  column, no generator class. Same shape as `access_keys.uuid`, which is already a bearer
  credential at the same entropy (74 random bits after the v7 timestamp and variant bits).
- Int PK, int FKs — the rule `plan_refactor.md` §0 set for the whole schema.
- Exactly one of `file_id` / `folder_id` is set. The `CHECK` is belt-and-braces (MariaDB 10.2+);
  `ShareControl::Create()` enforces it in PHP regardless.
- `key_id` is denormalized off the target, as `files.key_id` already is.
- `views` / `last_viewed_at`: atomic `views = views + 1`, same pattern as `Key::IncrementUses()`.
  Counted only on a successful public load, never on a 404.

No existing table is modified: no `ALTER`, no backfill, no new column on `folders` or `files`.

---

## 3. API

### 3.1 Owner routes (`self::AUTHENTICATED`)

| Method | Route | Action | Notes |
|---|---|---|---|
| `POST` | `shares` | `ShareApi::Create` | body: `file_uuid` **xor** `folder_uuid` |
| `GET` | `shares` | `ShareApi::GetAll` | the key's shares; optional `?file_uuid=` / `?folder_uuid=` |
| `DELETE` | `share/:uuid` | `ShareApi::Delete` | kills the link |

No `PUT` (nothing mutable) and no `GET share/:uuid` — the list endpoint covers it.

### 3.2 Public routes (`self::OPEN`)

| Method | Route | Action |
|---|---|---|
| `GET` | `shared/:uuid` | `PublicShareApi::Get` |
| `GET` | `shared/:uuid/folder/:folder_uuid` | `PublicShareApi::GetFolder` |

### 3.3 `POST /shares`

```
file_uuid=0199f2a1-...        # xor folder_uuid
folder_uuid=0199f0b3-...
```

- Exactly one of the two, else `4001`.
- Target resolved via `FileControl::GetForKeyByUuid()` / `FolderControl::GetForKeyByUuid()`, so
  another key's target 404s rather than 403s.
- Sharing the **root folder** is allowed; `target_name` reports it as `"All files"`, never the
  literal row name `"root"`.
- Duplicate shares of the same target are allowed — separate uuids, deletable independently.
- Per §1.6, `Create()` explicitly sets `views = 0`, `last_viewed_at = null`, and the unused one of
  `file_id`/`folder_id` to `null`. `uuid` needs no line — `Insert()` mints it.

### 3.4 Owner response (`Share`)

```json
{
  "uuid": "0199f2a1-8c3d-7e04-b1f2-9a7c5d3e8f10",
  "url": "https://host/app/s/0199f2a1-8c3d-7e04-b1f2-9a7c5d3e8f10",
  "target_type": "folder",
  "target_name": "Photos",
  "file_uuid": null,
  "folder_uuid": "0199f0b3-2a11-7c88-9d40-1e6b0f4a2c77",
  "views": 3,
  "last_viewed_at": "2026-09-30 11:02:14",
  "created_at": "2026-09-22 14:10:00"
}
```

No `id` anywhere — the refactor removed int ids from every public response, and this follows suit.
`url` = `Config::Instance()->Get("app_url")` + `/app/s/{uuid}`, the same config key `api.php`
already uses for `SetAddress()`.

### 3.5 Public response — folder share

```json
{
  "type": "folder",
  "name": "Photos",
  "path": [
    { "uuid": "0199f0b3-2a11-7c88-9d40-1e6b0f4a2c77", "name": "Photos" },
    { "uuid": "0199f0b4-77aa-7d12-8e33-5c2f9b1d4e60", "name": "2025" }
  ],
  "folders": [ { "uuid": "0199f0b5-11cd-7a99-b002-73e8c1f5a934", "name": "ski-trip" } ],
  "files": [
    {
      "uuid": "0199f0c2-4d5e-7b31-a7f8-2b9e6d0c1a45",
      "name": "ski.jpg",
      "extension": "jpg",
      "mime_type": "image/jpeg",
      "file_type": "image",
      "size": 91233,
      "width": 1920,
      "height": 1080,
      "duration": null,
      "url": "https://cdn/{key-storage-uuid}/Xk92mQ....jpg?disposition=inline&filename=ski.jpg",
      "thumbnail_url": "https://cdn/{key-storage-uuid}/7Fp1vR....jpg",
      "created_at": "2026-09-01 08:22:10"
    }
  ]
}
```

The file entries are a strict subset of `FileApi::FileView()` — same field names, same types, so
`SharedFileList` can be built against the shape the app already knows.

### 3.6 Public response — file share

```json
{ "type": "file", "name": "contract.pdf", "file": { "...same file shape..." } }
```

### 3.7 Fields the public shape must not contain

`key_id`, the key's `uuid` (the bearer credential) or `name`, `views`, `no_convert`, `tags`, and
any folder **above** the share root — `path` starts at the share root, or the share leaks the shape
of the owner's tree.

`storage_uuid` appears inside every file `url` by construction; that is intended and already the
case for the owner's own URLs. It is the non-secret directory name, never `access_keys.uuid`.

---

## 4. PHP

Register the feature in `api/src/_inc.php`:

```php
->AddFeature("Key", "Folder", "File", "Storage", "Share")
```

### 4.1 New files

```
api/src/features/Share/
  Base/ShareBase.php        model -- $dbValues incl. ["uuid"] = "uuid"; relations to Key/File/Folder
  Base/ShareControlBase.php control base ($modelNamespace / $modelName / $dbTable)
  Share.php                 TargetType(), TargetName()
  ShareControl.php          Create / ResolveFromUuid / RegisterView
                            DeleteForFile / DeleteForFolder / DeleteForKey
  ShareApi.php              owner routes  (extends ExplorerApiControl)
  PublicShareApi.php        public routes (extends MagratheaApiControl -- NOT ExplorerApiControl)
  ShareAdmin.php            admin feature (§7)
  admin/list.php            admin view
```

`PublicShareApi` must **not** extend `ExplorerApiControl` — that base exists only to resolve a
bearer key, and a public route has none. Extending it makes it easy for a later edit to call
`GetRequestKey()` on an unauthenticated route and throw a confusing 401.

Mark the `dbValues["uuid"]` line in `ShareBase` with a comment (§1.8).

### 4.2 `Share` model

```php
public function TargetType(): string {
    return !empty($this->file_id) ? "file" : "folder";
}
```

### 4.3 `ShareControl::ResolveFromUuid()` — the whole public security gate

```php
public static function ResolveFromUuid(string $uuid): Share {
    if(empty($uuid)) ErrorCodes::Instance()->ThrowException(4045);
    $share = self::GetRowWhere(["uuid" => $uuid]);
    if($share === null) ErrorCodes::Instance()->ThrowException(4045);

    // A share dies with its key. Collapsed to the same 404 so the visitor learns nothing
    // about the owner's account state.
    $key = new Key($share->key_id);
    try {
        $key->AssertUsable();
        if($key->HasPendingScheduledDeletion()) throw new \Exception();
    } catch(\Throwable $ex) {
        ErrorCodes::Instance()->ThrowException(4045);
    }
    return $share;
}
```

Every public action starts with this call; nothing else in `PublicShareApi` touches auth.
`RegisterView($share)` runs only after the response is successfully assembled.

### 4.4 The subtree clamp — why a folder uuid is safe to hand out

`FolderControl::PathWithin(Folder $folder, Folder $root): ?array` walks `parent_id` **upward** from
`$folder`, collecting the chain, and returns it root-first when it reaches `$root` — or `null` if it
runs out of parents or exceeds a depth cap of 64 (cycle guard, same posture as
`KeyControl::DeleteFoldersDeepestFirst()`'s `$progressed` net).

There is exactly one path from any folder to the tree root, so a folder outside the shared subtree
can never arrive at `$root` on the way up.

```
root
├── Photos        <- share->folder_id
│   └── 2025
│       └── ski        ski -> 2025 -> Photos   allowed
└── Documents          Documents -> root -> NULL   4042
```

This is the **only** change to `FolderControl`: one method, no column, no write. The same walk
produces the breadcrumb `path`, so it is work the response needs anyway, not an extra query.

```php
// PublicShareApi::GetFolder()
$share = ShareControl::ResolveFromUuid((string)$params["uuid"]);
if($share->TargetType() !== "folder") ErrorCodes::Instance()->ThrowException(4042);

// key_id clamp is free here and means a visitor can't even name another key's folder
$folder = FolderControl::GetRowWhere([
    "uuid"   => (string)$params["folder_uuid"],
    "key_id" => $share->key_id,
]);
if($folder === null) ErrorCodes::Instance()->ThrowException(4042);

$root = new Folder($share->folder_id);
$path = FolderControl::PathWithin($folder, $root);
if($path === null) ErrorCodes::Instance()->ThrowException(4042);   // outside the share
```

`GetRowWhere()`, not `new Folder($id)` — the constructor throws `MagratheaModelException` on a miss
and we want our own `4042`. Same reason `FolderControl::GetForKey()`'s docblock already gives.
`GetForKeyByUuid()` isn't usable here: it takes a `Key`, and a public route has none.

`4042` covers "no such folder" and "outside this share" alike — the 404-not-403 rule the rest of
the API follows.

`FolderApi::FolderView()` is **not** changed.

### 4.5 `api.php`

```php
private function AddShare() {
    $api = new ShareApi();
    $this->Add("POST",   "shares",      $api, "Create", self::AUTHENTICATED);
    $this->Add("GET",    "shares",      $api, "GetAll", self::AUTHENTICATED, "GET: file_uuid=?, folder_uuid=?");
    $this->Add("DELETE", "share/:uuid", $api, "Delete", self::AUTHENTICATED);

    $public = new PublicShareApi();
    $this->Add("GET", "shared/:uuid",                     $public, "Get",       self::OPEN);
    $this->Add("GET", "shared/:uuid/folder/:folder_uuid", $public, "GetFolder", self::OPEN);
}
```

Called from `Initialize()` after `AddFile()`.

---

## 5. Lifecycle cleanup — the part most likely to be missed

Every path that deletes a file, folder or key must delete its shares **first** (§1.4). All five
call sites, from grepping `->Delete()` across `api/src/`:

| # | Site | Fix |
|---|---|---|
| 1 | `FileControl::DeleteFileAndStorage()` (`FileControl.php:53`) | `ShareControl::DeleteForFile((int)$file->id);` before `$file->Delete()`. Also covers `DuplicatesAdmin::DeleteSelected()`, which routes through `FileControl::Delete()`. |
| 2 | `FolderApi::Delete()` (`FolderApi.php:48`) | `ShareControl::DeleteForFolder((int)$folder->id);` before `$folder->Delete()`. A folder can be empty *and* shared. |
| 3 | `KeyControl::DeleteKeyNow()` | `ShareControl::DeleteForKey((int)$key->id);` as the **first** step, before files and folders. |
| 4 | `BrowserAdmin::DeleteFolder()` (`BrowserAdmin.php:61`) | same as #2. |
| 5 | `BrowserAdmin::PurgeOrphan()` (`BrowserAdmin.php:87`) | raw `$file->Delete()`, bypasses `FileControl` entirely → needs `ShareControl::DeleteForFile()`. |

`ScheduledDeletionControl::RunDueDeletions()` calls `DeleteKeyNow()`, so #3 covers cron.

All three helpers are a single unconditional `DELETE FROM shares WHERE <fk> = ?`.

**Why order matters:** `FileControl::DeleteFileAndStorage()` deletes the storage object first and
the DB row last. An FK failure on `$file->Delete()` leaves a `files` row whose bytes are already
gone — the orphan state `BrowserAdmin::OrphanCheck()` exists to hunt down. In `DeleteKeyNow()` the
share delete must also precede `removeDirectory($key->StorageDir())`, which is already sequenced
before `$key->Delete()`.

`AssertEmpty()` already blocks deleting a non-empty folder, so a subfolder inside a shared subtree
can only be deleted once empty — no special handling needed, but test 18 pins the behaviour.

---

## 6. Error code

One line for `api/src/error-manager/error_codes.conf`:

```ini
	4045 = "Share not found"
```

→ HTTP 404 (§1.7). `GET /error-codes` is already public and cache-backed, so the app picks it up.

---

## 7. Admin panel

`api/src/features/Share/ShareAdmin.php`, following the `KeyAdmin` pattern (server-rendered views,
no AJAX). **Stays on int ids** — `plan_refactor.md` §0 deliberately left `/admin.php` there, so the
delete form posts `share->id`, not the uuid.

- `List()` — every share across every key: key name, target type + name, views, last viewed,
  created. Newest first.
- `Delete()` — POST handler calling `ShareControl::Delete()` with `AdminManager::Instance()->Log()`.

Register in `MagratheaExplorerAdmin::SetFeatures()` (`$this->features["share"] = new ShareAdmin();`)
and add to `BuildMenu()` after the browser/duplicates entries.

**CSRF:** the delete button is a raw `<form method="post">`, so it needs
`AdminCsrf::Instance()->GetToken()` in a `magrathea_csrf_token` hidden input, or the POST is
rejected before it reaches the handler.

---

## 8. App (`app/`)

### 8.1 New files

```
app/src/api/shares.js                  createShare / fetchShares / deleteShare
app/src/api/publicShare.js             fetchShare / fetchShareFolder (no Authorization header)
app/src/views/SharesView.vue           "Shared links"                     (§8.4)
app/src/views/SharedView.vue           the public page                    (§8.5)
app/src/components/ShareDialog.vue     per-item create/copy/delete modal  (§8.3)
app/src/components/SharedFileList.vue  read-only listing for the public page
```

`SharedFileList` is separate from `FileList` — the public page has no rename/delete/share actions
and no `@open-folder` semantics of the owner kind. The item shapes now match (§3.5), so only the
actions column differs.

### 8.2 Modified files

**`app/src/api/client.js`** — add `apiGetPublic(path)` that sets no `Authorization` header. It must
**not** reuse `request()`: that clears the stored key and fires `onUnauthorized` on a 401, which
would log a visitor out of their own Explorer session just for opening a share link in the same tab.

**`app/src/router/index.js`**

```js
{ path: '/shares', name: 'shares', component: SharesView },
{ path: '/s/:uuid/:folderUuid?', name: 'shared', component: SharedView, props: true,
  meta: { public: true } },
```

`beforeEach` gains `if (to.meta.public) return true` **before** the key check, or every share link
redirects to login. `/shares` stays authenticated and needs no `meta`.

`:folderUuid?` resolves to `''` (not `undefined`) when omitted — normalize with
`props.folderUuid || null`, the same gotcha the explorer route already handles for `:uuid?`.

**`app/src/components/AppHeader.vue`** — a `Shared links` router-link before the usage label. It is
the only entry point to §8.4.

**`app/src/components/FileList.vue`** — a `Share` action on folder and file rows, emitting `share`
with `('folder'|'file', item)`, placed before `Rename`. On file rows use `@click.prevent` — the row
is wrapped in an `<a href>`.

**`app/src/views/ExplorerView.vue`** — hold `shareTarget`, render `<ShareDialog>` when set, wire
`@share` from `FileList`.

### 8.3 `ShareDialog.vue`

- **Create link** → `POST /shares` with `file_uuid` or `folder_uuid` → the returned `url` in a
  readonly input with a **Copy** button (`navigator.clipboard.writeText`, `document.execCommand`
  fallback for non-secure-origin dev).
- Existing links for that target (`GET /shares?file_uuid=` / `?folder_uuid=`), each with view
  count, **Copy**, **Delete**.
- A link through to **Shared links**.

### 8.4 `SharesView.vue` — `/app/shares`

Reuses `AppHeader`. One `GET /shares` on mount. Table, newest first:

| Column | Content |
|---|---|
| Target | icon + `target_name`, with a `File` / `Folder` chip |
| Link | truncated `url` + **Copy** |
| Views | `views`, and `last_viewed_at` as a date, or `Never opened` |
| Created | `created_at` via the existing `formatDate` in `utils/format.js` |
| Actions | **Open** (new tab) · **Delete** |

- **Delete** uses the inline two-step confirm already in `FileList.vue` (`confirmDeleteKey` →
  `Delete? Yes / No`), not `window.confirm`. Label: *"Delete this link? The share page stops
  working. Direct file URLs already copied keep working."*
- Clicking the target name navigates into the explorer — folder share →
  `{ name: 'explorer', params: { uuid: folder_uuid } }`; file share → its containing folder.
- Empty state: *"You haven't shared anything yet. Use the Share action on any file or folder."*

### 8.5 `SharedView.vue` — the public page

Its own minimal header — **not** `AppHeader`, which calls `/key/usage` and renders a "Log out"
button. Nothing on this page may show the key name, the owner's identity, or a link back to `/app`.

- `type === 'file'` → single-file card: icon/thumbnail, name, size, **Download** → `file.url`.
- `type === 'folder'` → breadcrumbs from `path`, then `SharedFileList`. A subfolder click pushes
  `{ name: 'shared', params: { uuid, folderUuid: f.uuid } }`.
- Any failure → *"This link is not valid."*

### 8.6 Build

`./script/build.sh`, then **commit `dist/`** — it is tracked in git and served at `/app` via the
`api/src/app` symlink.

---

## 9. Migration

1. **`api/database/database.sql`** — append the `shares` table (§2) after `file_tags`.
2. **`api/database/migrations/1.3.0_shares.sql`** (new folder) — the same `CREATE TABLE`, for an
   instance already running 1.2.0. Note in the README that `database.sql` is for fresh installs and
   `migrations/` for upgrades.

One additive `CREATE TABLE`, nothing else: no `ALTER`, no backfill, no ordering requirement against
serving traffic. Rollback is `DROP TABLE shares;`.

> If sharing ships **before** the 1.2.0 cutover (which reloads `database.sql` and wipes the
> instance anyway), step 2 is dead weight — the table just rides along in `database.sql`.

---

## 10. Test checklist

Manual, against the docker-compose dev stack. No test harness exists in this repo today; say the
word and I'll plan PHPUnit coverage instead.

**Owner**
1. `POST /shares` with `folder_uuid` → 200, `url` resolves.
2. `POST /shares` with `file_uuid` → 200.
3. `POST /shares` with both → `4001`. With neither → `4001`.
4. `POST /shares` with another key's `file_uuid` → `4043` (not 403).
5. `GET /shares` returns only this key's shares.
6. `DELETE /share/:uuid` → gone from `GET /shares`; public `GET /shared/{uuid}` → `4045`.
7. `DELETE` against another key's share uuid → `4045`.

**Public**
8. `GET /shared/{uuid}` with **no** `Authorization` header → 200.
9. Response contains no `key_id`, no `views`, no key name, and **not the key's bearer `uuid`** —
   grep the raw JSON for both `access_keys.uuid` and `name`. This is the blueprint's "never give
   the API which it belongs".
10. Navigate two levels down → correct `path`, share root first.
11. A folder uuid from **outside** the shared subtree → `4042`.
12. A folder uuid belonging to a **different key** → `4042` (the `key_id` clamp in §4.4).
13. Share whose key has `active = 0` → `4045`.
14. Share whose key has a pending scheduled deletion → `4045`.
15. The share uuid typed in **UPPERCASE** → still resolves (router lowercasing + `_ci` collation).
16. `views` increments on a successful load, not on an unknown uuid.

**Lifecycle**
17. `DELETE /file/:uuid` on a shared file → no FK error, share row gone.
18. Delete an empty shared folder → no FK error.
19. `DuplicatesAdmin` purge of a shared duplicate → no FK error.
20. `BrowserAdmin::PurgeOrphan` on a shared file → no FK error.
21. `KeyControl::DeleteKeyNow()` on a key with shares → no FK error, all shares gone, and
    `removeDirectory()` still succeeds.

**App**
22. Cold-load `/app/s/{uuid}` in a private window (no key in localStorage) → renders, no redirect.
23. Open a share link in a browser that **does** have a key stored → the key survives; `/app/folder/…`
    still works afterwards (proves §8.2's `apiGetPublic`).
24. Hard-refresh on `/app/s/{uuid}/{folderUuid}` → still renders (§1.5).
25. `/app/shares` with no shares → empty state, not a spinner or an error.
26. Delete from `/app/shares`, reload the public link in another tab → "This link is not valid."

---

## 11. Docs and version

Per `claude.md`, all three move together — **1.2.0 is the uuid refactor, so sharing is `1.3.0`**:

- `api/version` → `1.3.0`
- `api/changelog.md` → new `## 1.3.0` section
- `docs/openapi.yaml` → `info.version: "1.3.0"`, the five new paths, and `Share` / `SharedFile` /
  `SharedFolder` / `PublicShare` schemas

Also:
- `docs/skills.md` — a `## Sharing` section, a line in **Mental model**, and a note under **URL
  obfuscation** that a share link is a third kind of opaque identifier alongside the file token and
  the folder/file uuids. State that deleting a share does **not** invalidate already-issued file
  URLs, so an agent reading this doc doesn't tell a user otherwise.
- `README.md` — `shares` in the schema list (step 2) and `/app/s/{uuid}` + `/app/shares` in the
  directory layout.

---

## 12. Commit order

Each step leaves the tree working and is independently reviewable.

1. Schema: `shares` in `database.sql` and `migrations/1.3.0_shares.sql`.
2. `Share` / `ShareControl` / `Base` classes + error code.
3. `ShareApi` + `api.php` registration + `_inc.php` feature.
4. `PublicShareApi` + `FolderControl::PathWithin()`.
5. Lifecycle cleanup — all five sites in §5.
6. `ShareAdmin` + view + menu.
7. Vue: `client.js` / router / api modules.
8. Vue: `ShareDialog` + `FileList` action + `ExplorerView` wiring.
9. Vue: `SharesView` + `AppHeader` link.
10. Vue: `SharedView` + `SharedFileList`.
11. `./script/build.sh` + commit `dist/`.
12. Docs + version bump.

---

## 13. Out of scope

Expiry dates · reversible revoke · password-protected shares · download-all-as-zip ·
per-file download counters · uploading into a share · e-mailing a link ·
making a deleted link invalidate already-copied file URLs.

---

## 14. Risks

1. **`ShareBase` is a generated file.** Regenerating it through the admin drops
   `dbValues["uuid"] = "uuid"`, and every insert then fails against the `NOT NULL` column with no
   obvious cause. Mark the line with a comment, as `FolderBase::GetParent()` already does (§1.8).
2. **UUIDv7 embeds a creation timestamp.** Anyone holding a share link can read roughly when it was
   made. Accepted — the owner is the one handing the link out, and the same is already true of
   every key and file uuid in the system.
