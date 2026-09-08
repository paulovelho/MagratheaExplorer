# MagratheaExplorer — Implementation Plan

## Context

`blueprint.md` specified a generalized, storage-agnostic sibling to MagratheaImages3 (self-hosted file hosting: images/audio/video/pdf/other, obfuscated URLs, pluggable local/R2/S3 storage). Working through `plan.md` with the user resolved every open design question (schema, auth model, serving strategy, upload pipeline, caching). This plan turns that finalized design into a concrete, buildable scaffold on the MagratheaPHP2 framework (same framework/conventions as MagratheaImages3), grounded in the real framework source (not just its docs) and Images3's actual code patterns.

Two things resolved during this planning pass, beyond what `plan.md` already settled:
- The framework's declarative route auth (`BaseAuthorization`) is a pure boolean gate with no shared state to the controller — so an authenticated request does two cheap indexed `keys` lookups (gate + controller), not one. Kept the declarative gate anyway (cleaner route table) since both lookups are sub-millisecond and this project already decided no caching layer is needed.
- S3 `PutObject` can't actually persist an `X-Content-Type-Options: nosniff` response header (no such param exists) — `plan.md` assumed it could. The real protection is `Content-Disposition: attachment` (S3-native, forces download, never executes) for `file_type='other'` and SVG; `nosniff` becomes a CDN/vhost deploy note, not something the storage adapter can guarantee.

## Directory scaffold

```
/mnt/Rincewind/MagratheaExplorer/
├── composer.json
├── .gitignore
├── database/database.sql                 (magrathea system tables + keys/scheduled_deletions/folders/files/tags/file_tags)
├── docs/openapi.yaml, docs/skills.md
└── src/
    ├── version                            ("1.0.0")
    ├── configs/
    │   ├── magrathea.conf (gitignored) + magrathea.conf.sample
    │   ├── magrathea_objects.conf
    │   └── storage.conf (gitignored) + storage.conf.sample
    └── api/
        ├── _inc.php, index.php, admin.php, api.php, cron.php, .htaccess
        ├── error-manager/ErrorCodes.php, error_codes.conf
        ├── shared/ExplorerApiControl.php, SystemApi.php
        ├── admin/MagratheaExplorerAdmin.php, admin/Browser/BrowserAdmin.php (+ views)
        └── features/
            ├── Storage/  StorageAdapter.php, LocalStorageAdapter.php, S3StorageAdapter.php,
            │             StorageConfig.php, StorageFactory.php, StorageException.php
            ├── Key/      Base/{Key,ScheduledDeletion}{,Control}Base.php, Key.php, KeyControl.php,
            │             KeyAuthControl.php, KeyApi.php, ScheduledDeletion.php, ScheduledDeletionControl.php,
            │             KeyAdmin.php, ScheduledDeletionAdmin.php
            ├── Folder/   Base/Folder{,Control}Base.php, Folder.php, FolderControl.php, FolderApi.php
            └── File/     Base/{File,Tag}{,Control}Base.php, File.php, FileControl.php, FileApi.php,
                          Tag.php, TagControl.php, FileTagControl.php (plain class, no Base — join table),
                          UploadPipeline.php, MimeSniffer.php, TokenGenerator.php, ImageProcessor.php,
                          MetadataStripper.php, ResampleCalculator.php, ThumbnailGenerator.php,
                          MediaMetadataReader.php (getID3 wrapper)
```

`_inc.php` mirrors Images3 exactly: `MagratheaPHP::Instance()->AppPath(realpath(__DIR__))->AddCodeFolder("admin","admin/Browser","shared","error-manager")->AddFeature("Key","Folder","File","Storage")->Load()`. `index.php`/`admin.php`/`api.php` mirror Images3's real shape (URI-derived `$_GET` re-parse in `index.php`, `AdminManager::Instance()->Start(new MagratheaExplorerAdmin())` in `admin.php`).

## Data model — `magrathea_objects.conf` + hand-written Base classes

5 modeled tables (`keys`, `scheduled_deletions`, `folders`, `files`, `tags`) each get a `[section]` in `magrathea_objects.conf` (field-by-field `<field>_type`/`<field>_alias`) plus paired `belongs_to`/`has_many` entries in the single `[relations]` section: `File↔Key`, `File↔Folder`, `Folder↔Key`, `ScheduledDeletion↔Key`, `Folder↔Folder` (self-referential parent/children, needs a hand-added null guard on `GetParent()` since root folders have `parent_id = NULL`). `file_tags` (composite-PK join table) gets **no Model/Control** — `FileTagControl` is a plain static class doing direct `Query`/`PrepareAndExecute` calls, since `MagratheaModel` assumes a single PK.

