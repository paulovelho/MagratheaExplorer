# 1.5.0 — Turbopod Tier 2: implementation plan

Covers §4 of `.claude/blueprint-1.5.0.md` (key provisioning, Idempotency-Key, upload from a URL,
UTC timestamps), plus §5.5 (JSON body detection), which comes forward from Tier 3 because
Tier 2 needs it first (§0.3). Tier 3 has its own plan, `.claude/plan-1.5.0-tier3.md`.

It works like `.claude/plan-1.5.0-tier1.md`: the work is split into phases, each phase leaves the
branch working with its own docs and tests, and "Decided" and "As built" notes get added as the
work goes.

Written against the working tree on `version/1.5`. Tier 1's code is done there, 1.5.0 isn't
released yet, and Tier 1's Phase 8 is still open. **This plan ships in 1.5.0 too** (decided,
§1).

**Status (2026-11-18):** Phases 1 to 5 done (§2.6, §3.5, §4.8, §5.9, §6.7), except the framework
release (§2.1) and every DB-backed check (no DB here). Phase 6 (release) is left. Decided: the
release (Q1, everything in 1.5.0), timestamps (Q2, switched in place), key secrets (Q3, hashed),
provisioning (§4.1: create-only, one instance secret, limits by the operator; that made Q4's
ceilings and Q6 moot) and the URL fetch (Q5, one limit; opt-in; `name` on both sources). Nothing
is open.

---

## 0. Findings: the code vs the blueprint's "Today" lines

### 0.1 Keys

- **The bearer secret is `access_keys.uuid`.** It's declared `"uuid"` (`Key.php:22`), so
  `Insert()` mints a **UUID v7**: 48 of its bits are the creation time in ms and 74 are random.
  That's still far too many to guess online, but it's a weaker secret than a UUID looks, and it
  gives away when the key was made. Provisioned and rotated secrets should be fully random
  (§4.5).
- **It's stored in plaintext.**
  - Auth looks it up as-is (`KeyControl.php:99`).
  - The admin's key form shows it (`features/Key/admin/form.php:50`).
  - Every Magrathea dump in `backups/` contains all of them.
  - The blueprint asks for "returned only here and on rotation" for the API. It doesn't ask for
    hashing. **Open (Q3).**
- **Non-secret ids that already exist:** `id`, an int auto-increment (sequential, which §6 of the
  blueprint rules out), and `storage_uuid`, which is public and appears in every file URL.
- **No API creates keys.** `KeyControl::Create()` is only called from `KeyAdmin::Save()`.
- **`access_keys.name` is UNIQUE across the whole instance** (`database.sql`). Two provisioners
  that both name a key `workspace-42` would collide, and the 1062 error would tell one of them
  that the other's name exists.
- **Offboarding needs `cron.php --run`.** `DELETE /key` schedules the deletion 14 days out, and
  only `cron.php --run` (daily) carries it out. The DreamHost instance has no `--run` line
  (Tier 1 §1.2), so a provisioned key's deletion would never complete there.
- **`DeleteKeyNow()` deletes everything in one go,** one storage call per object. A workspace
  with 10k print files has about 30k objects (the original plus 2 renditions each) to delete in
  a single cron run. It does converge: a run that dies is picked up by the next one from what's
  left. But at one run a day, each retry costs a day (§14).

### 0.2 Auth

- **Every auth failure looks the same.** The framework answers 401
  `Authorization Failed! (IsAuthenticated = false)` whenever the auth method returns false
  (`MagratheaApi.php:528-530`). If the auth method throws a `MagratheaApiException` instead, the
  framework returns that exception (`:532`), so the auth method can answer 429 itself.
- There's no rate limiting anywhere (grepped).
- `Add()`'s `$auth` argument is the name of a method on the single `BaseAuthorization()` class
  (`KeyAuthControl`). So the provisioner check is a second method on that class, not a second
  auth class.

### 0.3 JSON bodies (§5.5)

- **Framework.** `GetPhpInput()` (`MagratheaApiControl.php:287`) treats a body as JSON only when
  the Content-Type *ends in* `json`. A body sent as `application/json; charset=utf-8` goes
  through `parse_str()` instead, every field reads as missing, and the caller gets a 4001.
  `docs/skills.md` documents this as the "JSON trap".
- **It also accepts bad JSON quietly.** Invalid JSON gives `null`, and a JSON scalar or list
  gives something that isn't an array, which callers then index into.
- **Explorer has its own JSON problem.** `FileApi::Upload()` (`FileApi.php:22`) reads "no
  `$_FILES`, no `$_POST`, but a Content-Length" as a `post_max_size` overflow, and answers 4002.
  Every non-multipart `POST /files` matches that, so **a JSON `source_url` request (§4.3) would
  be refused as too large.**
- This plan's new routes take structured input (a rendition profile is a JSON array), and so
  does Tier 3's batch lookup (up to 100 uuids). So §5.5, filed under Tier 3, is something Tier 2
  needs first. **It moves to Phase 1.**

### 0.4 Timestamps (§4.4, blueprint §7 Q4)

**How they're stored.** Everything is in local time: magrathea.conf `timezone`, which is
`America/Sao_Paulo` in both sections of the sample. The framework sets MySQL's session
`time_zone` to PHP's, so both kinds of column read back as local wall time:
- `TIMESTAMP` columns (stored as UTC inside MySQL, converted on read): `created_at` on files,
  folders, shares and keys.
- `DATETIME` columns (stored as written, which is local): `scheduled_deletions.execute_at`,
  `access_keys.expiration`, `shares.last_viewed_at`.

**Where they reach the API:**
- `GET /key`: `expiration`, `created_at`.
- `DELETE /key`: `execute_at`.
- Folders: `created_at`.
- Files, in both the owner and the shared view (`FileResponse.php:54`, `:85`): `created_at`.
- Shares: `created_at`, `last_viewed_at`.

No `updated_at` is exposed anywhere. `/changelog` has dates without a time, which this doesn't
affect.

**Consumers (Q4 in the blueprint):**
- **The explorer app.** `formatDate()` (`app/src/utils/format.js:12`) does
  `new Date(s.replace(' ', 'T'))`. Given a `Z`, that parses as UTC, so the app keeps working.
  Today it reads the server's local time as the browser's local time, which is wrong for any
  browser outside BRT. The change fixes that.
- **social.** `social/api/src/core/Storage/ExplorerClient.php:108` copies each file's
  `created_at` into its list, and nothing reads it afterwards (`UserDeleter` only uses
  `file_uuid`). It isn't affected.
- **Turbopod** is new.
- **Nothing else in `~/www`** (grepped for Explorer routes and `thumbnail_url`). Third parties
  working from the published skill guide are unknown.

**DST.** Sao Paulo hasn't had DST since 2019, so converting local time to UTC is unambiguous on
this instance. An instance in a zone with DST gets one ambiguous hour a year, in both column
kinds, since TIMESTAMPs are also read through the session zone. That gets documented, not
engineered around.

### 0.5 `files.size` is a signed int(11): files stop at 2 GiB

`database.sql:108`. Meanwhile the key form accepts limits up to 1 TB
(`KeyControl::MAX_UPLOAD_MB_CEILING`), and the blueprint expects videos and ISOs of several GB.
A 2.5 GB upload passes every check. Then the insert fails (strict mode) or silently clamps the
size to 2147483647 (non-strict), after the object is already in storage. The URL fetch (§4.3)
makes this likelier, since PHP's post limits don't stand in its way.
`access_keys.total_size` and `file_renditions.size` are already `bigint`. Fix in Phase 1.

### 0.6 `/changelog` hides breaking changes

`SystemApi.php:50` keeps only `new`, `fix` and `improvement(s)` entries. 1.5.0 already has
**breaking:** and **db:** entries, and `/changelog` drops them. On top of that, Tier 1 §2.4
found that only the first line of each entry is kept. §4.4 asks for "a changelog entry" for the
timestamp change, and an entry the endpoint drops doesn't count. Fixed in Phase 1.

### 0.7 The upload pipeline vs sources that aren't multipart

- `Key::EffectiveMaxUploadSize()` is the smaller of the key's (or instance's) limit and PHP's
  `post_max_size`/`upload_max_filesize`. PHP's limits don't apply to a URL fetch.
  **Open (Q5):** keep one number everywhere (recommended: `GET /key` stays true for every source)
  or give each source its own.
- `MoveToWorkingCopy()` (`UploadPipeline.php:178`) uses `copy()` for anything that isn't an HTTP
  upload, so a 150 MB URL fetch would sit on disk twice. It needs a move instead.
- `Handle()` takes an array shaped like a `$_FILES` entry, so a new source fits by building one.
  ImportPipeline already does that (`ImportPipeline.php:63-73`).

### 0.8 Error-code space

- There are no 409, 410, 422, 429 or 502 codes yet. 4-digit codes map to HTTP `code / 10`, so
  4091, 4101, 4221, 4291 and 5021 are free.
- The 400 block is full (4001–4009). New 400 codes take 5 digits (40010 and up, HTTP
  `code / 100`), as Tier 1 §0.7 said.
- Tier 3's codes are allocated in this plan too (§2.5), so the two plans never pick the same
  number.

---

## 1. Release: everything in 1.5.0 (decided 2026-11-18)

- **Tier 2 and Tier 3 go on the current branch and ship as 1.5.0, together with Tier 1.**
  I recommended shipping Tier 1 alone first. Decided otherwise.
- **The cost:** the XXE file-disclosure hole (Tier 1 §0.1) stays live on every 1.4.x instance
  until Tier 2 and Tier 3 are done. Tier 1 §0.1's interim measure applies for longer: disable
  any key held by a party that isn't fully trusted until 1.5.0 is deployed.
- **Tier 3 can still be cut at release time.** It needs this plan's Phase 1 (JSON bodies) and
  Phase 2 (UTC times), and none of this plan's tables. If it's late, the release goes without
  it, and its migration file stays out.
- **Migrations:** this plan appends to `api/database/migrations/1.5.0_tier2.sql`, and Tier 3 to
  `1.5.0_tier3.sql`. Both run in order after `1.5.0_tier1.sql`, and each is mirrored in
  `database.sql` phase by phase, as in Tier 1. Phase 6 decides whether to merge them into one
  file.
