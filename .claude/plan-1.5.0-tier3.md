# 1.5.0 — Turbopod Tier 3: implementation plan

Covers §5.1–§5.4 of `.claude/blueprint-1.5.0.md`: direct uploads, URL rotation, recursive
folder delete, batch lookup. §5.5 (JSON body detection) moved into Tier 2's Phase 1
(`.claude/plan-1.5.0-tier2.md` §2.1), because Tier 2 needs it first.

It works like `.claude/plan-1.5.0-tier1.md` and the Tier 2 plan: the work is split into phases,
each phase leaves the branch working with its own docs and tests, and "Decided" and "As built"
notes get added as the work goes.

Written against the working tree on `version/1.5`. **It ships in 1.5.0** with Tier 1 and Tier 2
(decided, Tier 2 §1), and can be cut at release time if it's late (§1).

**Status (2026-11-19):** Phases 1 to 4 done (§2.1, §3.1, §5.1, §6.1), with docs and tests;
Phase 5's doc parts done (§8.1), its release parts (git, the shared release with Tier 1 Phase 8
and Tier 2 Phase 6) left. Q7 and Q8 (§12) built as recommended: no CDN purge, confirmation by id.
**Nothing DB-backed has run** (no DB here): the migration, `models:verify` and §9 are the
user's; §8.1 lists what's unverified, riskiest first. 776 tests, all green.

**From Tier 2, needed first:**
- Phase 1: JSON bodies, `files.size` as bigint, and the error-code base entries and allocation
  (Tier 2 §2.5).
- Phase 2: `ApiTime`, for `expires_at`.

---

## 0. Findings: the code vs the blueprint's "Today" lines

### 0.1 The router lowercases the whole path

`MagratheaApi::Run()` (`:481`) runs `strtolower()` on control, action and params before it
matches a route, so every path parameter arrives in lowercase. That's harmless today, because
uuids are lowercase hex. But any token that goes into a path must be lowercase-only, like
§5.1's `upload_url` (Phase 3). TokenGenerator's base62 alphabet can't be used there.

### 0.2 Folders

- `DELETE /folder/:uuid` refuses a folder that isn't empty (4003) and the root folder (4005).
- social deletes a user's tree itself, file by file and then folder by folder
  (`social/api/src/core/UserDeleter.php:170-185`). It would use §5.3 too.
- `FolderControl::ComputeSize()` already uses `WITH RECURSIVE`, so §5.3's subtree query has
  precedent in the code.
- Public folder shares list a folder's children with no filter (`PublicShareApi.php:92-93`), so
  a folder pending deletion would still show up there.

### 0.3 Storage: no copy or move, and objects are cached as immutable

- `StorageAdapter` has `put`, `fetch`, `delete`, `url`, `exists` and `removeDirectory`.
  Rotation (§5.2) changes the object key (`{storage_uuid}/{token}.{ext}`), so the adapter needs
  a `copy()`: `CopyObject` on S3 (done on the server), `copy()` on local storage.
- S3 objects are stored with `public, max-age=31536000, immutable` (`S3StorageAdapter.php:50`).
  An R2 custom domain sits behind Cloudflare's cache, so after a rotation the old URL can keep
  being served from the edge for up to a year. The blueprint's "CDN caches may keep serving the
  old URL for a while" understates it: **without a cache purge, rotating doesn't revoke a URL
  that was ever fetched through the CDN.** See Phase 2.
- social stores storage paths it derives from URLs (`ExplorerClient::StoragePath()`), so after a
  rotation its stored path is stale. Rotation is opt-in, so nothing breaks unless someone calls
  it.
- Deletes that are only "best effort" leave orphan objects behind when they fail: JobRunner's
  cleanup of previous renditions and FileProcessing's after a regenerate. Nothing retries them
  (§4 fixes that).

### 0.4 The owner view runs extra queries per file (N+1)

`FileResponse::Owner()` runs one tag query per file (`:53`), plus one folder lookup per file
when the caller didn't pass the folder's uuid (`:33`). `GET /files` passes it. A batch lookup
across folders (§5.4) wouldn't, so 100 uuids would cost 200 extra queries. Both need preloading.

### 0.5 Error codes

All of them are allocated in Tier 2 §2.5, so the two plans never pick the same number. This
plan's:

| Code | HTTP | Message | Phase |
|---|---|---|---|
| 4046 | 404 | Upload token not found | 3 |
| 4092 | 409 | Upload token already used | 3 |
| 4093 | 409 | File changed during rotation, retry | 2 |
| 4101 | 410 | Upload token expired | 3 |

It also reuses 40013 (invalid field value), 4002 (too large) and 4151 (file type).

---

## 1. Release