No CLI codegen tool exists in this framework — Base classes are hand-written directly in the generated shape (framework docs call this normal for bootstrapping). Full real template for one (all others follow identically):

```php
namespace MagratheaExplorer\Key\Base;
use Magrathea2\iMagratheaModel;
use Magrathea2\MagratheaModel;

class KeyBase extends MagratheaModel implements iMagratheaModel {
    public $id, $uuid, $name, $uses, $usage_limit, $total_size, $usage_limit_mb, $expiration, $active;
    public $created_at, $updated_at;
    protected $autoload = null;

    public function __construct($id=0){
        $this->MagratheaStart();
        if(!empty($id)){ $pk=$this->dbPk; $this->$pk=$id; $this->GetById($id); }
    }
    public function MagratheaStart(){
        $this->dbTable = "keys"; $this->dbPk = "id";
        $this->dbValues["id"]="int"; $this->dbValues["uuid"]="uuid"; $this->dbValues["name"]="string";
        $this->dbValues["uses"]="int"; $this->dbValues["usage_limit"]="int";
        $this->dbValues["total_size"]="int"; $this->dbValues["usage_limit_mb"]="int";
        $this->dbValues["expiration"]="datetime"; $this->dbValues["active"]="boolean";
        $this->dbValues["created_at"]="datetime"; $this->dbValues["updated_at"]="datetime";
        $this->relations["properties"]["Folders"]=null; $this->relations["methods"]["Folders"]="GetFolders"; $this->relations["lazyload"]["Folders"]="true";
        $this->relations["properties"]["Files"]=null; $this->relations["methods"]["Files"]="GetFiles"; $this->relations["lazyload"]["Files"]="true";
        $this->relations["properties"]["ScheduledDeletions"]=null; $this->relations["methods"]["ScheduledDeletions"]="GetScheduledDeletions"; $this->relations["lazyload"]["ScheduledDeletions"]="true";
    }
    public function GetControl() { return new \MagratheaExplorer\Key\Base\KeyControlBase(); }
    public function GetFolders(){ if($this->relations["properties"]["Folders"]!=null) return $this->relations["properties"]["Folders"]; $pk=$this->dbPk; return $this->relations["properties"]["Folders"]=\MagratheaExplorer\Folder\Base\FolderControlBase::GetWhere(["key_id"=>$this->$pk]); }
    public function GetFiles(){ if($this->relations["properties"]["Files"]!=null) return $this->relations["properties"]["Files"]; $pk=$this->dbPk; return $this->relations["properties"]["Files"]=\MagratheaExplorer\File\Base\FileControlBase::GetWhere(["key_id"=>$this->$pk]); }
    public function GetScheduledDeletions(){ if($this->relations["properties"]["ScheduledDeletions"]!=null) return $this->relations["properties"]["ScheduledDeletions"]; $pk=$this->dbPk; return $this->relations["properties"]["ScheduledDeletions"]=\MagratheaExplorer\Key\Base\ScheduledDeletionControlBase::GetWhere(["key_id"=>$this->$pk]); }
}
```
`KeyControlBase` (and its 4 siblings) are the trivial 3-property shape: `protected static $modelNamespace/$modelName/$dbTable`.

`keys.uuid` uses the framework's built-in `uuid` field type (auto-fills via `Uuid::V7()` on `Insert()` if unset) — replaces Images3's hand-rolled key-generation loop entirely, and directly satisfies blueprint's "a key has a unique UUID" requirement. Collision safety comes from `random_bytes` entropy + the DB `UNIQUE` constraint (already in the schema).

`database/database.sql` = the standard `_magrathea_*` framework tables (copied unchanged from Images3) + the 6 project tables exactly as finalized in `plan.md` §4/§5.

## `Storage` feature (build first — everything else depends on it)