- **No version bump:** `api/version` and the openapi `version:` are already 1.5.0. Changelog
  entries go under the existing `## 1.5.0` / `unreleased` heading.
- **The long branch gets longer** (Tier 1 §14). Phases 1, 4 and 5 here, and Tier 3's Phase 3,
  all touch the head of `FileApi::Upload()` or `UploadPipeline::Handle()`. Land them in order.

---

## 2. Phase 1: prerequisites   [S] ✅

### 2.1 JSON body detection (§5.5, pulled forward)

- **Fix it in MagratheaPHP.** `GetPhpInput()` should parse the media type: the body is JSON when
  `strtolower(trim(strtok($contentType, ";")))` is `application/json` or ends in `+json`. That's
  a 3.0.x framework release, and Explorer's composer constraint follows it.
- **Override it in Explorer until then,** and keep the override as a guard:
  `ExplorerApiControl::GetPhpInput()` with the same rule. Every controller that reads a body
  extends `ExplorerApiControl` (checked: FileApi, FolderApi, ShareApi; KeyApi reads none).
  `SystemApi` and `PublicShareApi` read no body.
- **The override also tightens two things:**
  - Invalid JSON, or JSON that isn't an object, gets **40010 "Invalid JSON body"** (400) instead
    of today's "every field missing" (4001).
  - The body is read with a 1 MB cap. JSON bodies here are small, and the cap stops a big body
    from becoming one PHP string.
- **Docs:** remove the "JSON trap" paragraph from `docs/skills.md` "Request bodies". In openapi,
  JSON is accepted on every route except the multipart form of `POST /files`.
- **Tests:** `tests/Shared/JsonBodyTest.php`: a charset parameter, `+json`, upper case, invalid
  JSON, a scalar, a list, the form fallback.

### 2.2 `POST /files`: the overflow check

Replace the "empty `$_POST`" heuristic (`FileApi.php:22`) with an explicit check that runs only
for a `multipart/form-data` request whose `CONTENT_LENGTH` is above `post_max_size` (converted
with `Key::IniSizeToBytes()`). Without this, Phase 5's JSON `source_url` request is a 4002.

### 2.3 `/changelog` parser

- Accept every entry type the changelog uses: `new`, `fix`, `improvement(s)`, `breaking`, `db`
  (`SystemApi.php:50`). openapi's changelog `type` enum follows.