- **It ships in 1.5.0,** with Tier 1 and Tier 2 (decided, Tier 2 §1).
- **It can be cut at release time.** It needs Tier 2's Phases 1 and 2 and none of Tier 2's
  tables. If it's late, the release goes without it, and `1.5.0_tier3.sql` stays out.
- **Migration:** `api/database/migrations/1.5.0_tier3.sql`, run after `1.5.0_tier1.sql` and
  `1.5.0_tier2.sql`, appended to phase by phase and mirrored in `database.sql`.
- **Ordering:** Phase 3 (direct uploads) lands after Tier 2's Phases 4 and 5. All three touch the
  head of the upload path (Tier 2 §1).

---

## 2. Phase 1: batch lookup (§5.4)   [S] ✅

Do this phase first: it's the one Turbopod can use right away, to poll Tier 1's processing
status for many files at once.

- **`POST /files/lookup`** takes `uuids`: a JSON array, or form `uuids[]`. 1–100 after removing
  duplicates. More than 100 → 40013 "uuids: at most 100".
- **Unknown or malformed uuids are left out.** A malformed one can't belong to the key either,
  so it's simply unknown.
- **One query for the files:** `WHERE key_id = ? AND uuid IN (…)`, escaped. Renditions come
  from `GetForFiles()`.
- **Tags and folder uuids are preloaded** (§0.4) with `FileTagControl::GetTagsForFiles()` and
  `FolderControl::UuidsFor()`: 4 queries for 100 files, instead of 202. `GET /files` gets the
  tag preload too, since it has the same N+1.
- **It returns a list in the request's order,** without the unknown ones. Same shape as
  `GET /files`.