```php
interface StorageAdapter {
    public function put(string $path, string $localTmpFile, array $meta): void;
    public function delete(string $path): void;
    public function url(string $path): string;
    public function exists(string $path): bool;
}
```
`LocalStorageAdapter` — writes into a configured folder a vhost serves directly (confirmed available); `put()` handles mkdir-if-missing + move. `S3StorageAdapter` — one class for both R2 and AWS S3 via `async-aws/s3`, parametrized by endpoint/region/pathStyle/bucket/credentials (R2 = S3 with a Cloudflare endpoint + `region:auto`); sets `ContentType`/`ContentDisposition`/`CacheControl` at `PutObject` time (the only chance to set them, since there's no PHP in the read path). `StorageConfig` mirrors Images3's `R2Config` pattern exactly: env-sectioned INI (`storage.conf`), absent-file-is-cleanly-disabled. `StorageFactory::Instance()->Get()` picks the driver from `StorageConfig::GetDriver()` (`local`|`s3`) and caches the instance for the request.

`storage_path` is flat for both drivers: `"{token}.{ext}"` — no sharding, so `url()` is the same shape regardless of active driver, and switching drivers later needs no data migration (URLs are computed from `storage_path` at read time, never stored).

Deploy note (not code): a Caddy/Apache snippet forcing `Content-Disposition: attachment` for `.svg` and non-media extensions under local storage, plus disabling script execution in that vhost block — local storage has no per-object header mechanism the way S3 does.

## `Key` feature

`KeyControl::Create()` inserts the key (uuid auto-fills) and auto-creates its root folder. `ResolveFromBearer($ctrl)` reads the `Authorization` header, looks up by `uuid`, and calls `AssertUsable()` (checks `active`, `expiration`, and — importantly — a pending `scheduled_deletions` row, which makes a key read-only even before its 14 days are up, per `plan.md`'s "share one code path" note). `AssertQuota($key, $incomingSize)` checks `uses < usage_limit` and `total_size + incoming <= usage_limit_mb*1MB` (both nullable = unlimited) before any upload processing work happens. `IncrementUses()`/`AdjustSize()` use atomic `UPDATE ... SET x = x + ?` (via `PrepareAndExecute`) to avoid a lost-update race under concurrent uploads, no read-modify-write.

Key creation has **no route in the public API at all** — it only happens through `KeyAdmin`'s admin-panel form (CSRF-protected automatically by the framework), matching the earlier decision to drop the shared-secret creation endpoint entirely. `KeyAdmin` also gets a clearly-labeled `ForceDeleteNow()` admin action (bypasses the 14-day wait) for the "manageable" cleanup goal.

`shared/ExplorerApiControl.php` is the common base every business controller extends instead of `MagratheaApiControl` directly — gives every controller a `GetRequestKey()` that resolves the bearer key (see Context above re: the double-lookup tradeoff).

Public routes (all bearer-authenticated, self-service only): `GET /key`, `GET /key/usage`, `DELETE /key` (schedules deletion), `POST /key/cancel-deletion`.

## `Folder` feature

`FolderControl::Create()` enforces unique `(key_id, parent_id, name)`. `ComputeSize()` does the on-demand recursive-subtree `SUM(size)` decided in `plan.md` §5 (MariaDB `WITH RECURSIVE`). `AssertEmpty()` blocks deleting a non-empty folder; root folders (`parent_id=NULL`) are never deletable, checked in the controller before `AssertEmpty` even runs. Every `FolderApi` method scopes queries by the resolved key's `id` — a folder belonging to another key 404s (not 403, to avoid confirming the ID exists).

Routes: `GET/POST /folders`, `GET/PUT/DELETE /folder/:id`, `GET /folder/:id/size`.

## `File` feature — upload pipeline

`UploadPipeline::Handle()` steps: (1) size check against `ConfigApp.max_upload_size`; (2) `AssertQuota()` before any processing; (3) `MimeSniffer::Detect()` (finfo-based, never trusts client-supplied type/extension, plus a small denylist of obviously dangerous types even under `file_type='other'`); (4) dispatch by detected type — **images** get `MetadataStripper` (ported near-verbatim from Images3: JPEG APP1/APP13/COM removal + synthetic Orientation-only EXIF, PNG/WebP/GIF chunk stripping, SVG DOM cleanup) → GD re-encode → webp conversion (on by default, `no_convert` opts out, always skipped for SVG and animated GIF) → square thumbnail via `ResampleCalculator`'s ported crop-to-fill math; **audio/video** get `getID3`-based duration extraction only, no transcoding; **document/other** pass through untouched; (5) `TokenGenerator` mints an independent random 21-char token for the file and, separately, for the thumbnail if one exists — never derived from each other; (6) `StorageAdapter->put()` with `Content-Disposition: attachment` forced for `file_type='other'`/SVG, `inline` otherwise; (7) `files` row written — `size` is the *processed/stored* byte count (post-webp-conversion), which is what counts against `usage_limit_mb`, thumbnail bytes excluded from quota; (8) `IncrementUses()` + `AdjustSize()`; (9) optional tag attachment.

Delete: ownership-checked (404 if not the caller's file), deletes from storage (file + thumbnail), detaches tags, deletes the row, `AdjustSize()` with a negative delta.

List/query directly matches blueprint's example queries: `GET /files?folder_id=X`, `GET /files?file_type=audio`, `GET /key/usage` (reads `keys.total_size` directly — no `SUM()`, per the precomputed-running-total decision). Tag filtering joins `file_tags`/`tags`. Responses include `url`/`thumbnail_url` computed via `StorageAdapter->url()` at read time, never persisted.

Routes: `POST /files`, `GET /files`, `GET/PUT/DELETE /file/:id`, `POST /file/:id/tags`, `DELETE /file/:id/tags/:tag`.

## Error handling

`ErrorCodes` (project file, copied from Images3's shape — a `Singleton` wrapping `Magrathea2\ConfigFile` over a local `error_codes.conf`) + `error_codes.conf` with codes for: bad/missing auth, key inactive/expired/pending-deletion, uses-limit/size-limit reached, key/folder/file/tag not found, folder name collision, folder not empty, deletion already pending / nothing to cancel, unsupported mime, storage backend error, image processing failure, token-generation exhaustion. Same `intdiv(code,10)`-derives-HTTP-status convention as Images3.

## Admin

`MagratheaExplorerAdmin` registers `KeyAdmin` (key CRUD + the only key-creation path + force-delete), `ScheduledDeletionAdmin` (pending/cancelled/executed list, cancel, force-execute), and `BrowserAdmin` (folder/file browser spanning all keys, orphan cleanup — lives at the `admin/` level like Images3's `MediaManager` since it's not scoped to one feature). Same `Magrathea2\Admin` subsystem Images3 uses, confirmed sufficient since it's developer-only.

## Scheduled-deletion cron

`src/api/cron.php`, mirrors Images3's `backup.php` CLI-entrypoint shape (`chdir(__DIR__)` first, `getopt` for `--run`/`--dry-run`/`--verbose`). Sweeps `scheduled_deletions` rows past `execute_at` with `cancelled_at IS NULL`, calls `KeyControl::DeleteKeyNow()` per row (storage + DB cleanup for every file, then the key), one try/catch per row so one failure doesn't kill the run.

## Dependencies

`composer.json` requires `platypustechnology/magratheaphp2` (plain version constraint, no path repo, matching Images3), `async-aws/s3` + `symfony/http-client` + `nyholm/psr7`, `james-heinrich/getid3`. No Ghostscript/ffmpeg. `.gitignore` copies Images3's list (`medias`→`storage`, `r2.conf`→`storage.conf`).

## Build & verification order

No existing test suite (brand-new project) — each stage gets a manual smoke test before moving to the next:

1. **Scaffold**: directories, `composer.json`, `composer install`, `database.sql` applied. Check: `php -l` on every entry file.
2. **Data layer**: all 5 tables' Base+hand-written classes, schema live. Check: throwaway script — insert a `Key`, confirm `uuid` auto-fills; insert a `Folder`; confirm `GetById`/relations round-trip.
3. **Storage (local only)**: interface + `LocalStorageAdapter` + config + factory. Check: `put()` a file, serve it via `php -S` pointed at the storage folder, curl it back, `delete()` it.
4. **Key feature + entry-point wiring**: `_inc.php`/`index.php`/`admin.php`/`api.php`, `KeyControl`, `KeyAuthControl`, minimal `KeyAdmin`. Check: create a key through the real admin UI (the only creation path), `curl -H "Authorization: Bearer <uuid>"` against `/key` and `/key/usage`; confirm a bad header 401s.
5. **Folder feature**: Check: create a folder via curl, list folders, confirm root folder auto-exists, confirm duplicate-name rejection.
6. **File feature**: Check: `curl -F file=@test.jpg` upload, confirm object + thumbnail land in storage with `{token}.ext` naming, confirm `files` row fields, curl the returned URLs directly and confirm they render; repeat with `no_convert=1` and an SVG; repeat with an oversized file to confirm the size-limit error.
7. **Tags**: Check: attach, filter by `?tag=`, detach.
8. **ScheduledDeletion + cron.php**: Check: `DELETE /key` → row created with `execute_at` ≈ now+14d, subsequent upload now blocked; cancel restores it; re-schedule, back-date `execute_at` manually, run `cron.php --run --verbose`, confirm key/files/storage objects are all gone.
9. **S3StorageAdapter** (against real R2/S3 or local MinIO): flip `storage.conf`'s driver, re-run steps 6 and 8 unchanged to confirm adapter parity.
10. **Admin polish**: full `BrowserAdmin`, `ScheduledDeletionAdmin` UI, `AppConfig` entries for `max_upload_size`/`thumbnail_size`/`webp_conversion_default`. Check: click through the whole admin UI including force-delete.
11. **Docs**: `docs/openapi.yaml` + `docs/skills.md`, `src/version` → `1.0.0`, confirm `magrathea.conf`/`storage.conf` gitignored with `.sample` files committed.

## Critical files

- `src/configs/magrathea_objects.conf`
- `src/api/features/Storage/StorageAdapter.php`
- `src/api/shared/ExplorerApiControl.php`
- `src/api/features/File/UploadPipeline.php`
- `src/api/MagratheaExplorerApi.php`
- `database/database.sql`