- Keep continuation lines, joined with a space (Tier 1 §2.4's finding).
- The response is cached in `cache_path/changelog.json`, which the deploy steps already clear.

### 2.4 `files.size` becomes bigint (§0.5)

```sql
ALTER TABLE `files` MODIFY `size` bigint(20) NOT NULL;
```

- The `File` model keeps the type `int` (PHP ints are 64-bit). `FileResponse`'s `(int)` casts
  and `ComputeSize()`'s `SUM()` need no change.
- The ALTER rebuilds the table. The migration header says so, and says to run it off-peak on a
  large instance.

### 2.5 Error codes: the whole allocation, Tier 3's included

`error_codes.conf` gets the base entries `409 = "Conflict"`, `410 = "Gone"`,
`422 = "Unprocessable Content"`, `429 = "Too Many Requests"` and `502 = "Bad Gateway"` (as
Tier 1 added `413`). Each phase adds its own codes from this table:

| Code | HTTP | Message | Phase |
|---|---|---|---|
| 40010 | 400 | Invalid JSON body | 1 |
| 40011 | 400 | Invalid Idempotency-Key | 4 |
| 40012 | 400 | Source URL refused | 5 |
| 40013 | 400 | Invalid field value (the detail names the field) | 2 (`ApiTime`), 3 (Tier 3 reuses it) |
| 4033 | 403 | Provisioning key limit reached | 3 |
| ~~4034~~ | — | (was: exceeds what this provisioner may grant. Unused: no ceilings, §4.1) | — |
| 4046 | 404 | Upload token not found | Tier 3, Phase 3 |
| 4091 | 409 | A request with this Idempotency-Key is still in progress | 4 |
| 4092 | 409 | Upload token already used | Tier 3, Phase 3 |
| 4093 | 409 | File changed during rotation, retry | Tier 3, Phase 2 |
| 4094 | 409 | Key name already exists | 3 |
| 4101 | 410 | Upload token expired | Tier 3, Phase 3 |
| 4221 | 422 | Idempotency-Key reused with a different request | 4 |
| 4291 | 429 | Too many failed authentications | 3 |
| 5021 | 502 | Source URL fetch failed | 5 |

`api/cache/error_codes.json` gets cleared on deploy (already in `deploy.md`).

### 2.6 As built (2026-11-18) ✅

- **The parsing lives in `shared/RequestBody.php`** (static, like `RequestBool`), and
  `ExplorerApiControl::GetPhpInput()` is a thin wrapper over it, so the rules are testable
  without `php://input`. Tests: `tests/Shared/RequestBodyTest.php` (not `JsonBodyTest.php`:
  named after the class) and `tests/Shared/ChangelogTest.php`.
- **Multipart is never read.** Found while writing the cap: an upload whose only field is
  `file` leaves `$_POST` empty, so `GetPost()` falls through to `GetPhpInput()` with the whole
  upload as its Content-Length. A cap there would have 413'd every such upload.
  `GetPhpInput()` returns `$_POST` for `multipart/form-data` (PHP has parsed it, and
  `php://input` is empty for it). Checked over real HTTP (`php -S`, `post_max_size=2M`): a
  1.5 MB file-only multipart passes; one over `post_max_size` is the only thing the overflow
  check flags.
- **Over 1 MB → base `413`** ("Payload Too Large: request body over 1 MB"), by Content-Length
  before reading, and by the bytes that actually arrive. §2.5's table had no code for it; a
  base code needs no allocation.
- **An empty JSON body is no fields, not 40010,** so the route's own check names what's
  missing (4001). `{}` likewise.
- **40010's detail** is json_decode's own message ("Syntax error", "Malformed UTF-8
  characters…", "Maximum stack depth exceeded") or "expected a JSON object".
- **The media-type rule is the plan's:** `application/json` or `*+json`. `text/json` and
  `application/x-json`, which the framework took as JSON (they end in `json`), are forms now.
  No Explorer client in `~/www` sends either (grepped).
- **Overflow check:** `RequestBody::ExceedsPostMaxSize()`, multipart only, `post_max_size` via
  `Key::IniSizeToBytes()` (0 = no limit). A JSON `POST /files` now gets 4001 `file`.
- **`/changelog`:** `SystemApi::ParseChangelog()`, also takes **`upgrade`** (1.4.0 uses it; the
  list above missed it). An entry of an unknown type is dropped with its wrapped lines; a blank
  line ends an entry. The test parses the real `changelog.md` too.
- **Error codes:** 40010 and the five base entries. `files.size`: `1.5.0_tier2.sql` (new) and
  `database.sql`. **The migration and `models:verify` haven't run**: no DB was reachable.
- **Docs:** skill guide (Request bodies, the 5-digit status rule, error table with 40010 and
  413, checklist), openapi (info, `/changelog`, `ChangelogEntry.type`, 40010 on every route
  that reads a body), changelog (two **fix:**, one **db:**).
- **Not done: the MagratheaPHP fix** (`/mnt/Rincewind/MagratheaPHP3`, a separate repo and
  release). Until it ships, the override is the fix.

---

## 3. Phase 2: timestamps in ISO 8601 UTC (§4.4)   [S] ✅

Phase 2 comes before provisioning, so that every route Phase 3 adds starts out in UTC.

### 3.1 Switch every field in place (decided 2026-11-18)

- Every datetime in every response becomes `YYYY-MM-DDTHH:MM:SSZ`.
- **No `_utc` twin fields.** Twins would double every datetime field forever, to spare
  consumers that §0.4 couldn't find.
- **No API version bump:** `/api/v1` stays. The 1.2.0 uuid refactor set the precedent (see the
  `.htaccess` comment).
- The blueprint's §2 already allows this break. It gets a changelog **breaking:** entry with
  before and after, and the skill guide's "Mental model" says it once.

### 3.2 One converter: `shared/ApiTime.php`

```php
/** A DB datetime (local wall time in magrathea.conf `timezone`) as "2026-11-17T03:22:10Z". null stays null. */
public static function Utc(?string $local): ?string;

/**
 * An API datetime: ISO 8601 with `Z` or an offset, or a plain date (= 00:00:00Z that day).
 * Returns local "Y-m-d H:i:s" for storage. 40013 naming $field on anything else.
 */
public static function FromApi(string $value, string $field): string;
```

- It uses `DateTimeImmutable` with `date_default_timezone_get()` (what `Load()` set from
  `timezone`), converts to UTC, and formats with `Y-m-d\TH:i:s\Z`.
- A zero date or a value that can't be parsed becomes `null`. That shouldn't happen.
- It's for the API only. The admin keeps showing local times, which is what the operator reads.

### 3.3 Where it applies

- `KeyApi::KeyView()` (`expiration`, `created_at`) and `KeyApi::Delete()` (`execute_at`).
- `FolderApi::FolderView()`.
- `FileResponse::Owner()` and `FileResponse::Shared()`.
- `ShareApi::ShareView()`.
- `PublicShareApi`'s folder payload, if it carries any dates.
- Every view added later uses it from the start: provisioning here, upload tokens in Tier 3.
- Review step for the phase: grep for `_at"` and `expiration` in `src/features` and `src/shared`.
  No datetime should leave the API unconverted.

### 3.4 Docs and tests

- **openapi:** every datetime property gets `format: date-time`, and the examples end in `Z`.
- **Skill guide:** "Mental model", and the TS types (`string // ISO 8601, UTC`).
- **The app** needs nothing (§0.4). `formatDate()`'s `replace(' ', 'T')` becomes dead code.
  Leave it in place, since it keeps working against older servers.
- **Changelog:** a **breaking:** entry.
- **Tests:** `tests/Shared/ApiTimeTest.php` covers a zone with DST (Europe/Berlin), one without
  (America/Sao_Paulo), null, a plain date, input with offsets, and garbage.

### 3.5 As built (2026-11-18) ✅

- **`shared/ApiTime.php`** as specified. `Utc()` parses strictly (`!Y-m-d H:i:s`, and
  createFromFormat's warnings count: it reads `2026-02-30` as March 2nd otherwise), so a date
  without a time, an ISO string or a zero date is `null`.
- **`FromApi()` accepts:** `YYYY-MM-DD`, or `YYYY-MM-DDTHH:MM[:SS[.fraction]]` with `Z`, `±HH:MM`
  or `±HHMM`. `t`/`z` in lower case too (RFC 3339), `-00:00` as UTC, fractions dropped (so
  `toISOString()` works). **Refused:** a date-time without an offset (it would silently mean the
  server's zone), a space instead of `T`, a named zone, week dates, leap seconds, impossible
  dates and times, and a result outside MySQL's DATETIME years (1000–9999) once converted to
  local. The 40013 detail is `expiration (expected an ISO 8601 date-time with Z or an offset,
  like 2026-11-18T12:00:00Z, or a YYYY-MM-DD date)`.
- **40013 is in `error_codes.conf` now** (§2.5 had it in Phase 3), because `FromApi()` throws it.
  No route reaches it yet, so the openapi and skill-guide error tables get it in Phase 3, with
  the first route that takes a datetime.
- **Applied in** `KeyApi` (`expiration`, `created_at`, `execute_at`), `FolderApi`, `ShareApi`
  (`created_at`, `last_viewed_at`) and both `FileResponse` views. `PublicShareApi`'s folder
  payload carries no dates (its files go through `FileResponse::Shared()`). The §3.3 grep is
  clean: no datetime leaves `src/features` or `src/shared` unconverted.
- **Found, not in §0.4's list: the health check's `time`.** It's the framework's
  `HealthCheck()`, which returns local `now()`. `api.php` registers its own `health-check`
  instead (`Health()`), same shape, `time` through `Utc()`. Checked over HTTP (`php -S`): it
  matched `date -u` to the second. The framework's own version is still local: worth fixing
  there with the JSON fix (§2.1).
- **DST, as measured** (PHP 8.4): in Europe/Berlin the repeated hour (`2026-10-25 02:30`) reads
  as the later one (CET, `01:30Z`), and a time in the skipped hour moves forward. The test
  accepts either reading of the repeated hour, since that's PHP's choice, not ours.
- **`deploy.md`:** its config sample had `time_zone` in `[general]`, which the framework ignores
  (it reads `timezone` in the environment section only; the sample had both). Removed, and a
  paragraph says what `timezone` now means for the API: never change it on an instance with
  data, leave `db_sync_timezone` on, and the DST hour.
- **Docs:** openapi (the info paragraph, the `Timestamp` schema with `format: date-time`, a
  `…Z` pattern and example: every datetime property already pointed at it), skill guide
  (Mental model, the upload example, the TS type, the checklist), changelog (one
  **breaking:**).
- **Not run: §9's DB check** ("every datetime … matches the DB's local value"): still no
  Explorer DB reachable here (no `mag_sql`, no mysqli on the host). The views are a one-line
  change each; the conversion is what the tests cover.

---

## 4. Phase 3: key provisioning (§4.1)   [M] ✅

### 4.1 Decided (2026-11-18): create-only, one instance secret, hashed key secrets

The first draft of this section (a `provisioners` table, one row per consuming project, the
blueprint's seven management routes, a new `public_id` per key, ceilings on what a provisioner
could grant) was replaced before any of it was built:

- **Limits are the operator's.** Turbopod doesn't set a key's limits, neither on creation nor
  later. Every provisioned key gets the same settings, and the rare exception is changed by hand
  in the admin. Turbopod setting them itself (plans, upgrades) is a job for the future.
- **So the only provisioning route is `POST /provision/keys`.** Everything after creation is
  done with the key's own secret, which Turbopod holds anyway to upload: `GET /key`,
  `/key/usage`, `DELETE /key`, `POST /key/cancel-deletion` and a new
  `POST /key/rotate-secret`. No route acts on someone else's key, so:
  - **Q6 is moot:** no route takes a key id, and no `public_id` column was added.
  - **Q4's ceilings are gone** (4034 unused): the request can't set a limit, so there's nothing
    to cap. `max_keys` stays.
  - A leaked provisioning secret can only add keys, at most `max_keys`, each with the template's
    limits. In the first draft it could rotate every workspace's secret and hold every tenant.
- **One provisioning credential per instance, not a table of provisioners.** One consumer
  exists. If a second one ever needs provisioning, the row becomes row #1 of a table.
- **Where it lives: one row in a `provisioning` table** (decided against AppConfig, which was the
  first idea): `_magrathea_config.value` is `varchar(255)`, and a rendition profile is ~170
  characters for thumb + preview, ~800 for six specs. The row holds the secret's hash,
  `max_keys`, the template and `last_used_at`. Its row lock serializes `max_keys`.
- **Q3 = B: key secrets are hashed** (sha256), the provisioning secret too. Nobody can read a
  secret again, the operator included: the admin shows it once, after creation or rotation.

### 4.2 Schema (`1.5.0_tier2.sql`, Phase 3 block, mirrored in `database.sql`)

- **`access_keys.secret_hash`** `char(64) NOT NULL UNIQUE`, filled by
  `SHA2(LOWER(TRIM(uuid)), 256)`. Then **`access_keys.uuid` is dropped**. LOWER because the old
  lookup went through the column's case-insensitive collation: a secret sent in upper case
  worked, and still does (`Secret::Hash()` lowercases too). Every existing integration keeps
  sending the same secret and keeps working.
- **`access_keys.provisioned`** `tinyint(1) NOT NULL DEFAULT 0`: what `max_keys` counts, and the
  key list's filter.
- **`provisioning`**: one row, id 1, inserted by the migration (`ProvisioningControl::GetOrCreate()`
  puts it back if deleted). `secret_hash` (NULL = off), `max_keys`, the five settings with the
  key's column types and meaning (`usage_limit`, `usage_limit_mb`, `verbatim_default`,
  `max_upload_size`, `rendition_profile`; NULL = the instance default), `last_used_at`,
  `created_at`/`updated_at` per the framework's contract.
- **`auth_failures`**: `bucket`, `window_start` as an **int (unix time)**, not the DATETIME the
  draft had: windows are epoch-aligned, so no local-time DST overlap can merge two of them.
  Extra index on `window_start` for the purge.
- **Key names stay globally unique** (the existing UNIQUE). The draft's per-provisioner scoping
  has nothing to scope now.

### 4.3 Secrets: `shared/Secret.php`

- **Key secrets:** UUID v4 from `random_bytes()` (122 bits), lowercase, the same shape every key
  always had: no client that stores or checks it as a UUID breaks. For keys made in the admin
  too.
- **The provisioning secret:** `xpv_` + 64 hex (256 bits). A key's secret never has that shape,
  so it never even reaches the hash compare.
- **`Hash()`:** sha256 of the trimmed, lowercased secret. Lowercasing costs nothing: every secret
  minted here is hex.
- **Where a secret is shown:** the API responses of `POST /provision/keys` and
  `POST /key/rotate-secret`, and the admin page after a create or a rotation (a session flash,
  read and removed on the redirect).

### 4.4 Auth: `KeyAuthControl::IsProvisioning()`

1. The rate limit is checked first: **4291** with `Retry-After` when the client's window has 10
   failures already, before anything is compared.
2. The bearer has to have the provisioning shape and `hash_equals()` the stored hash.
3. A failure is the framework's generic 401 (`IsProvisioning = false`) and counts one failure.
4. A success writes `last_used_at`, at most once a minute (in a try: bookkeeping never fails a
   request).

The two credentials can't mix: a key's secret has the wrong shape and isn't the stored hash,
and the provisioning secret isn't in `access_keys`.

### 4.5 Rate limit: `shared/AuthRateLimit.php`, `shared/ClientIp.php`

- Fixed, epoch-aligned 15-minute windows, 10 failures, one
  `INSERT … ON DUPLICATE KEY UPDATE` per failure. Parallel failures can go a few past 10 (read,
  then increment): a brake, not a quota.
- **Buckets:** IPv4 as is; **IPv6 by its /64** (one host usually gets a whole /64, so counting
  per address would count nothing); an IPv4-mapped IPv6 address as its IPv4. Not in the draft.
- **`client_ip_header`** (magrathea.conf): trusted only when set, and only when the header holds
  exactly one valid IP. Otherwise REMOTE_ADDR. A list (`X-Forwarded-For: a, b`) is refused, since
  its left end is client-written. A value that isn't a header name is a 5004.
- **Purge:** windows older than a day, in `cron.php --process`, after the jobs and under the same
  lock, and also when no processor is configured (`ProcessingQueue::Run()` returns early there).

### 4.6 Routes

```
POST provision/keys       (provisioning secret)  {name}  → GET /key's view + `secret`
POST key/rotate-secret    (the key itself)               → {secret}
```

- **`POST /provision/keys`** takes `name` and nothing else: any other field, settings included,
  is **40013** naming it, since a field silently ignored would look like it applied. A missing
  name → 4001. Not a string, over 255 characters, or with control characters → 40013.
- **Create** (`ProvisioningControl::CreateKey()`), in one transaction: lock the provisioning row
  (`FOR UPDATE`), check `max_keys` (**4033**; pending deletions count) and the name (**4094**),
  then `KeyControl::Create()`: key, copied settings and root folder. `KeyControl::Create()` is
  transactional on its own too, so an admin create no longer leaves a key without a root folder
  either (it answered 5004 on every request). A 1062 from an admin key taking the name in
  between → 4094. Any other DB error is logged and answered as a plain 500: the framework's
  error response carries a DB exception's query and values **in every mode, not only DEV** as
  §0 assumed.
- **The settings are copied, not referenced.** Changing the template later doesn't touch
  existing keys, and a NULL in the template is NULL on the key (the instance default, read at
  use).
- **`POST /key/rotate-secret`:** one UPDATE, the old secret fails from the next request on, no
  grace period. If its response is lost, the key is locked out until the operator rotates it in
  the admin. Documented.
- **Logging:** `logs_path/keys_<YYYYMM>.log`, one line per provisioned key and per API rotation:
  the key's id, name and the client IP, never a secret. `KeyLog` restores Logger's active file
  afterwards (it's a singleton setting), and never fails the request.

### 4.7 Admin

- **Provisioning** (new page): on/off and last use; **Generate / Rotate secret** (shown once) and
  **Turn off**; *Max keys*; the template, with the same fields as the key form (a shared partial,
  `Key/admin/settings-fields.php`); the provisioned key count (and how many are pending
  deletion); the **worst case**, max keys × size limit, "unbounded" with a warning when either
  is empty.
- **Keys:**
  - paged at 50, newest first, filter *All / Made in the admin / Provisioned*, a "provisioned"
    badge;
  - the form drops the UUID field and gains *Created by*, a one-time secret banner, and
    **Rotate secret**;
  - a new key's form opens with its secret after saving.
- **The key form's parsing moved to `KeySettings::FromForm()`,** which the Provisioning form
  uses too. Every settings field is checked before anything is saved now. Before, `usage_limit`
  and `usage_limit_mb` were `(int)` cast (`"abc"` saved as 0), and on a new key `Normalize()`
  turned 0 into "no limit". Name required, unique (both used to be a DB error or an empty name),
  and an expiration that isn't a date are refused too.
- **Found and fixed: saving a key cleared its expiration.** The `date` input was given
  `YYYY-MM-DD HH:MM:SS`, showed nothing, and the save wrote that nothing back.
- **Found, worked around: MagratheaPHP 3.0.0's `GetPagination()` skips a row at every page
  boundary** when it runs without a total: it fetches `limit + 1` rows and `Page()` computes the
  offset from that same `limit + 1`. The key list pages with the total (`withTotal: true`), which
  is correct. It needs a framework fix.

### 4.8 As built (2026-11-18) ✅

- **New:** `shared/Secret.php`, `shared/ClientIp.php`, `shared/AuthRateLimit.php`;
  `features/Provisioning/` (`Provisioning`, `ProvisioningControl`, `ProvisioningApi`,
  `ProvisioningAdmin`, `admin/index.php`), in `_autoload.php`; `features/Key/KeySettings.php`,
  `KeySettingsException.php`, `KeyLog.php`, `admin/settings-fields.php`.
- **Changed:** `Key` (`secret_hash`, `provisioned`, `Normalize()` keeps 0), `KeyControl`
  (`Create()` returns `[key, secret]` and is transactional, `UpdateFields()`, `RotateSecret()`,
  `NormalizeName()`, `NameTaken()`, lookup by hash), `KeyApi` (`View()` public,
  `RotateSecret()`), `KeyAuthControl::IsProvisioning()`, `KeyAdmin` and its views, `api.php`, the
  admin menu, `cron.php`, `error_codes.conf` (4033, 4094, 4291), the conf sample.
- **Tests:** `Shared/SecretTest`, `Shared/ClientIpTest`, `Shared/AuthRateLimitTest`,
  `Key/KeySettingsTest` (form parsing, the template copy, names, the worst case),
  `Provisioning/ProvisioningApiTest` (the body). 374 tests, all green. The three admin views
  were rendered offline with fake data (no DB, no session): they render.
- **Docs:** openapi (the auth paragraph, a `provisioning` tag, `provisioningBearer`,
  `POST /provision/keys`, `POST /key/rotate-secret`, `KeySecret`, `ProvisionKeyInput`,
  `ProvisionedKey`, 40013 in `BadRequest`), skill guide (Mental model, Auth, Workflow 7,
  **Workflow 8**, error table, checklist, "does NOT do", Images3 table), `deploy.md` (key secrets
  shown once and old dumps, "Key provisioning", `client_ip_header`, `--run` required for
  offboarding, the purge), changelog (2 **new:**, 1 **breaking:**, 1 **improvement:**, 1
  **fix:**, 1 **db:**).
- **Not run:** the migration, `models:verify` and every integration check below (still no DB
  here, and Docker was declined). Unverified until they run:
  - the migration on a copy of a 1.4.x/tier1 DB: every key still authenticates;
  - a key secret on `/provision/keys` → 401, the provisioning secret on `/files` → 401;
  - rotate (API and admin): the old secret → 401 on the next request;
  - the 11th failure → 429 with `Retry-After`, another IP unaffected;
  - `max_keys`, including two creates in parallel for the last slot;
  - a create whose root folder insert fails leaves no key;
  - `cron.php --process` purges old windows.
- **Phase 4 changes because of this** (§5): `credential_type` is `('key','provisioning')`, and a
  replay of a key creation can't return the secret (only its hash exists). It has to mint a new
  one (a rotation), which is fine: the first one never reached anyone.

---

## 5. Phase 4: Idempotency-Key (§4.2)   [M] ✅

### 5.1 Schema

```sql
CREATE TABLE `idempotency_keys` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`credential_type` enum('key','provisioning') NOT NULL,
	`credential_id` int(11) NOT NULL,
	`idempotency_key` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	`request_hash` char(64) NOT NULL COMMENT 'sha256 of the route and the canonical request, file bytes included',
	`status` enum('in_progress','done') NOT NULL DEFAULT 'in_progress',
	`lease_until` DATETIME NOT NULL,
	`resource_type` enum('file','key') NULL,
	`resource_id` int(11) NULL,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY `uniq_idempotency` (`credential_type`, `credential_id`, `idempotency_key`),
	KEY `idx_idempotency_created` (`created_at`)
);
```

- **Binary collation.** With a case-insensitive one, `ABC` and `abc` would be the same key.
- **No FK on `resource_id`.** It points at either a file or a key, and a deleted file must
  neither block the record nor be blocked by it.

### 5.2 The state machine: `shared/Idempotency.php`

1. No header: no idempotency, which is today's behavior.
2. A header that's empty, longer than 255 characters, or not printable ASCII → **40011**.
3. `INSERT` the row, with status `in_progress` and `lease_until = NOW() + lease`.
   - **Inserted:** this request owns the key, and the action runs.
   - **Duplicate (1062):** read the existing row and check, in this order:
     1. `request_hash` differs → **4221** (422). This is checked first, so a different request
        learns nothing else about the first one.
     2. `done` → **replay** (§5.4).
     3. `in_progress` with `resource_id` set → replay. The work finished, and only the
        bookkeeping didn't (§5.3).
     4. `in_progress` with a live lease → **4091** (409).
     5. `in_progress`, lease expired, no resource → take the row over:
        `UPDATE … WHERE id = ? AND status = 'in_progress' AND lease_until < NOW() AND resource_id IS NULL`.
        One row changed means this request now owns it. Zero means another retry got there
        first: 4091.
4. The action succeeds → `status = done`, with the resource set.
5. The action throws → the row is **deleted**. Errors aren't stored, so a retry runs again (and
   gets the same 4xx again, if that's what it was). The blueprint only asks completed requests
   to replay.

**Lease:** `max(max_execution_time, source_url_timeout_s) + 60` seconds, and at least 120. A
request killed by PHP or by the host leaves a live lease for at most that long. Retries within
that window get 409, which a cron job's retry loop already handles.

### 5.3 The crash window

A request can die after the file row is inserted and before step 4: inline processing runs in
between, for up to 30 s (Tier 1 §7.12). Re-running that request after the lease expires would
create a second file, which is exactly the orphan §4.2 exists to prevent. So the resource is
recorded **as soon as it exists**:
- **Files:** `UploadOptions` gets an optional `?callable $onStored(File)`. `Handle()` calls it
  right after `$file->Insert()`, and it writes `resource_id` in a statement of its own.
- **Keys:** the `resource_id` is written inside `ProvisioningControl::CreateKey()`'s
  transaction, so it commits together with the key, or neither does.

The window that's left is the few microseconds between the file insert and that write.

### 5.4 Replay

- **The replay is rebuilt from the resource's current state,** with the same status and shape as
  the first response:
  - a file: `FileResponse::Owner()`;
  - a key: `POST /provision/keys`'s view, **with a new secret**. Secrets are hashed (Q3 = B),
    so the first one can't be returned, and a key whose secret the caller never saw is an orphan
    using a `max_keys` slot. The replay rotates it and returns the new one: the first one never
    reached anyone. (A duplicate of a request whose response *did* arrive would invalidate the
    secret already stored: the docs say an Idempotency-Key is only retried after a failure.)
- **Header:** `Idempotent-Replayed: true`.
- **A resource deleted since** gets its normal 404 (4043 for a file, 4041 for a key). That's
  honest: the caller deleted it.
- `uses` and `total_size` don't change, because the pipeline doesn't run.
- **Deviation to report:** "the same response" means the same resource in its current state,
  not a byte-for-byte copy of the first body. Storing the body would mean a JSON blob per write
  kept for 24 h. And replaying a stale `processing.status: pending` to a client that polls is
  worse than giving it the current one.

### 5.5 What counts as "a different request"

`request_hash` is the sha256 of a canonical JSON made of:
- **`POST /files`, multipart:**
  - the route and the resolved folder id;
  - the tag names, sorted;
  - the resolved `verbatim`/`no_convert` booleans, so `1` and `true` count as the same request;
  - the original file name;
  - the sha256 of the **uploaded** bytes.

  Hashing the upload is one more streaming pass over it. When nothing re-encodes the file
  (verbatim, or not an image), that hash is reused as `files.sha256`, so it costs nothing extra.
  It runs after the size check, so a file that's about to be refused never gets hashed.
- **`POST /files` with `source_url`:** the same, minus the bytes, plus the normalized URL and
  `name`. The content isn't known before the fetch, and fetching it just to compare would be the
  very work the key is supposed to save.
- **`POST /provision/keys`:** the normalized `name` (trimmed), its only field.

The check runs after auth and parameter parsing, and before the quota check or anything gets
stored.

### 5.6 Where it applies

- **Required:** `POST /files` (multipart and Phase 5's `source_url`) and
  `POST /provision/keys`.
- **Direct uploads** (Tier 3, Phase 3) don't read it: each token is single use, which already
  makes the upload idempotent.
- **`POST /folders` and `POST /shares`** (optional in the blueprint) are **not covered.** A
  retried folder creation gets 4004 (name exists), which a client can treat as success. A
  retried share creation makes a second link, which costs nothing. The helper makes either one a
  5-line change later.
- **CORS:** `Idempotency-Key` goes into `AddAcceptHeaders()`.

### 5.7 Retention

- Records are kept for 24 h. magrathea.conf `idempotency_retention_h` can raise that, and a value
  below 24 is a 5004, because 24 h is the blueprint's floor.
- Purged in `--process` housekeeping (§7).
- A key reused after its record is purged counts as a new request. The docs say not to reuse
  keys.

### 5.8 Docs and tests

- **openapi:** the `Idempotency-Key` header on both routes, the 409 and 422 responses,
  `Idempotent-Replayed`.
- **Skill guide:** Workflows 1 and 8, and the error table. It gives a retry recipe for cron
  jobs: one key per logical upload, saved before the first try; every retry sends the same one;
  a 409 means back off; a 422 means a bug.
- **Unit tests:** hash canonicalization, header validation, and the state machine against a fake
  store.
- **Integration run:**
  - a replay gives the same uuid, and `uses` doesn't change;
  - concurrency: two processes, the first held inside the pipeline by a slow fake processor;
    the second gets 409;
  - a different file with the same key gets 422;
  - a crash right after the insert: the retry after the lease is a replay, with no second file;
  - a takeover after the lease;
  - the purge.

### 5.9 As built (2026-11-18) ✅

- **New:** `shared/Idempotency.php` (the header, the request hash, the lease, the retention,
  `Begin()`, `Run()`), `IdempotencyStore` (interface), `IdempotencyDbStore`, `IdempotencyClaim`,
  `IdempotencyRecord`. Table `idempotency_keys` (`1.5.0_tier2.sql`, Phase 4 block, mirrored in
  `database.sql`). Error codes 40011, 4091, 4221. `Idempotency-Key` in the CORS accept list.
  `idempotency_retention_h` in the conf sample.
- **Changed:** `FileApi::Upload()`, `UploadPipeline` (`AssertAcceptable()` and `HashUpload()`,
  public), `UploadOptions` (`uploadSha256`, `onStored`), `ProvisioningApi`,
  `ProvisioningControl::CreateKey()` (`onCreated`), `cron.php`.
- **Deviation: an `attempt` column, and only the owning attempt can record the resource, inside
  the resource's own transaction.** Found while writing §5.2's lease: on Linux,
  `max_execution_time` counts CPU time, not the time spent waiting on storage, so the lease
  doesn't bound a request. With §5.3 as written, a request that outlived its lease would still
  have inserted its file after a retry took over: two files, the orphan §4.2 exists to prevent.
  Now a takeover bumps `attempt`, and `RecordResource()` is one
  `UPDATE … WHERE id = ? AND attempt = ? AND resource_id IS NULL`, run inside the transaction
  that inserts the file (or the key). The two commit together or not at all. A request whose row
  was taken over gets 4091, its insert rolls back, and its stored object is deleted. That also
  closes §5.3's "few microseconds": no window is left. The lease now only decides how long
  retries get 409 after a crash, not whether they can duplicate.
- **Deviation: `lease_until` is an int** (unix time, the DB's clock: `UNIX_TIMESTAMP()` on both
  sides), not a DATETIME, for the same DST reason as `auth_failures.window_start`.
- **Deviation: a failed request releases its key only when it recorded nothing.** §5.2 step 5
  deleted the row on any throw. A throw after the file's transaction committed (a tag, the
  response) would then have let a retry make a second file. Kept, the record replays.
- **Added: fatal errors release the key.** `Run()` registers a shutdown function that releases
  an unfinished claim, so a PHP fatal (memory, time limit) doesn't leave retries on 409 for the
  whole lease. Only a SIGKILL or a host crash does.
- **Added, found while wiring §5.3: `Handle()` inserts the row and updates both counters in one
  transaction, and deletes the stored object when that fails.** Before, a failed insert left an
  orphan object in storage, and a failure between the insert and the counters left a file that
  `uses`/`total_size` didn't count. Changelog **fix:**. Bulk import goes through the same code.
- **The request hash:**
  - the flags **as sent** (`?bool`, null = absent), not resolved against the key's default: an
    operator changing a key's `verbatim_default` between a try and its retry mustn't turn the
    retry into a 4221;
  - tags as a sorted set, so `b,a,a` and `a,b` are the same request;
  - the route is in the hash, so one Idempotency-Key on two routes is a 4221;
  - invalid UTF-8 in a name is replaced for the hash, not refused (it only has to hash);
  - provisioning: the trimmed name.
- **sha256 reuse:** `UploadOptions::$uploadSha256` becomes `files.sha256` only when
  `file_type !== image || verbatim`. Not "when the path didn't change": a non-verbatim SVG is
  stripped in place, so its path stays the same while its bytes change.
- **Order in `FileApi::Upload()`:** auth → the overflow check → 4001 `file` → the header (40011)
  → folder, tags, flags (4042, 4009) → `AssertAcceptable()` (4001/4002) → hash → `Begin()` →
  replay, or `Handle()` (the quota check is inside it). So a replay is never refused by the
  quota its first try used up.
- **Header:** trimmed of spaces and tabs, then 1–255 characters of `\x20-\x7E`, case kept (the
  column is `ascii_bin`). Not sent = no idempotency. Sent empty = 40011.
- **Lease:** `max_execution_time` + 60 s (300 + 60 when it's 0), 120 s at least. Phase 5 adds
  `source_url_timeout_s` to the max.
- **Retention:** the purge deletes `created_at < NOW() - INTERVAL (retention + 1) HOUR` rows whose
  lease is over, 1000 a run, in `--process` after `auth_failures` (with or without a processor).
  The extra hour covers the DST spring-forward night, when local `created_at` would otherwise cut
  one hour's records short of 24 h. The purge is the only reader of `idempotency_retention_h`,
  so a value below 24 fails `--process` with 5004, never an API request.
- **A replayed provisioning create** rotates the secret (as §5.4) and logs
  `secret rotated (Idempotency-Key replay)`. Only a key with `provisioned = 1`, else 4041.
- **Not done: `Access-Control-Expose-Headers`** for `Idempotent-Replayed`. MagratheaPHP has no
  setting for it, and both routes are backend-only. A browser can't read the header.
- **Tests:** `Shared/IdempotencyTest` (54: the header, the hash, the lease, the retention, the
  state machine against `tests/Support/FakeIdempotencyStore.php`, `Run()`), and one more in
  `File/UploadOptionsTest`. 429 tests, all green.
- **Docs:** openapi (an info paragraph "Retries", the `IdempotencyKey` parameter, the
  `IdempotentReplayed` header, `FileUploadOk`, `IdempotencyKeyReused`, 40011/4091/4221 on both
  routes, 4041 on a provisioning replay; the "Not idempotent yet" paragraph replaced), skill guide
  (Workflow 1 "Retrying an upload" with the cron recipe, Workflow 8, the error table, the
  checklist, both client snippets take a key), `deploy.md` (`idempotency_retention_h`, the
  purge), changelog (**new:**, **fix:**, **db:**).
- **Found: `openapi.yaml` doesn't validate.** `FileProperties.duration` is
  `{ type: integer, description: Seconds, audio/video only. }`: the comma splits the flow
  mapping, so "audio/video only." becomes a key. It dates from `07cb4f4`. **Fixed in Phase 5**
  (§6.7): quoted, and the whole file passes openapi-spec-validator.
- **Not run** (no DB here): the migration and §5.8's integration run. Unverified until they run:
  - a replay gives the same uuid, `uses` doesn't move, `Idempotent-Replayed: true` is sent;
  - two processes, the first held inside the pipeline: the second gets 409;
  - a different file with the same key: 422;
  - a `kill -9` right after the file's commit: the retry is a replay, no second file;
  - a takeover after the lease, and the slow first request then getting 4091 with no object
    left in storage;
  - two retries of a provisioning create: the second replay's secret works, the first's doesn't;
  - the purge.

---

## 6. Phase 5: upload from a URL (§4.3)   [M–L] ✅

### 6.1 Request

- `POST /files` with `source_url` instead of `file`, form-encoded or JSON. It also takes `name`,
  `folder_uuid`, `tags`, `verbatim`, `no_convert` and `Idempotency-Key`.
- Both, or neither → 4001 "file or source_url (exactly one)", the same wording as `POST /shares`.
- After the fetch it's the same pipeline: `UploadPipeline::Handle()` gets an array shaped like a
  `$_FILES` entry whose `tmp_name` is the fetched file. A new `UploadOptions` flag makes
  `MoveToWorkingCopy()` rename that file instead of copying it (§0.7).

### 6.2 `features/File/SafeFetcher.php`: the SSRF rules

It uses ext-curl, one handle per hop.

**The URL:**
- `https` only, and no userinfo (`user:pass@`).
- The host is a DNS name (letters, digits, hyphens, dots) or an IP literal. A name with non-ASCII
  characters goes through `idn_to_ascii()` when ext-intl is loaded, and is refused otherwise.
- Decimal, octal and hex IPv4 forms (`2130706433`, `0x7f.1`) are refused. `parse_url()` lets
  them through, and curl would connect to them.
- Port 443 only, unless magrathea.conf `source_url_ports` lists others.

**Resolving:**
- `dns_get_record($host, DNS_A | DNS_AAAA)`. **Every** address returned must be public: a single
  non-public answer refuses the URL, so an attacker answering `[public, 127.0.0.1]` gets
  nothing.
- No answers → 5021 "could not resolve".

**"Non-public" is one CIDR table in the code,** unit-tested. It doesn't rely on `filter_var()`
flags, whose coverage changes between PHP versions (and PHP 8.1, the floor, has no
`FILTER_FLAG_GLOBAL_RANGE`):
- **IPv4:** 0/8, 10/8, 100.64/10, 127/8, 169.254/16, 172.16/12, 192.0.0/24, 192.0.2/24,
  192.88.99/24, 192.168/16, 198.18/15, 198.51.100/24, 203.0.113/24, 224/4, 240/4.
- **IPv6:** `::/128`, `::1/128`, `100::/64`, `2001::/32` (Teredo), `2001:db8::/32`, `fc00::/7`,
  `fe80::/10`, `ff00::/8`.
- **Prefixes that embed an IPv4 address** are checked as that IPv4: `::ffff:0:0/96`,
  `64:ff9b::/96`, `2002::/16`.

**Connecting:**
- **Only to the checked addresses.** `CURLOPT_RESOLVE` is set to `host:port:ip,…`, so curl never
  resolves the name again (no DNS rebinding). TLS still checks the certificate against the
  hostname (`CURLOPT_SSL_VERIFYPEER` and `CURLOPT_SSL_VERIFYHOST` stay on).
- **Redirects by hand.** `CURLOPT_FOLLOWLOCATION` is off. Up to 3 hops are followed in code:
  each `Location` is resolved against the current URL and goes through every rule again
  (scheme, host, port, resolving, the CIDR table). A 4th → 40012 "too many redirects".
- **Protocols:** `CURLOPT_PROTOCOLS` and `CURLOPT_REDIR_PROTOCOLS` are set to HTTPS only, in
  case the manual redirect handling ever slips.

**Size:**
- A `Content-Length` above the limit aborts before the body arrives (header callback).
- The write callback counts bytes and aborts past the limit either way, since `Content-Length`
  can be absent or lie.
- → 4002, with the same detail as an upload.

**Time:**
- `CURLOPT_CONNECTTIMEOUT` of 10 s.
- `CURLOPT_TIMEOUT` = magrathea.conf `source_url_timeout_s` (default 90, §6.4).
- `CURLOPT_LOW_SPEED_LIMIT`/`TIME` at 1 KB/s for 30 s, against servers that trickle.

**Nothing is forwarded:**
- no cookies, no auth;
- `CURLOPT_PROXY ""` and `CURLOPT_NOPROXY "*"` (otherwise libcurl takes `https_proxy` from the
  environment);
- `User-Agent: MagratheaExplorer/<version>`;
- `Accept-Encoding: identity`, so the size check counts the bytes that get stored, and verbatim
  stores what the server sent.

**Where the body goes:** it's streamed to a file in `processing_tmp_path`, which is inside the
account. Not `sys_get_temp_dir()`.

**Errors:**
- Rule refusals (scheme, host, port, a non-public address, userinfo, too many redirects) →
  **40012** "Source URL refused: <reason>". The reason says "resolves to a non-public address",
  never which address: an internal DNS name shouldn't tell the caller anything about the
  network.
- A non-2xx upstream status (after redirects), a TLS or connection error, a timeout →
  **5021** "Source URL fetch failed: upstream 404" (or "timed out after 90 s", "TLS certificate
  invalid", …).

**Testing.** The resolver and the address policy are injected (an interface, with the real ones
as defaults).
- The URL rules, the CIDR table and redirect resolution are pure functions, unit-tested.
- The curl part runs in an integration run against a local TLS server, with a test policy that
  allows 127.0.0.1 and a CA bundle injected for the self-signed certificate.

### 6.3 The file's name

1. `name`, when the request sends one.
2. Else the `Content-Disposition` filename (`filename*`, UTF-8, first; then `filename`).
3. Else the last path segment, URL-decoded.
4. Else `download`.

It's truncated to 255 characters (the column). The Content-Disposition stored with the object
is sanitized as for any upload (`BuildDisposition()`). Sniffing ignores the upstream
Content-Type, as it ignores a client's.

### 6.4 Limits and timeouts

- **Size limit:** the key's effective limit, the same number `GET /key` shows. **Open (Q5).** The
  recommendation is one number everywhere: Turbopod needs 150 MB multipart uploads anyway, so
  PHP has to allow that size regardless.
- **Quota:** a `Content-Length`, when the server sends one, is checked against `usage_limit_mb`
  before the body. The real check runs afterwards, in `AssertCanImport()`, as today.
- **`source_url_timeout_s` defaults to 90**, under Cloudflare's 100 s proxy timeout.
  - Past 100 s, a request through the proxy gets a 524 at the edge, while PHP carries on and
    stores the file. Turbopod would retry. Idempotency-Key turns that retry into a 409 and then
    a replay, but staying at 90 s avoids the round trip.
  - Add the rest of the pipeline after the fetch: the hash, the `put()` to R2 (150 MB takes
    ~3 s at 50 MB/s), and inline processing (30 s at most).
  - `max_execution_time` has to cover all of it. `deploy.md` already says 300 (Tier 1 §5.4).
- **DreamHost:** its FastCGI request timeout is unknown. Measure it in this phase with a 150 MB
  fetch from R2, and add it to the DreamHost checks (`docs/dreamhost-tests.md`).

### 6.5 Disk

The file is fetched into `processing_tmp_path` and moved, not copied, into the working copy.
After that, the pipeline runs as today. Peak disk use is the fetched file, plus its re-encoded
copy for a non-verbatim image. Not twice the file.

### 6.6 Docs and tests

- **openapi:** `POST /files` as `oneOf` (multipart with `file`, or JSON/form with `source_url`),
  plus 40012, 5021 and 4002.
- **Skill guide:** "Workflow 1: upload from a URL" spells out the rules in plain words (https,
  public hosts, 3 redirects, limits, timeouts), plus the error table.
- **`deploy.md`:**
  - `source_url_timeout_s` and `source_url_ports`;
  - ext-curl in the requirements, and ext-intl for IDN hosts;
  - the web PHP needs outbound HTTPS.
- **Changelog:** a **new:** entry.
- **Unit tests:**
  - every URL refusal;
  - the CIDR table, both families, embedded IPv4, the boundaries;
  - redirect resolution: relative, a scheme change, a hop to a private address;
  - `Content-Disposition` parsing and the name fallback.
- **Integration run** (local TLS server):
  - a 200;
  - a 404 → 5021;
  - 3 redirects pass, a 4th is refused;
  - a redirect to an address the policy refuses;
  - the size abort, with and without `Content-Length`;
  - a server that trickles;
  - verbatim: the stored sha256 = the source's;
  - `Idempotency-Key` with `source_url`.

### 6.7 As built (2026-11-18) ✅

- **Decided (2026-11-18), before any code:**
  - **Q5:** one limit, `GET /key`'s effective `max_upload_size` (PHP's limits included).
  - **Opt-in:** magrathea.conf `source_url_enabled`, absent = off. Off → 40012 "uploads from a
    URL aren't enabled on this instance". Not in the plan: every instance would have gained an
    outbound-connection feature on upgrade.
  - **`name` on both sources:** for a multipart upload it overrides the part's filename, and the
    request hash takes the effective name. Today's multipart ignored `name` silently.
- **New** (`features/File/`): `SourceUrl` (the URL rules, the rebuilt URL, redirect resolution),
  `SourceUrlException` (refused / failed / too large: kept apart from the error table, FileApi
  maps them to 40012 / 5021 / 4002), `SafeFetcher`, `FetchedFile`, `HostResolver` +
  `DnsHostResolver`, `AddressPolicy` + `PublicAddressPolicy`, `RemoteFileName`. Error codes 40012
  and 5021. Conf keys `source_url_enabled`, `source_url_timeout_s`, `source_url_ports`.
- **Changed:** `FileApi::Upload()` (split into `UploadFile()` and `UploadFromUrl()`, plus
  `ParseTags()`, `ParseName()`, `SourceUrlRequestHash()`), `UploadOptions::$consumeSource`,
  `UploadPipeline::Handle()`, `Idempotency::LeaseSeconds()`, `cron.php --process`.
- **Deviation: curl never sees the caller's string.** It gets `SourceUrl::ToString()`, rebuilt
  from the parts that were checked. That closes the parser-differential tricks
  (`https://a\@b/`, `https://a#@b/`), where the checker and curl read different hosts out of one
  URL. A backslash or a control character is refused. Spaces and non-ASCII bytes in the path or
  query are percent-encoded, dot segments are removed and the fragment is dropped. That same
  string goes into the request hash.
- **Deviation: IPv6 is an allowlist.** Only global unicast `2000::/3` passes, minus `2001::/23`
  (Teredo, benchmarking, ORCHID), `2001:db8::/32` and `3fff::/20`. §6.2 had a denylist, and a
  denylist misses whatever gets reserved next. `::a.b.c.d` (IPv4-compatible) falls outside, so
  it's refused. The IPv4 table and the embedded-IPv4 prefixes are as written.
- **Deviation: `localhost`, `*.localhost` and single-label names are refused by name.**
  `dns_get_record()` doesn't read `/etc/hosts`, so `https://localhost/` would have been 5021
  "could not resolve", while §9 expects 40012. No public web host has a single-label name.
- **Ports:** 443 is always allowed, and `source_url_ports` adds to it (§6.2 said "lists others").
- **Only 200 and 203 are success.** Any other 2xx is 5021: a 206 without a Range header means a
  partial file, and a 204 means no file at all.
- **Any `Content-Encoding` but identity → 5021**, not stored. Otherwise gzip bytes would be
  stored as the file, and the size check would have counted the wrong bytes.
- **Deviation: the download is the working copy** (`consumeSource`), with no rename. §6.1's
  rename would still have copied the file: the working copy lives in `sys_get_temp_dir()`, the
  download in `processing_tmp_path`, which can be two filesystems.
- **Deviation: lease = `max_execution_time` + `source_url_timeout_s` + 60 s**, not the max of
  the two. Phase 4 found that `max_execution_time` doesn't count network waits on Linux, so the
  two add up. This only applies to `source_url` requests: the multipart lease is unchanged.
- **Order in `Upload()`:**
  1. auth;
  2. the overflow check;
  3. exactly one of `file` and `source_url` (4001);
  4. enabled (40012);
  5. the Idempotency-Key header (40011);
  6. folder, tags, flags, `name`;
  7. the URL rules (40012);
  8. `Begin()`. A replay downloads nothing.
  
  Then, inside `Run()`:
  1. `AssertCanImport(0)`: an inactive, expired, pending-deletion or count-capped key
     downloads nothing;
  2. the fetch, which checks the Content-Length against the size limit (4002) and against the
     storage cap (4032) before the body;
  3. `Handle()`.
- **Added: no download left behind.**
  - A shutdown function in `Fetch()`, because a PHP fatal skips every `finally`. It's tested
    with a child process that runs out of memory mid-fetch.
  - `SafeFetcher::SweepStale()` in `--process`, for a killed process: files untouched for a
    day, 1000 a run (§7).
  - `tempnam()`'s silent fallback to the shared `/tmp` is refused (5001).
- **Added: a tripwire behind `CURLOPT_RESOLVE`.** `CURLOPT_PREREQFUNCTION` (PHP 8.4 + libcurl
  7.80) aborts before the request is sent when the connected IP isn't one of the checked ones
  (checked here: errno 42). The header callback compares `CURLINFO_PRIMARY_IP` on every version.
- **Found and fixed: `tags`.**
  - A JSON list is accepted. Any other type is 40013 (a form's `tags[]=` was a TypeError, so a
    500).
  - `array_filter()` used to drop a tag named `0`.
- **Name:** §6.3's order, with the final URL's last segment first, then the one that was sent.
  A cut keeps an extension of up to 16 characters. In a quoted `filename`, only `\"` and `\\`
  are escapes, so a Windows path a server sends unescaped still reduces to its last component.
- **The 4001 detail** changed from "file" to "file or source_url (exactly one)".
- **Tests:** `File/SourceUrlTest` (93), `File/PublicAddressPolicyTest`, `File/RemoteFileNameTest`,
  `File/FileApiTest`, `File/SafeFetcherTest` (32), plus the URL hash and the lease in
  `Shared/IdempotencyTest` and `consumeSource` in `File/UploadOptionsTest`. 712 tests, all green.
  - **SafeFetcherTest is §6.6's integration run, in the suite.** It runs against
    `tests/Support/tls-server.php`, a forking PHP HTTPS server with a throwaway `openssl`
    certificate (skipped without the command). Its resolver maps the `.test` names to loopback,
    so every connection goes through `CURLOPT_RESOLVE`.
  - **What it covers:** 200; 404 → 5021; 3 redirects followed, a 4th refused; a redirect to a
    refused address, to http and to another port; the size abort with and without
    Content-Length; a trickle; the total timeout; gzip; a body shorter than its Content-Length;
    an untrusted certificate and one for another host; `https_proxy` in the environment;
    pinning (a name mapped to 127.0.0.2, where nothing listens); the quota callback; the fatal
    cleanup; the sha256 of the stored bytes.
- **Live check, with the production defaults** (real DNS, system CAs, from this machine):
  - fetched: `example.com`, and `www.github.com`, whose 301 to `github.com` was followed;
  - refused (40012): `127.0.0.1.nip.io` (public DNS answering loopback), `169.254.169.254`,
    `[::ffff:127.0.0.1]` and `http://`.
- **Docs:**
  - openapi: the info paragraph "Request bodies"; on `POST /files`, the "From a URL"
    description, `source_url` and `name` on the multipart body, JSON and form bodies
    (`SourceUrlUploadInput`), the 400 list and a 502;
  - skill guide: the bodies table, Workflow 1 "Uploading from a URL", the retry rule, the error
    table (4001, 40012, 40013, 5021), `uploadFromUrl` in both snippets, the checklist and "does
    NOT do";
  - `deploy.md`: the requirements (curl and intl, plus `mbstring`, which has been missing since
    Phase 3), the optional keys, a new "Uploads from a URL" section, and `processing_tmp_path`;
  - `dreamhost-tests.md` step 6: DNS timing, and the 150 MB fetch that finds the FastCGI
    timeout;
  - the conf sample, and the changelog (**new:**, **improvement:**).
- **Not run** (no DB here): the whole `POST /files` path with `source_url`, and §6.4's DreamHost
  measurement. Unverified until they run:
  - a JSON `POST /files` with `source_url` stores the file, named from `name`, then the
    Content-Disposition, then the URL; verbatim keeps the source's sha256;
  - `source_url_enabled` off → 40012;
  - an Idempotency-Key retry of a completed URL upload is a replay that downloads nothing;
  - a Content-Length over the storage cap → 4032 before the body;
  - the 150 MB R2 fetch on DreamHost, timed.
- **Found and fixed after the first pass (2026-11-18):**
  - **A DNS oracle.** "could not resolve" (5021) and "resolves to a non-public address" (40012)
    told a key holder whether a name exists in the server's resolver (split-horizon names like
    `db.corp.example.com`). **Deviation from §6.2** ("no answers → 5021"): a name with no
    addresses now gets the same 40012 as a private one, word for word ("the host doesn't
    resolve to a public address"). A typo is a 40012 now, which is right: retrying it won't
    help. What does need a retry is a lookup that *failed*, and `dns_get_record()` tells those
    apart: `[]` for NXDOMAIN or no records, `false` for TRY_AGAIN/NO_RECOVERY (checked here:
    `dnssec-failed.org` gives `false`). `HostResolver::Resolve()` returns `null` for those, and
    only that is 5021 "DNS lookup failed for <host>", which says nothing about the name.
  - **`/settings` gets `source_url_enabled`**, so a client can check first instead of finding
    out from a 40012. An unreadable value is `false` there, never a 5004: `/settings` is public
    and the app reads it. The upload itself still answers 5004, naming the key.
    `SystemApi::SourceUrlEnabled()`, `Shared/SettingsTest`.
  - **`openapi.yaml` validates** (openapi-spec-validator): Phase 4's `FileProperties.duration`
    line is quoted.
  - Also: `dreamhost-tests.md` step 6's DNS one-liner did `count(false)` (a TypeError) on the
    very failure it checks for; the skill guide's checklist said to check sizes against
    `/settings`, which its own Step 0 says is only the instance default.
  - Tests: 719, all green. The live check again: a nonexistent name and `127.0.0.1.nip.io` get
    the same 40012; `dnssec-failed.org` gets 5021.

---

## 7. Housekeeping in `--process`

This plan's periodic work goes into the existing every-minute `cron.php --process` run, after
the jobs and under the same lock. That avoids another cron line, which on DreamHost is another
thing to forget. Each item has its own cap, so the queue never starves. Tier 3 adds its own rows
to the same run (Tier 3 §7).

| Work | Phase | Cap per run |
|---|---|---|
| purge `auth_failures` older than a day ✅ (also without a processor) | 3 | one DELETE |
| purge `idempotency_keys` past retention ✅ (also without a processor) | 4 | 1000 rows |
| remove `source_url` downloads a killed request left in `processing_tmp_path` (untouched for a day) ✅ | 5 | 1000 files |

---

## 8. Phase 6: release   [S]

Together with Tier 1's Phase 8, which is the same release (Tier 3's Phase 5 does the same for
its part):
- Review `1.5.0_tier2.sql` (merging it into Tier 1's file is optional) and mirror it in
  `database.sql`. Run all of 1.5.0's migrations (tier1, tier2, and tier3 if it ships) on a
  throwaway copy of the 1.4.0 schema, then `models:verify` (as Tier 1 did).
- Tier 1's "Upgrading to 1.5.0" section in `deploy.md` gains:
  - the extra migration file(s), in order;
  - the new magrathea.conf keys (`client_ip_header`, `idempotency_retention_h`,
    `source_url_timeout_s`, `source_url_ports`);
  - the **`--run` cron line**;
  - key secrets are hashed: the admin shows them once; delete the pre-migration dumps once
    settled (already in `deploy.md` §6, move or link it);
  - turn provisioning on (admin → Provisioning) where a project provisions keys.
- openapi, the skill guide and `/error-codes` are already done phase by phase. Copy
  `docs/skills.md` (the source) into `~/.claude/skills/magrathea-explorer-api/SKILL.md`.
- The changelog date is set once, in Tier 1's Phase 8, for all three tiers.
- Send Turbopod the updated openapi, skill guide and error codes (blueprint §8).

---

## 9. Test checklist (manual, per phase, on both storage drivers)

- [ ] `Content-Type: application/json; charset=utf-8` body on `POST /folders` → parsed; invalid JSON → 40010
- [ ] JSON `POST /files` with `source_url` → not mistaken for a `post_max_size` overflow
- [ ] `/changelog` shows the **breaking:** and **db:** entries, wrapped lines included
- [ ] every datetime in `GET /key`, `/files`, `/folders`, `/shares` and the public shares ends in `Z` and matches the DB's local value
- [ ] the tier2 migration on a copy of a tier1 DB: every existing key still authenticates (upper-case secret too); `access_keys.uuid` is gone
- [ ] key secret on `/provision/keys` → 401; provisioning secret on `/files` → 401; provisioning turned off → 401
- [ ] `POST /key/rotate-secret` and admin "Rotate secret" → the old secret is 401 on the next request
- [ ] admin: a new key's secret shows once (gone on reload); no UUID field on the key form; saving keeps the expiration
- [ ] 11 bad provisioning secrets from one IP → 429 with `Retry-After`; another IP isn't affected; with `client_ip_header`, the header's IP is the bucket
- [ ] provisioning with `max_keys` 2 and a 1000 MB size limit: a third key → 4033 (also with two in parallel for the last slot); the keys have 1000 MB; a body with `usage_limit_mb` → 40013; a taken name → 4094
- [ ] provisioned key with `verbatim_default`, `max_upload_size` 150 MB and the preset profile → a 140 MB upload is verbatim, with renditions
- [ ] provisioned key: `DELETE /key` with its own secret → `--run --dry-run` lists it after 14 days; cancel removes it
- [ ] `cron.php --process` purges `auth_failures` windows older than a day, with and without a processor
- [ ] same Idempotency-Key twice after success → same uuid, `uses` +1 only once, `Idempotent-Replayed`
- [ ] same key while the first upload is still running → 409; a different file → 422
- [ ] `source_url` to `https://127.0.0.1/`, `https://localhost/`, `http://…`, `https://169.254.169.254/` and a public host redirecting to a private one → 40012
- [ ] `source_url` of a 150 MB R2 object → stored, sha256 matches (verbatim), time measured on DreamHost
- [ ] `source_url` above the limit, with and without `Content-Length` → 4002, nothing left in `processing_tmp_path`
- [ ] `source_url` with `source_url_enabled` absent → 40012 "not enabled"; with `name` on a multipart upload → the file has that name
- [ ] `models:verify` and `check` clean

---

## 10. Phase order and size

| Phase | Content | Size | Depends on | Migration (`1.5.0_tier2.sql`) |
|---|---|---|---|---|
| 1 ✅ | JSON detection, the POST /files overflow check, /changelog parser, `files.size` bigint, error codes | S | — | yes |
| 2 ✅ | UTC timestamps | S | 1 (changelog) | — |
| 3 ✅ | Hashed key secrets, `POST /provision/keys` (max_keys, template), rotate-secret, rate limit, admin | M | 1, 2 | yes |
| 4 ✅ | Idempotency-Key | M | 3 (provisioning credential) | yes |
| 5 ✅ | Upload from a URL | M–L | 1, 4 | — |
| 6 | Release, with Tier 1's Phase 8 | S | all | review |

Blueprint §8 asks for §4.1 and §4.2 first, and Phases 3 and 4 come right after the two small
ones. Phase 3 is done, so Phase 4 has both credentials to key its records by. Don't start Phase 5 before Phase 4: both change the head of `FileApi::Upload()`.
Tier 3 can start once Phase 2 is in. Its direct uploads (Tier 3, Phase 3) land after Phase 5,
since they touch the same code.

---

## 11. Answers for Turbopod (blueprint §7 and §8)

| Item | State today | Effort | Deviations from the blueprint |
|---|---|---|---|
| §4.1 provisioning | done (Phase 3) | M | **create only:** `POST /provision/keys {name}` → the key + its secret. One provisioning secret per instance (`xpv_…`), made in the admin. **No list/get/PUT/DELETE by id, no key `id`:** each key is managed with its own secret (`GET /key`, `/key/usage`, `DELETE /key`, new `POST /key/rotate-secret`). **Settings are the operator's**, copied from admin → Provisioning at creation; the body can't set any (40013). `max_keys` (4033); names unique per instance (4094); 10 failed authentications per 15 min per IP (4291). **Key secrets are hashed: shown once, never readable again** |
| §4.2 Idempotency-Key | done (Phase 4) | M | a replay is the same resource in its current state, not a byte copy of the first response; **a replayed `POST /provision/keys` returns a new secret** (only the first one's hash exists), so retry only after a failure; errors aren't stored (a retry runs again); 409 (4091) while running, 422 (4221) for a different request; `Idempotent-Replayed` header (not readable from a browser); the flags count as sent; folders and shares not covered |
| §4.3 upload from a URL | done (Phase 5) | M–L | **off unless the instance turns it on** (`source_url_enabled`); https, port 443 (configurable), public addresses only (IPv6: global unicast only); 3 redirects; 90 s for the whole download; the size limit is the same effective limit as `GET /key`, PHP's limits included (Q5); only a 200/203 without `Content-Encoding` is stored; refused 40012, fetch failed 5021; `name` works for both sources |
| §4.4 UTC timestamps | local time, no offset | S | switched in place: no `_utc` fields, no API version bump |
| §5.5 JSON | broken as described, and also blocks §4.3's JSON requests | S | fixed in the framework plus an Explorer override; invalid JSON → 400; moved from Tier 3 into this plan |

- **Feasibility of §4.1 and §4.2 (asked first): both are feasible,** and nothing in Tier 1 blocks
  either. `UploadOptions` is the place for §4.2's callback, and `Key::EffectiveMaxUploadSize()`
  already serves every upload source. The one design consequence for Turbopod: a replay of
  `POST /provision/keys` returns a *new* secret (secrets are hashed, §5.4).
- **Without an `Idempotency-Key`** (Phase 4), a create whose response is lost still leaves a key
  Turbopod has no secret for, and the retry gets 4094: the operator deletes or rotates it.
  Workflow 8 says so.
- **§7 Q4 (consumers of the timestamps):** §0.4. The explorer app gets *more* correct, social
  stores the field but never reads it, and nothing else was found.
- **Not part of this plan's answers, but Turbopod depends on it:** offboarding only completes
  where `cron.php --run` is scheduled (§0.1).
- Tier 3's answers (§5.1–§5.4) are in Tier 3 §11.

---

## 12. Decisions

Decided 2026-11-18:
- **Q1, release:** everything in 1.5.0 (§1). My recommendation was 1.5.0 with Tier 1 alone, then
  1.6.0.
- **Q2, timestamps:** switched in place (§3.1).
- **Q4, provisioner limits:** `max_keys` plus ceilings on what a provisioner can grant.
  My recommendation was `max_keys` only. **Superseded the same day** by §4.1: the request can't
  set limits, so only `max_keys` is left.
- **Q3, key secrets: B, hashed** (below). I had recommended A, then changed to B once
  provisioning meant thousands of keys nobody needs to see.
- **Provisioning (§4.1):** limits are the operator's, so create-only; one instance secret; one
  `provisioning` row, not AppConfig (`varchar(255)` can't hold a rendition profile).
- **Q6, a key's public id:** moot, no route takes a key id.
- **Q5, the URL fetch's size limit:** the same effective limit as `GET /key` (§6.7). Also decided
  with it: `source_url` is opt-in per instance, and `name` works for both sources.

### Q3, key secrets (decided: B)

`access_keys.uuid` is the bearer key every integration sends (social, the explorer app's login,
Turbopod's workspace keys). It's stored in plaintext. Three options:

| Option | The admin can show it | A DB dump exposes it | Cost |
|---|---|---|---|
| **A. Keep plaintext** (as today) | yes | **yes**, every key | none |
| **B. Hash** (sha256, lookup by hash) | **never again**, not even to the operator; a lost secret can only be rotated, and the integration updated | no | the admin form loses the UUID field; an Idempotency replay of a key creation must keep the secret encrypted for 24 h; a migration hashes every existing key (they keep working: the integration still sends the same secret) |
| **C. Hash + encrypt** | yes, decrypted with a key from magrathea.conf | no (the dump has the hash and the ciphertext, not the conf key) | the same as B, plus an encryption key to manage. If it's lost, auth still works (it goes by the hash), but the admin can't show secrets any more. On the server itself it protects nothing: admin access is shell access (Tier 1 §6.7), and the conf is right there |

First recommendation: A for 1.5.0. Changed to **B**: with provisioning, Turbopod creates
thousands of keys nobody needs to see, and one dump would expose all of them; being able to see
a secret only matters for the few admin keys, and those can be rotated. C protects a dump only
as long as the dump and magrathea.conf never leak together, and they sit on the same server.
**Decided B (2026-11-18).**

### Minor (all decided now)

| # | Decision | Recommendation | Alternative and its cost |
|---|---|---|---|
| ~~Q5~~ | URL fetch size limit | Decided: the same effective limit as `GET /key` | — |
| ~~Q6~~ | Key's public id | Moot (§4.1): no route takes a key id | — |

Q7 and Q8 belong to Tier 3 (Tier 3 §12).

---

## 13. Out of scope

- Managing keys by id (list, get, PUT, DELETE, rotate for someone else's key), the key's public
  id, and limits set by the consuming project, ceilings with them (§4.1). A job for when
  Turbopod needs plans.
- More than one provisioning credential (a `provisioners` table).
- Rate-limiting failed key authentications.
- Immediate key deletion through the API.
- Idempotency on `POST /folders` and `POST /shares`.
- Deleting objects in batches (`DeleteObjects`, 1000 per call) to speed up `DeleteKeyNow()`
  (§0.1). A good follow-up once there are real workspaces to offboard.
- Tier 3 (§5.1–§5.4): its own plan, `.claude/plan-1.5.0-tier3.md`.
- Everything in blueprint §6.

## 14. Risks

- **The XXE hole stays open until Tier 2 and Tier 3 ship** (§1). Every 1.4.x instance keeps it.
  Tier 1 §0.1's interim measure is the only cover: disable keys held by parties that aren't fully
  trusted.
- **Offboarding at scale.** Deleting a large workspace means one storage call per object, in one
  daily cron run. It converges, but it's slow, and on the DreamHost instance it doesn't run at
  all until the `--run` line exists.
- **DNS on shared hosting.** `dns_get_record()` has no timeout of its own: a slow resolver stalls
  the request until PHP's or the proxy's limit (`source_url_timeout_s` only starts after it).
  Measure it on DreamHost (`dreamhost-tests.md` step 6).
- **Unknown DreamHost FastCGI timeout** for long URL fetches (§6.4). Not measured in Phase 5 (no
  access from here): `dreamhost-tests.md` step 6 says how.
- **Each URL fetch holds a PHP worker** for up to `source_url_timeout_s`. A key holder sending
  many in parallel can tie up the pool. That's why it's opt-in (§6.7).
- **Cloudflare:** when the API host is proxied, the 100 s timeout applies to URL fetches and the
  100 MB body cap to multipart uploads. Idempotency-Key makes the 524 case safe to retry, not
  fast.
- **Key secrets in old dumps.** Hashed since this phase, but every dump from before the
  migration has them all in plaintext until it's deleted (`deploy.md` says so).
- **The hashing migration is one-way.** A bug in it breaks every key, and the dump is the only
  undo. It hasn't run against a real DB yet (§4.8).
- **A lost rotate or create response** locks a key out or orphans it until the operator acts.
  Phase 4 fixes the create when the caller sends an `Idempotency-Key`; the rotate stays the
  caller's job.
- **A duplicate provisioning create kills a secret.** A replay rotates the key's secret (§5.4),
  so a retry of a create whose response *did* arrive invalidates the secret the caller stored.
  The docs say to retry only after a failure; a client that retries blindly locks its tenant
  out until it rotates again.
- **MagratheaPHP leaks DB query data** in API error responses in every mode (§4.6), on every
  route. Phase 3's writes catch it; the rest needs the framework fix.
- **Framework dependency.** The JSON fix belongs in MagratheaPHP. Explorer's override covers it
  until a framework release ships, and then it's dead code, to be removed with a comment pointing
  at the release.
- **Admin access means shell access** (Tier 1 §6.7), and now it also means minting the
  provisioning secret.
  Nothing new to accept, but worth knowing.
- **DST** on instances in a zone that has it: one ambiguous hour a year in the UTC conversion
  (§0.4).