- **Route:** `POST files/lookup` has 2 segments, so it can't collide with `POST files`.
- **Docs:** openapi; the skill guide (the TS polling helper uses it); changelog **new:**.
- **Tests:** ownership (another key's uuid is left out), 100 vs 101, duplicates, order,
  malformed uuids, the number of queries.


### 2.1 As built (2026-11-19) ✅

- **Code:** `POST files/lookup` → `FileApi::Lookup()` → `LookupFor(Key, body)` (static, testable),
  `ParseLookupUuids()`, `OwnerViews()` (shared with `GET /files`, which gets the tag preload);
  `FileTagControl::GetTagNamesForFiles()`, `FolderControl::UuidsFor()`, `FileResponse::Owner()`'s
  `$tags`.
- **The 100 cap counts every distinct item, malformed ones included** (a non-string counts as
  its own item): 101 junk items are a 40013, not a silent empty list. Uuids are trimmed and
  lowercased; only `[0-9a-f-]` uuids reach the query (escaped anyway).
- **Tests:** `File/LookupUuidsTest` (parsing) and `File/LookupTest`, which checks the 4 queries
  for 100 files against `tests/Support/FakeDatabase.php`, new: a `Database::Instance()` stand-in
  that answers from canned handlers and records every statement (DatabaseSimulate returns no
  rows). `tests/Support/FakeStorage.php` (in-memory adapter with failure injection) and
  `StorageFactory::SetAdapterForTests()` came with it. Both serve every phase below.
- **Docs:** openapi (`/files/lookup`, `LookupInput`), skill guide (Workflow 3, 40013, the TS
  `waitForManyRenditions()` batch poller; `call()` takes `URLSearchParams` for `uuids[]`),
  changelog **new:**.

---

## 3. Phase 2: URL rotation (§5.2)   [M] ✅

**Route:** `POST /file/:uuid/rotate` returns the File with its new `url`, rendition URLs and
`thumbnail_url`.

**Storage:** a new `StorageAdapter::copy(from, to)`.
- S3: `CopyObject`, done on the server. `MetadataDirective=COPY` keeps the Content-Type,
  Content-Disposition and Cache-Control. A single CopyObject stops at 5 GB on S3; R2's ceiling
  needs checking. A multipart copy isn't built.
- Local: `copy()`. The old file stays until the commit.

**Flow:**
1. Read the file and its renditions, and mint a new token for each one (TokenGenerator).
2. Copy every object to `{storage_uuid}/{new token}.{ext}`. If any copy fails, delete the
   copies and answer 5001. Nothing has changed.
3. In a transaction:
   - lock the file row (`FOR UPDATE`, the same lock JobRunner's commit takes, Tier 1 §7.12) and
     read the renditions again;
   - if they changed since step 1 (a job committed in between) → roll back, delete the copies,
     **4093** (409);
   - otherwise update `files.token`/`storage_path` and each rendition's token and path.
4. After the commit, delete the old objects. A failed delete goes into the garbage queue (§4)
   for cron to retry, and the response still succeeds, since the database points at the new
   objects.

**Pending processing doesn't block a rotation.** A queued job's rendition tokens were never
public (renditions are null while pending), so its renditions come out under URLs nobody has
seen. When the job commits, it deletes the "previous" renditions it reads under the lock. After
a rotation, those are the rotated ones, which it replaces anyway.

**Shares point at file ids,** so a share page shows the new URLs straight away.

**The CDN (§0.3).** With `immutable, max-age=31536000`, an old URL that was fetched through
Cloudflare keeps being served from the edge until the cache evicts it or someone purges it. The
docs say so plainly. **Open (Q7):** an optional Cloudflare purge after step 4 (zone id and API
token in `storage.conf`, `POST /zones/:id/purge_cache` with the old URLs). Recommended as a
follow-up, not in this phase.

**Consumers that store URLs or paths** (Turbopod's database, social's paths) have to update them
from the response.

**Idempotency:** rotating mints new URLs every time, so Idempotency-Key isn't read here. A
retried rotation just rotates again, which is harmless.

**Docs:** openapi; skill guide Workflow 5 (with the CDN warning); changelog **new:**.

**Tests** (integration, local driver):
- the old paths are gone, and the new ones serve the same bytes (sha256), renditions included;
- a job commit forced in between → 4093, nothing changed;
- a copy failure → nothing changed;
- a delete failure → a garbage row.

---

## 4. The storage garbage queue (built in Phase 2)

Rotation's old objects use it, and so do the deletes that are only best effort today (§0.3).

```sql
CREATE TABLE `storage_garbage` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`storage_path` varchar(255) NOT NULL,
	`attempts` int(11) NOT NULL DEFAULT 0,
	`last_error` varchar(1000) NULL,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

- `StorageGarbage::Delete(paths)` tries each delete and queues the ones that fail.
- `--process` housekeeping retries up to 100 per run (§7). A path that has failed 20 times stays
  in the queue and shows up on the admin's Processing page.
- JobRunner's deletion of previous renditions and FileProcessing's cleanup after a regenerate
  switch to it too, so the orphans they can leave behind today get retried.


### 3.1 As built, Phase 2 (2026-11-19) ✅

- **Code:** `StorageAdapter::copy()` (S3: CopyObject, `MetadataDirective=COPY`, the source key
  URL-encoded per segment, `->resolve()` so errors surface inside the try; local: `copy()` with
  mkdir, a partial copy removed). `File/FileRotation` (`Rotate()`, `ReplaceToken()`,
  `Fingerprint()`), `FileApi::Rotate()`, route `POST file/:uuid/rotate`, 4093.
  `Storage/StorageGarbage` (`Delete()`, `Queue()`, `Retry()`, `Counts()`, `Stuck()`).
- **R2's copy ceiling, checked:** R2's single-request limit (5 GiB) covers copies, as on S3.
  No multipart copy: a bigger file's rotation is a 5001 that changes nothing (deploy.md says so).
- **The lock compares a fingerprint:** the file's token and path plus every rendition's id,
  token and path. So a concurrent *rotation* of the same file is caught too (its committed
  token differs): 4093, not two half-rotations. A file deleted in between → 4043, copies removed.
- **New paths keep the directory and the extension** of the old ones (everything after the
  token's first dot: tokens are `[0-9A-Za-z]`), rather than being rebuilt from the key.
- **`storage_garbage.storage_path` is UNIQUE** (not in §4's schema): a path queued twice keeps
  one row and its attempts (`ON DUPLICATE KEY UPDATE last_error`). Index `(attempts,
  updated_at)` for `Retry()`, which takes the least recently tried first, so paths that keep
  failing don't starve the rest.
- **Added: `Retry()` never deletes a path a row points at again.** Checked by token (UNIQUE and
  indexed in `files` and `file_renditions`) plus the path; such a row is dropped from the queue
  and logged. Cheap insurance against a queued path that a later write reused.
- **Wider than §4:** every caller of `JobRunner::DeleteObjects()` (removed) goes through the
  queue: the job's own objects when it loses or fails, the previous renditions, the regenerate
  with an empty profile, the Browser's orphan purge. And **UploadPipeline's cleanup after a
  failed insert** (only logged before). If the queue can't be written either (the DB is down,
  which may be why the insert failed), it's logged as before.
- **Admin → Processing** gets a "Storage deletes to retry" card: the count, and the stuck paths
  (20 failures) with their last error.
- **Known race, not handled:** a `DELETE /file` that reads the paths before a rotation commits
  and deletes the row after it leaves the rotation's copies orphaned (objects without a row;
  the Browser's orphan check looks for the opposite). Needs both on the same file at once.
- **1.4.x rollback:** `files.thumbnail_token`/`thumbnail_path` aren't rotated (unused since
  1.5.0). A rollback after rotations loses those files' old thumbnails; originals are fine.
- **Tests:** `File/FileRotationTest` (the happy path with sha-equal bytes, a job commit in
  between → 4093 with nothing changed and the copies gone, a copy failure, a failed old delete →
  a garbage row, a file deleted in between → 4043), `Storage/StorageGarbageTest`, two
  `LocalStorageAdapterTest` cases.
- **Docs:** openapi (`/file/{uuid}/rotate`, with the CDN warning in §14's words), skill guide
  (Workflows 4 and 5, 4093), deploy.md ("URL rotation and the CDN": how to purge by hand on
  Cloudflare and CloudFront; the cron paragraph), changelog (**new:**, **improvement:**,
  **db:**). Migration `1.5.0_tier3.sql` started, mirrored in `database.sql`.

---

## 5. Phase 3: direct uploads (§5.1)   [M] ✅

**Schema:**

```sql
CREATE TABLE `upload_tokens` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`uuid` char(36) NOT NULL UNIQUE COMMENT 'Public id: GET /upload-tokens/:uuid',
	`token_hash` char(64) NOT NULL UNIQUE COMMENT 'sha256 of the token in upload_url',
	`key_id` int(11) NOT NULL,
	`folder_id` int(11) NOT NULL,
	`max_size` bigint(20) NOT NULL,
	`file_types` varchar(64) NULL COMMENT 'Comma-separated file_type values. NULL = any.',
	`verbatim` tinyint(1) NULL,
	`no_convert` tinyint(1) NULL,
	`tags` varchar(1000) NULL,
	`status` enum('pending','uploading','used','failed') NOT NULL DEFAULT 'pending',
	`file_id` int(11) NULL,
	`error` varchar(1000) NULL,
	`expires_at` DATETIME NOT NULL,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	KEY `idx_upload_tokens_expiry` (`status`, `expires_at`),
	FOREIGN KEY (`key_id`) REFERENCES `access_keys`(`id`)
);
```

The only FK is on `key_id`, and `DeleteKeyNow()` deletes the key's tokens first. `folder_id` is
checked when the upload arrives, and `file_id` is informational. FKs on either would block the
folder and file deletes.

**`POST /upload-tokens`** (key auth):
- **Fields:**
  - `folder_uuid` (default: the root);
  - `max_size` in bytes (at most the key's effective limit, which is also the default);
  - `file_types` (a subset of image, audio, video, document, other; default: any);
  - `expires_in` in seconds (60–900, default 900);
  - `verbatim`, `no_convert`, `tags`.
- **The key has to be able to upload right now:** `AssertCanUpload(0)` checks active, not
  expired, no pending deletion, and the upload count.
- **Response:** `{id, token, upload_url, expires_at}`, with `expires_at` in UTC (Tier 2's
  `ApiTime`).
- **The token** is 32 random bytes as 64 lowercase hex characters (the router lowercases paths,
  §0.1). Only its sha256 is stored.
- `upload_url` is `{app_url}/api/v1/upload/{token}`.
- **Deviation:** the backend confirms with `GET /upload-tokens/:id`, by the non-secret id, not
  by the token. That keeps the token out of the backend's request logs. (The blueprint has
  `GET /upload-tokens/{token}`.) **Open (Q8), minor.**

**`POST /upload/:token`** (no auth; holding the token is the credential), multipart `file`:
1. Look up `sha256(token)`. Unknown → 4046 (404).
2. Claim the token:
   `UPDATE … SET status = 'uploading' WHERE id = ? AND status = 'pending' AND expires_at > NOW()`.
   If no row changed, read it again: used or uploading → 4092 (409); expired → 4101 (410).
3. Check the upload against the token:
   - its size must be within the token's `max_size` (4002, with the token's limit);
   - its sniffed file type must be one the token allows (4151, with "not allowed by this upload
     token").
4. Run `UploadPipeline::Handle()` with the token's options. The token becomes `used`, with its
   `file_id`.
5. **Failures:**
   - a 4xx refusal → `failed`, with the error's code and message. The token is single use: the
     consumer's backend reads why and mints a new one;
   - a 5xx (storage, database) → back to `pending` if it hasn't expired, so the browser can
     retry.
- **Response:** the File (owner view), so the browser can show the thumbnail straight away. The
  backend still confirms through GET: the blueprint's "never trust the browser's uuid" is about
  the backend, not about hiding the File from its uploader.
- **Idempotency-Key isn't read here:** the single-use token already makes the upload idempotent
  (Tier 2 §5.6).

**`GET /upload-tokens/:id`** (key auth, only the key's own tokens) returns
`{id, status, expires_at, file, error}`:
- `file` is a File or null;
- `error` is `{code, message}` or null;
- a `pending` token past its `expires_at` reads `expired`.

**Limits to document:**
- **Expiry is checked when the upload *arrives*.** PHP only sees a request once the whole body is
  in, so a 150 MB upload that starts at minute 14 of 15 is refused at minute 16. The consumer
  picks the window, up to 15 minutes. There's no grace period.
- **The whole body arrives before any check.** A valid token lets a browser push up to
  `post_max_size`. That's the same as any upload.
- **CORS:** a multipart POST with no custom headers is a simple request, so there's no preflight.
  `AllowAll()`'s `*` is enough, since no cookies are involved.
- **Cloudflare's proxy caps request bodies at 100 MB** on Free and Pro. A 150 MB print file
  uploaded from a browser through a proxied API host gets a 413 from the edge, before PHP sees
  it. Multipart `POST /files` has the same ceiling (Tier 1 §5.5). For the case the blueprint
  wrote §5.1 for, either the API host isn't proxied (DNS only), or direct uploads stay under
  100 MB. See §14 and `deploy.md`.
- **The alternative, not planned:** presigned R2 PUTs would skip both PHP and the proxy. But they
  only work on the S3 driver, they leave staging objects that are publicly reachable on an R2
  custom domain until finalized, and they need a finalize step that fetches the object back.
  That isn't the blueprint's shape.

**Housekeeping:** tokens more than 7 days past their expiry are purged in `--process` (§7).

**Docs:** openapi (3 routes; the upload route has no security); skill guide "Workflow 9: browser
uploads" (the backend mints a token, the browser posts the file, the backend confirms), plus the
error table; changelog **new:** and **db:**.

**Tests:**
- a claim race: two processes, one wins;
- an expired token, a used token;
- a disallowed type → `failed`, with the reason;
- a 5xx → `pending` again;
- a file above the token's `max_size`;
- another key's token id → 404;
- the browser's response;
- the purge.


### 5.1 As built (2026-11-19) ✅

- **Code:** `File/UploadToken` (model), `UploadTokenControl` (`Mint()`, `GetByToken()`,
  `GetForKeyByUuid()`, `Claim()`, `Refusal()`, `MarkUsed()`, `MarkFailed()`, `Release()`,
  `Purge()`, `DeleteForKey()`), `UploadTokenApi` (`Create()`, `Get()`, `Upload()`, `Consume()`,
  `ParseCreate()`, `HttpStatus()`, `View()`). `UploadOptions::$allowedFileTypes`,
  `UploadPipeline::AssertAllowedType()` (right after the sniff) and `RefuseAbove()` (4002 against
  any limit). `ApiTime::FromUnix()`. Codes 4046, 4092, 4101. `DeleteKeyNow()` deletes the key's
  tokens. Q8 as recommended: confirmation by id.
- **Deviation: `expires_at` is an int (unix time, the DB clock),** not a DATETIME, for Tier 2's
  reason (`lease_until`, `window_start`): in a zone with DST, a local DATETIME makes tokens
  minted in the repeated hour valid an hour longer. Minted as `UNIX_TIMESTAMP() + expires_in`
  (read first), compared with `UNIX_TIMESTAMP()`: PHP's clock is never involved.
- **Deviation: `error_code` column** next to `error` (the message), instead of encoding both in
  one varchar.
- **The token becomes `used` inside the file's transaction** (`onStored` → `MarkUsed()`), so the
  file row and the token's state commit together. `MarkUsed()` throws if the token isn't held,
  which rolls the file back and removes its object.
- **Every settle is conditional on `uploading`,** and a shutdown function releases a token a
  PHP fatal left `uploading` (like `Idempotency::Run()`). A SIGKILL still leaves it `uploading`
  until the purge: documented in §14 below, not engineered around.
- **A request with no `file` part doesn't use the token up** (4001 before the claim): it isn't
  an upload. A `post_max_size` overflow is checked after the claim and does (4002, with the
  smaller of the token's and the key's limit).
- **Unknown fields on `POST /upload-tokens` are 40013** naming the field, as on
  `POST /provision/keys`: a typo silently ignored would mint a looser token than asked. Also
  40013: `max_size` outside 1..the key's effective limit, `expires_in` outside 60..900, unknown
  or empty `file_types`, a tag over 100 characters (the column), tags over 1000 bytes of JSON.
- **The refusal for a lost claim** (`Refusal()`): no row → 4046; pending and expired → 4101;
  used, uploading, failed, or pending and not expired (lost to a request that put it back) →
  4092, with a detail for `uploading` and `failed`.
- **`HttpStatus()`** copies the framework's private rule (SetStatus() first, 3/4/5-digit codes,
  DB errors and `mysqli_sql_exception` always 500): a 4xx uses the token up, anything else
  releases it.
- **Tests:** `File/UploadTokenTest` (29: the mint body, refusal codes, the HTTP mapping, the
  view, the token shape, the type filter, and `Consume()` against FakeDatabase: a lost claim
  touches nothing, success leaves the state to the file's transaction, a 4xx marks `failed`
  with code and message, a 5xx/DB error/PHP error releases, `MarkUsed()` throws when not held).
- **Docs:** openapi (a `direct-uploads` tag, the three routes, the upload one `security: []`
  with the CORS and Cloudflare notes, `UploadTokenInput`, `UploadTokenMinted`,
  `UploadTokenState`), skill guide (Workflow 9 with a browser snippet, Auth, the error table,
  the checklist), deploy.md ("Direct uploads from a browser": the 100 MB cap and DNS-only,
  expiry on arrival), changelog (**new:**, **db:**).

---

## 6. Phase 4: recursive folder delete (§5.3)   [M] ✅

The blueprint gives this the lowest priority once provisioning exists (Tier 2 Phase 3): Turbopod
offboards whole keys instead.

**Route:** `DELETE /folder/:uuid?recursive=1` (parsed with RequestBool).
- Without `recursive`, nothing changes: a folder that isn't empty still gets 4003.
- The root folder still gets 4005: delete the key instead.

**Schema:** `folders.deleting_at DATETIME NULL`, plus an index.

**Flow:**
1. Find the subtree with `WITH RECURSIVE`, as `ComputeSize()` does: the folder ids and the file
   count.
2. Mark every folder in it: `UPDATE folders SET deleting_at = NOW() WHERE id IN (…subtree…)`.
   - From then on the whole subtree is gone as far as the API goes:
     `FolderControl::GetForKeyByUuid()`, `GET /folders`, public share listings (§0.2) and
     uploads into it all see 404 (4042).
   - Each check is one condition on the folder's own row. Nothing walks up the tree.
3. **Small subtrees are deleted now.** At most `folder_delete_inline_files` files (default 200),
   deepest folder first:
   - files through `FileControl::Delete()`, which adjusts the key's `total_size` and deletes
     renditions, shares and jobs;
   - then the folders' shares, then the folders.

   → `{"deleted": true}`.
4. **Larger subtrees** answer `{"deleted": false, "pending": true}`. `--process` housekeeping
   (§7) then deletes up to N files or T seconds' worth per run, until the marked subtree is
   empty, and then removes its folders. A file whose storage delete fails stays (5001, as
   today) and is tried again on the next pass.

**Behavior while a deletion is pending:**
- `GET /file/:uuid` and the batch lookup keep finding a file until the sweep deletes it. The file
  still exists, and checking its folder on every file lookup would cost a join each time.
  Listings leave it out already, since its folder is gone.
- **Concurrent uploads:** an upload that looked its folder up just before step 2 can still insert
  a file into a marked folder. The sweep keeps going until the subtree has no files left, so the
  next pass deletes it, and the folder delete waits for it (FK).
- **Quota:** the key's `total_size` drops as each file goes, not all at once.
- **Key deletion:** `DeleteKeyNow()` deletes everything anyway, marked or not.

**Docs:** openapi, skill guide Workflow 2, changelog **new:** and **db:**.

**Tests:**
- inline and swept deletes;
- the subtree disappears from listings, lookups and public shares;
- an upload into a marked folder;
- `total_size` afterwards;
- the root folder and the non-recursive delete behave as before.


### 6.1 As built (2026-11-19) ✅

- **Code:** `Folder/FolderDeletion` (`Delete()`, `Subtree()`, `Mark()`, `DeleteMarked()`,
  `Sweep()`), `Folder::$deleting_at`/`IsBeingDeleted()`, `FolderApi::Delete()` with
  `RequestBool::Parse($_GET, "recursive")`. `deleting_at IS NULL` in
  `FolderControl::GetForKey()`/`GetForKeyByUuid()` (so uploads, upload tokens, folder creation,
  listings by parent, the admin's import and every folder route see 4042), `GET /folders`, the
  public share's folder lookup and child listing, and `ShareRoot()` (a share of a marked folder
  is 4045). Conf keys `folder_delete_inline_files`, `folder_delete_max_s`.
- **Added: the top folder is renamed to `.deleting-<uuid>` when marked.** Names are unique per
  parent (`uniq_sibling_name`), so a marked folder would otherwise hold its name until the sweep
  deleted it: re-creating "invoices" right after deleting it would be a 4004, and the skill
  guide's ensure-folder recipe (4004 → list → find by name) would then find nothing, since the
  marked folder is hidden from the list. Only the top needs it: the rest are inside it.
- **Added: `GET /shares` leaves out shares of marked folders** (their target name would be the
  tombstone). The admin's Browser shows marked folders with a "deleting" badge instead of a
  delete button.
- **Inline deletes that hit a storage failure become pending,** not a 5001: the subtree is
  already gone for the API, and the sweep retries the file.
- **The sweep marks children created under a marked folder** in the moment before it was marked
  (one self-joined UPDATE per level, until none changes): otherwise their parent could never be
  deleted. A folder delete the FK refuses (an upload slipped in) waits for the next pass instead
  of failing the run.
- **Found and fixed: two deletes of one file lowered `total_size` twice.**
  `DeleteFileAndStorage()` returns whether its row delete hit (`LastAffectedRows()`), and
  `FileControl::Delete()` adjusts only then. It matters here (an inline delete and the sweep can
  meet on a file) and for two plain `DELETE /file` calls at once. Changelog **fix:**.
- **Cron timing:** with both defaults a busy run can take ~65 s (50 s of jobs + 15 s of sweep);
  the lock makes the next minute's run skip. deploy.md says so.
- **Tests:** `Folder/FolderDeletionTest` (the root refused with nothing marked, the subtree
  query, inline: mark + tombstone + files + total_size + folders deepest first with their
  shares, a big subtree left alone, a storage failure → pending with the folders kept, the
  sweep's propagation/files/leaves/summary, an idle sweep = 1 query, the single decrement).
- **Docs:** openapi (`recursive`, the two response shapes, 4009), skill guide (Workflow 2, "does
  NOT do"), deploy.md (the keys, the cron paragraph), changelog (**new:**, **fix:**, **db:**).

---

## 7. Housekeeping in `--process`

Same run as Tier 2's (Tier 2 §7): after the jobs, under the same lock, each item with its own
cap so the queue never starves.

| Work | Phase | Cap per run |
|---|---|---|
| retry `storage_garbage` | 2 | 100 paths |
| purge `upload_tokens` 7 days past expiry | 3 | 1000 rows |
| sweep folders marked for deletion | 4 | `folder_delete_max_s` (default 15 s) |

---

## 8. Phase 5: release   [S] (docs part ✅)

Part of the same release as Tier 1's Phase 8 and Tier 2's Phase 6:
- Review `1.5.0_tier3.sql` and mirror it in `database.sql`. Tier 2's Phase 6 runs all of 1.5.0's
  migrations together on a throwaway copy of the 1.4.0 schema.
- `deploy.md` "Upgrading to 1.5.0" gains this plan's magrathea.conf keys
  (`folder_delete_inline_files`, `folder_delete_max_s`) and the Cloudflare body-cap note for
  direct uploads.
- openapi, the skill guide and `/error-codes` are already done phase by phase.
- If this plan is cut from the release: its migration file and changelog entries stay out, and
  so do its routes.


### 8.1 Done, and what's left (2026-11-19)

- **Done:** `1.5.0_tier3.sql` (three blocks: `storage_garbage`, `upload_tokens`,
  `folders.deleting_at`), mirrored in `database.sql`; the conf sample (both sections); deploy.md
  (the keys, "Direct uploads from a browser" with the Cloudflare cap, "URL rotation and the
  CDN", the cron paragraph); openapi validates (openapi-spec-validator); `/error-codes` has
  4046, 4092, 4093, 4101.
- **Left for the release (with Tier 1 Phase 8 and Tier 2 Phase 6):** "Upgrading to 1.5.0" in
  deploy.md doesn't exist yet. It must list `1.5.0_tier3.sql` after tier2, and
  `folder_delete_inline_files`/`folder_delete_max_s`, and point at the Cloudflare note. Copy
  `docs/skills.md` into `~/.claude/skills/magrathea-explorer-api/SKILL.md`. The changelog date.
- **Unverified until a DB runs them** (riskiest first):
  1. the migration on a tier2 DB, then `models:verify` (`Folder.deleting_at`, `UploadToken`);
  2. the self-joined `UPDATE folders c INNER JOIN folders p …` in `Sweep()` on the production
     MySQL/MariaDB version;
  3. a claim race: two `POST /upload/{token}` at once, one 200 and one 4092;
  4. a recursive delete of a 1000-file tree: pending, gone from listings and public shares at
     once, `.deleting-<uuid>` in the table, empty after the cron, `total_size` right;
  5. rotate on both drivers: old original and rendition URLs 404 at the origin, new ones same
     sha256; S3 copy keeps Content-Type/Disposition/Cache-Control (check the headers);
  6. a failed storage delete → a `storage_garbage` row, gone after `--process`;
  7. a browser upload from another origin (no preflight), the backend's GET shows `used`;
     wrong type → `failed` with the reason; expired → 410;
  8. `POST /files/lookup` with 100 uuids, some another key's; 101 → 40013.

---

## 9. Test checklist (manual, per phase, on both storage drivers)

- [ ] `POST /files/lookup` with 100 uuids (some another key's) → only the key's own, in order; 101 → 40013
- [ ] rotate → the old original and rendition URLs 404 at the origin; new URLs, same sha256
- [ ] rotate while a job commits → 4093, nothing changed
- [ ] upload token: used once → second POST 409; expired → 410; wrong type → `failed`, with the reason in GET
- [ ] browser upload from another origin (no preflight) → File in the response; backend GET shows `used`
- [ ] recursive delete of a 1000-file tree → pending, gone from listings at once, empty after the cron runs, `total_size` correct
- [ ] a failed storage delete → a `storage_garbage` row, gone after the next `--process`
- [ ] `models:verify` and `check` clean

---

## 10. Phase order and size

| Phase | Content | Size | Depends on | Migration (`1.5.0_tier3.sql`) |
|---|---|---|---|---|
| 1 ✅ | Batch lookup | S | Tier 2 Phase 1 (JSON bodies) | — |
| 2 ✅ | Rotation + storage garbage queue | M | — | yes |
| 3 ✅ | Direct uploads | M | Tier 2 Phases 1 and 2; lands after Tier 2 Phases 4 and 5 | yes |
| 4 ✅ | Recursive folder delete | M | — | yes |
| 5 | Release, with Tier 1's Phase 8 and Tier 2's Phase 6 | S | all | review |

Phase 1 first, since it's the one Turbopod can use now. Phase 4 last, as the blueprint
suggests. Phases 2 and 4 don't touch the upload path, so they can go in at any point.

---

## 11. Answers for Turbopod (blueprint §8)

| Item | State today | Effort | Deviations from the blueprint |
|---|---|---|---|
| §5.1 direct uploads | missing | M | confirmed by id, not by token; a refused upload uses up its token; the 100 MB Cloudflare ceiling if the API host is proxied |
| §5.2 rotation | missing | M | old URLs fetched through Cloudflare stay cached (up to a year) unless purged; a concurrent job commit → 409 |
| §5.3 recursive delete | missing | M | files keep resolving by uuid until the sweep deletes them |
| §5.4 batch lookup | missing | S | malformed uuids are treated as unknown |

§5.5 (JSON) is answered in Tier 2 §11: it moved into Tier 2.

---

## 12. Decisions

Built with the recommendation (2026-11-19). Either is a small change if decided otherwise.

| # | Decision | Recommendation | Alternative and its cost |
|---|---|---|---|
| Q7 | CDN purge on rotation | Document it now; Cloudflare purge as a follow-up | Purge in Phase 2: Cloudflare credentials in `storage.conf` |
| Q8 | Upload-token confirmation | By non-secret id | By token, as the blueprint wrote it: the token ends up in request logs |

Q1–Q6 are Tier 2's (Tier 2 §12).

---

## 13. Out of scope

- Cloudflare purge (Q7).
- Presigned R2 uploads (§5).
- Multipart copy above 5 GB.
- A grace period on upload-token expiry.
- Everything in blueprint §6.

## 14. Risks

- **Rotation isn't revocation behind a CDN** (§0.3). Until Q7's purge exists, a leaked URL that
  was ever fetched through Cloudflare can stay reachable for up to a year after rotating. The
  docs have to say it in those words, not "for a while".
- **Cloudflare's 100 MB body cap** blocks direct uploads of large print files when the API host
  is proxied, which is the case §5.1 was written for. It's a deployment decision (DNS only for
  the API host), and `deploy.md` has to put it in front of whoever deploys.
- **Stored URLs go stale on rotation.** social keeps paths derived from URLs. Turbopod keeps
  URLs. Both have to update from the response, and a consumer that ignores it serves 404s.
- **Huge trees delete slowly.** A recursive delete is bounded per cron run, so a 100k-file tree
  takes many runs, and the quota frees up gradually. It converges; it isn't fast.
- **A killed upload strands its token** (added while building Phase 3). A PHP fatal releases it
  (shutdown function), but a SIGKILL or a host crash mid-upload leaves it `uploading`: every retry
  gets 4092 until it's purged, a week after expiry. The backend sees `uploading` past
  `expires_at` and mints a new one. A lease column would fix it; not worth it for 15-minute
  tokens.
- **Rotation vs a concurrent delete of the same file** can orphan the rotation's copies (§3.1).
