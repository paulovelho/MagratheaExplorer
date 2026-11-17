# 1.5.0 — Turbopod Tier 1: implementation plan

Covers §3 of `.claude/blueprint-1.5.0.md` (verbatim storage, sha256, per-key upload limit,
processing pipeline). Everything ships together as **1.5.0** but is built in phases. Each phase
leaves the branch working, with its own docs, and can be reviewed by itself.

Written against the working tree on `version/1.4.1`, which holds the unmerged **1.4.0**
(MagratheaPHP 3) work.

**Status (2026-11-17):** done: §1.4 (test harness), §2.0 (XXE fix), and §2.4's version bump.
Everything else is open. Phase 1 is **not** finished: §2.1–2.3 (boolean parsing) remain.

Every decision is settled (2026-11-17): the XXE fix ships in 1.5.0 (§2.0), oversize
non-verbatim images → 413 (§3.4), renditions don't count toward usage (§7.9), there are no
video/PDF handlers (§8), and public share links show only `thumbnail_url` (§7.7). The DreamHost
check ran (§1.2): the engine is pyvips. **Open:** the processor written in Python (recommended)
or Imagick to keep the project PHP-only (§1.2).

---

## 0. Findings: the code vs the blueprint's "Today" lines

The blueprint was written from the skill guide. The code is worse than the guide says.

### 0.1 SECURITY: SVG upload is an XXE file-disclosure hole (live in 1.4.0)

`MetadataStripper::StripSvg()` (`api/src/features/File/MetadataStripper.php:251`) parses with
`LIBXML_NONET | LIBXML_NOENT`. `LIBXML_NOENT` **turns entity substitution on**; `LIBXML_NONET`
only blocks the network, not `file://`. Verified on PHP 8.4.27 / libxml 2.9.14: an SVG with
`<!ENTITY x SYSTEM "file:///…/secret.txt">` comes back from `saveXML()` with the file's contents
inlined. That output is stored and served from a public URL.

Every non-verbatim SVG upload goes through this. Anyone holding a key can read any file the PHP
user can: `api/configs/magrathea.conf` (DB password), `api/configs/storage.conf` (R2/S3 secret
keys).

**Fix:** drop `LIBXML_NOENT`, and refuse (4151) any SVG with a `<!DOCTYPE` that declares
entities. **Decided: no separate patch. It ships with 1.5.0, as the first item of Phase 1
(§2.0).** **✅ Implemented (§2.0).** Until 1.5.0 is deployed, the exposure is every key holder. Any key held by a party that isn't
fully trusted should be disabled until 1.5.0 is deployed.

Forensics, after deploying: `saveXML()` keeps the DOCTYPE, so any stored `.svg` containing
`<!ENTITY` is an attempt. On R2, list the `.svg` objects and grep them. If any turn up, rotate
the DB password and the R2/S3 keys.

### 0.2 `no_convert=1` does not mean "untouched" (§7 Q1)

`UploadPipeline::Handle()` (`UploadPipeline.php:64-80`) runs **both** steps on every image:

1. `MetadataStripper::Strip()`: always, regardless of `no_convert`.
2. `ImageProcessor::ReencodeAndConvert()` (`ImageProcessor.php:88-105`): `no_convert` only
   picks the output extension (`:96`). The image is still **decoded and re-encoded through GD**.
   GD is 8 bits per channel and writes no `iCCP`/`pHYs`/EXIF, so a 16-bit PNG becomes 8-bit and
   loses its ICC profile and DPI, and a JPEG is recompressed at q90 and loses its ICC.

So §3.1 is bigger than "skip a flag": verbatim has to bypass both steps.

### 0.3 Existing bug: EXIF orientation is lost on every non-verbatim JPEG

The stripper carefully keeps the orientation tag (`MetadataStripper.php:47-53`). Then GD
re-encodes the pixels **unrotated** and writes no EXIF. A portrait phone photo (orientation 6)
is stored sideways, and so is its thumbnail. Not Turbopod's problem, since it uses verbatim.
Fixing it costs about 10 lines in the GD path (`imagerotate`/`imageflip` before saving), so it's
in Phase 2 as an optional extra.

### 0.4 Today's thumbnail (§7 Q2)

- GD, **synchronous**, built at upload from the already-converted file (`UploadPipeline.php:73-79`).
- Size: `ConfigApp` `thumbnail_size` (default 200), square, crop-to-fill.
- Format (`ImageProcessor.php:132`): **png if the stored file is png, jpg otherwise**. A default
  upload is converted to webp, so its thumbnail is **jpg**, and transparent pixels come out
  **black** (GD truecolor default, no flatten).
- Crop quirk: `ResampleCalculator::CutHorizontal()` (`ResampleCalculator.php:88-96`) leaves
  `src_y = 0`, so **portrait images are cropped from the top**. Landscape images are centered.
- Animated GIF: first frame. SVG: none.

### 0.5 Memory: nothing streams except storage `put()`

- `MetadataStripper` reads the whole file with `file_get_contents()` and builds a second copy
  (`:44`, `:113`, `:139`, `:166`): about 3× the file size in memory.
- `ImageProcessor::IsAnimatedGif()` reads the whole file (`:26`).
- GD decodes at about 4–5 bytes per pixel: **100 MP is about 450 MB** before the thumbnail canvas.
- `docker/php.ini` sets `memory_limit=4096M` in dev, so dev hides all of this. `deploy.sh`
  writes `512M` on servers (`scripts/deploy.sh:287`).

A 60 MB / 100 MP non-verbatim PNG is a PHP fatal today. Verbatim avoids the stripper and the
re-encode, but **the inline GD thumbnail still decodes the full image**. Phase 2 has to guard
that until Phase 6 replaces it.

### 0.6 Boolean parsing

`FileApi.php:31`: `$noConvert = !empty($post["no_convert"]);`. It's the only request flag in the
API (checked). The explorer app never sends `no_convert` (`app/src/api/files.js:26-28`), so the
fix doesn't affect it.

### 0.7 Error-code space: the 400 block is full after 4009

HTTP status is `intval(code / 10)` for 4-digit codes. 400-class codes are `4000–4009`, and
`4001–4008` are taken (`api/src/error-manager/error_codes.conf`). This plan uses **4009** for
booleans. Any further 400 has to be 5 digits (`400xx`, status `code / 100`). 413 → `4131` is free.

### 0.8 No test suite exists

`phpunit/phpunit ^10.5` is in `require-dev`, but there's no `tests/`, no `phpunit.xml`, and no
Imagick in the dev image (`docker/Dockerfile` installs `gd exif xdebug` only).

---

## 1. Phase 0: prerequisites (no 1.5.0 product code)

### 1.1 Branches (recommendation, git is yours)

The branch is called `version/1.4.1` and holds 1.4.0. Merge 1.4.0 to master and tag it, then
branch `version/1.5.0` from master. That way, a bug in the framework migration and a bug in this
plan never share a release. The XXE fix is in Phase 1 (§2.0); there's no separate patch.

### 1.2 DreamHost feasibility test (blueprint §7 Q5): decides the processor engine

Deliverable: `api/processor/diag/dreamhost-check.php`. It's a CLI script you run **from cron**
on the target server, because cron is where the process watcher and the CLI's php.ini apply.
It reports:

- The PHP CLI binary actually used, its version, and its `php.ini`. On DreamHost the CLI `php`
  is often not the site's version: the result is the exact path for `processor_command`.
- `proc_open` enabled. `imagick` loaded in the **CLI**. ImageMagick version, quantum depth
  (Q8/Q16), webp write support.
- Whether `MAGICK_CONFIGURE_PATH` set through `proc_open`'s env is honored: a policy that denies
  `PNG` must make a read fail.
- The actual test: generate a ~105 MP RGBA PNG with a **streaming PHP writer** (row by row,
  `deflate_add`, flat memory). No need to upload a 60 MB file. Then, in a child process with
  Imagick resource limits (memory 128 MB, map 256 MB, disk 4 GB, temp inside the account),
  resize it to a 1200 px webp. Report wall time, peak RSS, exit status, and whether the watcher
  killed it.
- Whether `python3 -m pip install --user "pyvips[binary]"` is possible, and the same test
  through pyvips if so.

My expectation, unverified: Q16 ImageMagick needs about 840 MB of pixel cache for 105 MP RGBA,
so it spills to disk under those limits and takes minutes, which is exactly what the watcher
kills. Don't build Phase 5 until this has run.

**Results so far (2026-11-17, quick run on `iad1-shared-e1-19`, interactive):**
- `proc_open` & co usable. CLI `/usr/local/php84/bin/php` (8.4.27), CLI `memory_limit` 128M.
- Imagick 3.8.1 / ImageMagick **6.9.12-98 Q16**, PNG + WEBP. Default thread limit already 1.
- `MAGICK_CONFIGURE_PATH` through `proc_open`'s env: **honored** (a deny-PNG policy blocked
  the read). The system `policy.xml` denies **nothing**, so the processor's own policy is required,
  not optional.
- Python 3.12.3 (Ubuntu 24.04, PEP 668): pyvips installed in a venv,
  `~/explorer-venv` → **pyvips 3.2.0 / libvips 8.18.7**. A pyvips engine's `processor_command`
  would be `["/home/platypusweb/explorer-venv/bin/python", ".../processor.py"]`.
- crontab: lines below `###--- END DREAMHOST BLOCK` are preserved. `setlock` is available.
  Found while looking: **no `cron.php --run` line**, so scheduled key deletions never run on
  this instance (reported 2026-11-17).
- ulimits all unlimited (128 CPUs, 450 GB): the watcher is the only limit, and only the
  cron `--heavy` run can show it.
- **Heavy run, from cron** (generated 10759×9759 RGBA 8-bit, 14 MB → 1200 px webp, single thread):

  | engine | wall | CPU | peak RSS |
  |---|---|---|---|
  | Imagick, memory 128 / map 256 / disk 4096 MB | 7.98 s | 3.57 s | 316 MB |
  | Imagick, no limits | 3.18 s | 2.84 s | 1,119 MB (not killed) |
  | **pyvips**, `VIPS_CONCURRENCY=1` | **1.54 s** | **1.36 s** | **127 MB** |

  - Imagick's `memory` limit doesn't cap RSS: map is RAM too.
  - The watcher tolerated a 1.1 GB, 3 s spike. Its threshold for sustained use is still unknown,
    and it doesn't matter at pyvips' 127 MB.
- **Engine #1: pyvips.** ⚠ DECISION (open): processor written in Python (recommended), or
  Imagick to keep the project PHP-only. §6 assumes Python.
- Still to check by hand: every-minute cron (DreamHost policy) and a 150 MB upload through the
  front proxy (do it in Phase 4).

Also check: is every-minute cron allowed on the plan? Queue latency equals the cron interval.
And does a **150 MB upload** through DreamHost's front proxy survive (body size and timeouts),
not just PHP's limits?

### 1.3 Dev environment

- `docker/Dockerfile`: `python3` + `python3-venv`, and a venv with `pyvips[binary]` (the same
  wheel as the server, not a distro libvips, so dev and prod run the same libvips build).
- `docker/php.ini`: `memory_limit=512M`, the same as servers, so dev hits the same OOMs.
- `.gitignore`: `api/tmp/` (processor job dirs). **Done.**

### 1.4 Test harness ✅ done (with §2.0)

- `api/phpunit.xml` + `api/tests/`. Run from `api/`: `vendor/bin/phpunit`.
- Bootstrap: `tests/bootstrap.php` requires **`src/_autoload.php`**, not `vendor/autoload.php`.
  Composer's autoloader alone can't load app classes, because Magrathea's autoloader only knows
  the folders registered in the bootstrap. The code-folder list moved out of `_inc.php` into
  `_autoload.php`, so `_inc.php` and the tests share one list. The bootstrap also sets an empty
  `test` environment: `magrathea.conf` is never read. A test that needs config keys or the DB
  sets them up itself (`Config::SetConfig`, `Database::Mock()`).
- `failOnWarning`, `failOnRisky`, `failOnEmptyTestSuite`; no result cache (nothing to gitignore).
- Unit tests go here: boolean parser, rendition-profile validator, size parsing.
- Processor contract fixtures run outside phpunit (§6.6) so that they also run on the server.

---

## 2. Phase 1: XXE fix + boolean parsing (§3.1, part)   [S]

### 2.0 SVG XXE (§0.1): first commit of the branch ✅ done

- `MetadataStripper::StripSvg()`: flags `LIBXML_NONET` only. **No `LIBXML_NOENT`.** A comment
  there says why it must never come back.
- New `features/File/UploadValidator.php`. `Validate($path, $detected)` is called in
  `UploadPipeline::Handle()` right after `MimeSniffer::Detect()`, before any processing.
  Phase 2's verbatim branch is therefore covered with no extra work, and so is `ImportPipeline`
  (it goes through `Handle()`). It holds one `match` arm per type that needs a check.
  Future pre-processing content checks go there, not in `MimeSniffer` or `MetadataStripper`.
  `Strip()` catches every `Throwable`, so a refusal inside it would be silently swallowed.
- SVG rule. **Deviation: libxml, not a byte scan of the prolog.** A byte scan is bypassable,
  verified on PHP 8.4.27 / libxml 2.9.14: a UTF-16 SVG is still sniffed as `image/svg+xml`, the
  old code leaked through it, and its bytes contain no ASCII `<!DOCTYPE`. Padding the prolog with
  a long comment defeats any fixed-size window. The check works like this instead:
  - `XMLReader` streams the prolog (`LIBXML_NONET` only: no substitution, no external DTD) and
    stops at the root element, so memory stays flat at any file size.
  - It takes libxml's **own serialization** of the DOCTYPE (`readOuterXml()`): UTF-8 and
    normalized, whatever the file's encoding.
  - The internal subset is checked against a whitelist. **Decided: plain entities pass**, so
    old Illustrator exports (`<!ENTITY ns_svg "http://…">`) keep uploading. A plain entity is
    `<!ENTITY name "text">` with no `&`, `%` or `<` in the value, at most 1 KB per value and
    64 KB per subset. Comments are ignored. Anything else (`SYSTEM`/`PUBLIC`/`NDATA`, parameter
    entities, nested or character references, `ATTLIST`/`ELEMENT`, PIs) → 4151 `"SVG DTD
    declarations other than plain entities"`.
  - **Fails closed:** an SVG libxml can't read up to its root element → 4151 `"SVG not
    well-formed"`. When libxml fails, the reader errors out before it returns the DOCTYPE, so
    what can't be inspected isn't stored. An SVG broken only *after* its first chunk still goes
    through as before (the stripper fails and the file is stored raw), which is harmless.
- Tests: `api/tests/File/UploadValidatorTest.php` (accepted/refused fixtures, UTF-16, 2 MB
  comment padding, oversized value) and `MetadataStripperSvgTest.php`. The stripper is tested
  **with the validator bypassed**: `SYSTEM` entity, parameter entity, external DTD and UTF-16,
  each pointed at a secret file written at run time, must never be expanded. Fixtures in
  `api/tests/fixtures/svg/` (text only; the UTF-16 and padded variants are built at run time).
  19 tests green. Not done: a mutation run with `LIBXML_NOENT` put back, to see the stripper
  tests fail.
- Changelog **fix:** under 1.5.0 (written). Docs: the 4151 list in `docs/skills.md` and the
  `POST /files` description in `docs/openapi.yaml`.
- Server check before deploying: `XMLReader` (ext-xmlreader) has to be loaded in the **web**
  PHP on DreamHost (`php -m` doesn't show the web SAPI). Without it, every SVG upload is a 500.

### 2.1 New: `api/src/shared/RequestBool.php`

```php
/** null = field absent. Throws 4009 on anything not in the two lists. */
public static function Parse(array $input, string $field): ?bool
```

- Absent key → `null` (the caller applies its default).
- PHP `bool` passes through (JSON bodies). Int `1`/`0` are accepted.
- Strings, trimmed and case-insensitive: `1 true yes on` → true; `0 false no off ""` → false.
- Anything else → `4009 "Invalid boolean value: <field>"` (HTTP 400).

### 2.2 Use it

- `FileApi::Upload()`: `no_convert` (and `verbatim` in Phase 2).
- Every new flag from here on goes through it.

### 2.3 Error code

`error_codes.conf`: `4009 = "Invalid boolean value"`. `api/cache/error_codes.json` is a cache of
`/error-codes`: clear it on deploy (it's already in the "Updating an instance" steps, or add it).

### 2.4 Version bump (start of the 1.5.0 work) ✅ bump done (with §2.0)

Bump `api/version` and `docs/openapi.yaml` `version:` to `1.5.0` now, and open `## 1.5.0` in
`api/changelog.md` with the date left as `unreleased` until the release. **Done:** all three
bumped, with `## 1.5.0` / `unreleased` holding the §2.0 entry. The boolean entry below comes
with §2.1.

Found while doing it: the `changelog` endpoint's parser keeps only the **first line** of each
entry and drops the wrapped continuation lines, so every multi-line entry (all of 1.4.0's) is
served cut off. Not fixed; worth a **fix:** of its own. Every phase appends
its own entries. The boolean entry is **breaking:** `no_convert=false` was true; unknown
values are now a 400.

---

## 3. Phase 2: verbatim storage (§3.1)   [M]

### 3.1 Schema

```sql
ALTER TABLE `access_keys`
	ADD COLUMN `verbatim_default` tinyint(1) NOT NULL DEFAULT 0 AFTER `usage_limit_mb`;
ALTER TABLE `files`
	ADD COLUMN `verbatim` tinyint(1) NOT NULL DEFAULT 0 AFTER `no_convert`;
```

Models: `Key` gets `"verbatim_default" => "boolean"`, `File` gets `"verbatim" => "boolean"`.
Run `vendor/bin/magrathea models:verify` after each schema step.

### 3.2 Request resolution (`FileApi::Upload()`)

```
verbatim  = RequestBool(verbatim) ?? key.verbatim_default
noConvert = verbatim || (RequestBool(no_convert) ?? false)
```

`verbatim=0` on a key with `verbatim_default=1` means "not verbatim". `UploadPipeline::Handle()`
replaces its bool parameters with a small options value object
(`UploadOptions{verbatim, noConvert, tags, enforceQuota, inlineProcessing}`). The signature
grows again in Phases 3 and 6, and `ImportPipeline.php:70` passes positional `false`s today.

### 3.3 Pipeline (`UploadPipeline::Handle()`)

| Step | verbatim | not verbatim |
|---|---|---|
| `MimeSniffer::Detect()` + 4151 refusal | yes | yes |
| `MetadataStripper::Strip()` | **no** | yes |
| `ImageProcessor::ReencodeAndConvert()` | **no** | yes |
| `getimagesize()` → `width`/`height` | yes (header only) | yes |
| thumbnail (until Phase 6) | guarded, see 3.4 | guarded, see 3.4 |

Verbatim stores `$workingPath` itself. `move_uploaded_file()` doesn't alter bytes, and
`put()` copies or streams it. The stored extension is the **sniffed** one, as today.

### 3.4 Guards (until Phase 6, then partly permanent)

- **Interim thumbnail guard:** skip the GD thumbnail when `getimagesize()` width × height is
  above `convert_max_pixels`. It goes away in Phase 6.
- **Non-verbatim conversion guard (permanent).** Strip and re-encode load the whole image into
  PHP memory. Above `convert_max_bytes` (default 25 MB) or `convert_max_pixels` (default 40 MP),
  a non-verbatim image upload is refused with **`4131` "Image too large to convert: upload it
  with verbatim=1"** (HTTP 413). **Decided.** The upload is refused before anything is stored,
  and the quota isn't touched.
  Both limits go in `magrathea.conf` (§6.2), not ConfigApp.

### 3.5 Optional while here: §0.3 orientation fix

In `ImageProcessor::ReencodeAndConvert()`, read the orientation and apply it with GD before
saving. Affects non-verbatim JPEGs only. Changelog **fix:**.

### 3.6 Response, admin, docs

- File view: add `"verbatim": bool` next to `no_convert`.
- `GET /key`: add `verbatim_default`.
- Key admin form (`features/Key/admin/form.php`) + `KeyControl::UpdateFields()`: a
  `verbatim_default` checkbox (hidden `0` + checkbox, like `active`).
- Docs (skill guide "Workflow 1", openapi `File`, `Key`, `POST /files`):
  - verbatim stores the bytes exactly as sent, **EXIF and GPS included**;
  - `no_convert` still re-encodes through GD and loses 16-bit, ICC, DPI and quality (§0.2):
    say so, it's been undocumented;
  - remove the "`no_convert` trap" paragraph (`docs/skills.md:180`): it's fixed now;
  - document 4131.

### 3.7 Acceptance (blueprint §3.1)

The 16-bit RGBA PNG fixture with ICC + pHYs (§6.6), `verbatim=1`: download it from `url` and
compare `sha256sum`. Run it on **both** storage drivers. Local storage serves through the vhost,
and an Apache `mod_deflate`/filter misconfiguration would change the bytes on the wire.

---

## 4. Phase 3: sha256 (§3.2)   [S]

### 4.1 Schema

```sql
ALTER TABLE `files`
	ADD COLUMN `sha256` char(64) NULL AFTER `size`,
	ADD KEY `idx_files_key_sha256` (`key_id`, `sha256`);
```

The index costs little now and saves a migration when dedup lookups arrive.

### 4.2 Compute

In `UploadPipeline::Handle()`: `hash_file("sha256", $finalPath)` right after `$storedSize`.
`hash_file` streams in chunks. It hashes the **stored** bytes, so it equals the client's hash
only when verbatim (documented). Lowercase hex, which is what `hash_file` returns.

File view: `"sha256": string|null`. Public share view: not added (decided).

### 4.3 `StorageAdapter::fetch()`

```php
/** Streams the object at $path into $localTmpFile. Never loads it into memory. */
public function fetch(string $path, string $localTmpFile): void;
```

- Local: `copy()`.
- S3: `getObject()` body → `fopen($localTmpFile, "wb")`, chunked via
  `getBody()->getContentAsResource()` / `stream_copy_to_stream`.

The backfill needs it, and so does Phase 6: cron processing and regeneration read the original
**from storage**, one code path for upload, retry and regenerate. R2 egress is free.

### 4.4 Backfill (nice to have)

`php cron.php --backfill-sha256 [--max-files=N] [--max-seconds=T]`: oldest `sha256 IS NULL`
first, fetch, hash, update. Same flock as §7.5.

---

## 5. Phase 4: per-key upload limit (§3.3)   [S]

### 5.1 Schema

```sql
ALTER TABLE `access_keys`
	ADD COLUMN `max_upload_size` bigint(20) NULL AFTER `verbatim_default`;
```

`Key`: `"max_upload_size" => "int"`. `null` = the instance default.

### 5.2 Effective limit, in one place

`Key::EffectiveMaxUploadSize(): int` returns `min(key.max_upload_size ?? instance max_upload_size,
PHP post_max_size, PHP upload_max_filesize)`. `UploadPipeline::AssertMaxSize()`
(`UploadPipeline.php:196-206`) moves onto it. PHP's limits are included on purpose: if `GET /key`
said "150 MB" while PHP stops at 64 MB, the response would be wrong.

- `GET /key`: `max_upload_size` (effective, int bytes).
- `GET /settings`: unchanged (instance default, still the string it is today).
- §4.3 (URL fetch) and §5.1 (direct upload) are later tiers. They call the same method.

### 5.3 Admin

- Key form: "Max upload (MB)", empty = instance default.
- Warn in the form when the value is above the PHP limits. A config error should be visible
  where it's made.

### 5.4 Docs: `docs/deploy.md`, DreamHost section

- `api/src/.user.ini`: `upload_max_filesize` / `post_max_size` above the largest key limit
  (e.g. `160M` / `170M`), `max_execution_time` and `max_input_time` at 300 or more.
  `memory_limit` stays where `deploy.sh` puts it: uploads don't grow it, thanks to the §3.4 guard.
- `deploy.sh setup_php_ini()` writes 64M today. Leave that default, and document the override.

---

## 6. Phase 5: the processor, standalone (§3.4.1–3.4.3, 3.4.6, 3.4.7)   [L]

Not wired into uploads yet. It's a CLI program with a contract and a fixture suite.

### 6.1 Layout (outside `api/src`: not web-served, not autoloaded, no Explorer code)

```
api/processor/
  vips/processor.py            engine #1: pyvips (§1.2). libvips' built-in "srgb" profile, no .icc file needed
  vips/requirements.txt        pyvips[binary]==<pinned>: the venv is rebuilt from it
  fixtures/                    committed fixture files + expected.json
  fixtures/make-fixtures.py    regenerates them (dev only; pyvips + hand-written chunks where libvips can't: JFIF units 0, bomb IHDR)
  run-fixtures.php             contract runner: dev, CI and the server. PHP, because it drives the engine exactly the way Explorer does (§6.3)
  diag/dreamhost-check.php     §1.2
```

Venv location on the server: outside the repo (`~/explorer-venv`), rebuilt from
`requirements.txt`. If DreamHost upgrades the system Python, the venv's interpreter disappears:
`run-fixtures.php` fails loudly, and the fix is documented in `docs/deploy.md` (recreate the venv).

### 6.2 Configuration: `magrathea.conf`, never ConfigApp

The processor command **is a command line**. ConfigApp is editable from the admin panel, so a
command there would turn any admin login into shell access. Per environment section:

```ini
processor_command      = '["/home/u/explorer-venv/bin/python","/home/u/explorer/api/processor/vips/processor.py"]'  ; argv, JSON
processing_tmp_path    = "/home/u/explorer/api/tmp"
processor_timeout_s    = "120"
processor_memory_mb    = "128"
max_pixels             = "200000000"
inline_max_bytes       = "16777216"
inline_max_pixels      = "40000000"
convert_max_bytes      = "26214400"   ; §3.4
convert_max_pixels     = "40000000"   ; §3.4
cron_process_max_files = "20"
cron_process_max_s     = "50"
processing_max_attempts = "3"
```

No `processor_command` → no handler → status `none` (§7.1). The defaults above are guesses
until §1.2 has run.

### 6.3 Runner, Explorer side: `api/src/features/Processing/ProcessorRunner.php`

- `proc_open($argv, …)` with an **array** (no shell), and an env carrying
  `VIPS_CONCURRENCY=1`, `TMPDIR` (inside `processing_tmp_path`) and a minimal `PATH`.
- Write the job JSON to stdin and close it. Drain stdout **and** stderr non-blocking with
  `stream_select` (a chatty stderr would otherwise deadlock the pipe).
- Wall-clock timeout: `proc_terminate($p, 9)`, result `failed: "time limit exceeded"`.
- Exit ≠ 0, a killed process, or invalid JSON on stdout → `failed` with the first 500 bytes of
  stderr. **Never trust output paths**: each `renditions[].path` must be a regular file inside
  `out_dir` (realpath check), or it's dropped.
- The job dir `processing_tmp_path/job-{id}-{attempt}/` is removed in `finally`.

### 6.4 Job contract

The blueprint's JSON (§3.4.1), plus one field:

- `"mode": "header" | "full"`. **header** reads properties from headers only and must not decode
  pixels (no renditions, no `is_opaque`). It runs inline at upload. **full** gives every property
  and every rendition.

Engine rules, all fixture-tested:

- The loader is forced from the sniffed type (`pyvips.Image.pngload(path)`, `jpegload`, …),
  never `new_from_file`, which sniffs on its own.
- `width`/`height` are the stored size, before orientation.
- `dpi_x`/`dpi_y` come from pHYs / JFIF / EXIF, **null when not declared**. libvips stores
  resolution in **pixels per mm** (×25.4 for DPI) and fills in a default when the file declares
  none, so it can't tell "absent" from "set". The engine reads pHYs / JFIF density itself
  (a few header bytes).
- Properties via libvips:
  - `bit_depth` from `format` (`uchar` = 8, `ushort` = 16);
  - `has_alpha_channel` from `hasalpha()`;
  - `color_space` from `interpretation`;
  - `has_icc_profile` from the `icc-profile-data` field;
  - `orientation` from the `orientation` field;
  - `frame_count` from `n-pages`;
  - `is_opaque` from a sequential full-resolution pass, alpha band `min() == max value`.
    Never from a downscaled copy: averaging hides single semi-transparent pixels.
- Renditions: `thumbnail()` with `size="down"`, `crop="centre"` for `cover`, auto-rotate,
  `export_profile="srgb"`, then save with no metadata. Each `thumbnail()` call decodes the PNG
  again (PNG has no shrink-on-load), so ~1.5 s per rendition at 105 MP (§1.2). Budget about 5 s
  per print file: it's `cron_process_max_s` that bounds a cron run, not the file count.
- Renditions:
  - EXIF orientation is applied;
  - converted to sRGB when an ICC profile is present;
  - then all metadata is **stripped**: renditions are public, and a verbatim original keeping
    GPS doesn't mean its thumbnail should;
  - never upscaled;
  - `contain` keeps the aspect ratio, `cover` center-crops;
  - jpg flattens onto the profile's `background` (default `#ffffff`);
  - animated GIF uses frame 0.
- `width × height > max_pixels`, read from the header, → exit 0 with every rendition in
  `errors` and no decode.

Format `legacy` (used only by the default profile, §7.3): png when the original is png, jpg
otherwise. That's today's rule from §0.4.

### 6.5 Security (§3.4.7)

libvips takes the place of the blueprint's Imagick rules:
- `pyvips.block_untrusted_set(True)`, then block **every** loader
  (`operation_block_set("VipsForeignLoad", True)`) and unblock only `pngload`, `jpegload`,
  `webpload`, `gifload`, plus the bmp loader if the wheel has one. This is the libvips version of a
  restrictive `policy.xml`. In particular, `svgload` (librsvg) and `magickload` stay blocked if
  the wheel ships them.
- Loader forced from the sniffed type (§6.4).
- Memory cap enforced by the processor on itself: `resource.setrlimit(RLIMIT_DATA, processor_memory_mb)`
  at startup. The `max_pixels` fixture (§6.6) checks that a real file fits under it.
- `VIPS_CONCURRENCY=1`, `TMPDIR` inside the account.
- SVG handler: properties only (root `width`/`height`/`viewBox`), with Python's XML parser and
  entities disabled (`defusedxml`-style: DOCTYPE refused). SVG is never rasterized.
- ffmpeg / Ghostscript: not used in 1.5.0 (§8).

If the decision in §1.2 goes to Imagick instead: `policy.xml` (deny
`MVG MSL URL HTTP HTTPS EPHEMERAL TEXT PS EPS PDF XPS SVG MSVG`, `delegate *`, `path @*`),
shipped via `MAGICK_CONFIGURE_PATH` (honored on DreamHost, §1.2), plus `setResourceLimit()` with
memory + map counted together (the 128 MB limit measured 316 MB of RSS).

### 6.6 Contract fixtures

`fixtures/expected.json` lists, per file: properties to match exactly, renditions to match on
dimensions, mime type and has-alpha.

| Fixture | Asserts |
|---|---|
| `rgba-transparent.png` | `has_alpha_channel=true`, `is_opaque=false` |
| `rgba-opaque.png` | `has_alpha_channel=true`, `is_opaque=true` |
| `rgb.png` | `has_alpha_channel=false`, `is_opaque=true` |
| `rgba16-icc-phys300.png` | `bit_depth=16`, `has_icc_profile=true`, `dpi=300`; renditions sRGB. **Also the §3.7 verbatim acceptance file** |
| `no-phys.png` | `dpi_x=dpi_y=null` |
| `jfif-units0.jpg` | `dpi=null` (the 72 trap) |
| `orient6.jpg` | `orientation=6`; renditions rotated (W/H swapped) |
| `animated.gif` | `animated=true`, `frame_count`; rendition = frame 0 |
| `bomb-header.png` | IHDR claims 100000×100000, tiny file → refused without decoding |
| `truncated.png` | non-zero exit with a message, nothing in `renditions` |
| `xxe.svg` | refused by Explorer (4151) before the processor sees it, on both the verbatim and the stripped path (§2.0). An Explorer-side test, not a processor one. ✅ Lives in `api/tests/fixtures/svg/` with the other SVG fixtures, covered by phpunit (§2.0) |
| generated at run time | ~`max_pixels`-scale PNG via the streaming writer from §1.2 → finishes under `processor_memory_mb` |

`run-fixtures.php` runs whatever `processor_command` is configured. **The same check runs on
DreamHost after every deploy.** `make-fixtures.php` builds them with Imagick/GD plus hand-written
chunks (pHYs, iCCP, EXIF APP1), and the outputs are committed.

---

## 7. Phase 6: wiring (§3.4.3–3.4.5)   [L]

### 7.1 Schema

```sql
ALTER TABLE `access_keys`
	ADD COLUMN `rendition_profile` JSON NULL AFTER `max_upload_size`;   -- null = instance default

ALTER TABLE `files`
	ADD COLUMN `properties` JSON NULL AFTER `duration`,
	ADD COLUMN `processing_status` enum('none','pending','ready','failed') NOT NULL DEFAULT 'none' AFTER `properties`,
	ADD COLUMN `processing_error` varchar(1000) NULL AFTER `processing_status`;

CREATE TABLE `file_renditions` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`file_id` int(11) NOT NULL,
	`name` varchar(32) NOT NULL,
	`token` char(21) NOT NULL UNIQUE,
	`storage_path` varchar(255) NOT NULL,
	`extension` varchar(16) NOT NULL,
	`mime_type` varchar(127) NOT NULL,
	`width` int(11) NULL,
	`height` int(11) NULL,
	`size` bigint(20) NULL,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY `uniq_file_rendition` (`file_id`, `name`),
	FOREIGN KEY (`file_id`) REFERENCES `files`(`id`)
);

CREATE TABLE `processing_jobs` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`file_id` int(11) NOT NULL,
	`status` enum('queued','running','done','failed','superseded') NOT NULL DEFAULT 'queued',
	`attempts` int(11) NOT NULL DEFAULT 0,
	`lease_until` DATETIME NULL,
	`profile` JSON NOT NULL,          -- snapshot at enqueue: a profile edit mid-flight can't mix specs
	`tokens` JSON NOT NULL,           -- {rendition name: token}, minted at enqueue
	`error` varchar(1000) NULL,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	KEY `idx_jobs_claim` (`status`, `lease_until`),
	KEY `idx_jobs_file` (`file_id`),
	FOREIGN KEY (`file_id`) REFERENCES `files`(`id`)
);
```

Models: `Processing/ProcessingJob` + `Control`, `File/FileRendition` + `Control`. JSON
columns are `"text"`, since MagratheaPHP has no json type.

### 7.2 Migrating today's thumbnails (same migration)

```sql
INSERT INTO `file_renditions` (`file_id`,`name`,`token`,`storage_path`,`extension`,`mime_type`)
SELECT `id`, 'thumb', `thumbnail_token`, `thumbnail_path`,
       SUBSTRING_INDEX(`thumbnail_path`, '.', -1),
       IF(`thumbnail_path` LIKE '%.png', 'image/png', 'image/jpeg')
FROM `files` WHERE `thumbnail_token` IS NOT NULL;

UPDATE `files` SET `processing_status` = 'ready' WHERE `thumbnail_token` IS NOT NULL;
```

- Migrated thumbnails have `width`/`height`/`size` **null**. That's a deviation to report.
  Regeneration fills them in.
- `files.thumbnail_token` / `thumbnail_path` stop being written and read in 1.5.0, but stay in
  place for rollback. Drop them in a later version.
- `TokenGenerator::GenerateUnique()` (`TokenGenerator.php:36-45`) checks `files.token` +
  `file_renditions.token`. The `UNIQUE` index is the real guard. Tokens reserved in a job's JSON
  aren't checked: at 125 bits, that's fine.

### 7.3 Rendition profiles: `Processing/RenditionProfile.php`

- Spec: `name` (`^[a-z0-9_-]{1,32}$`, unique), `width`/`height` (1–2048: renditions don't
  count toward usage (§7.9), so their size has to stay bounded), `fit`
  (`contain|cover`), `format` (`webp|png|jpg|legacy`), `quality` (1–100, default 85),
  `background` (`#rrggbb`, jpg only). At most 6 specs.
- Default (key `rendition_profile` null):
  `[{"name":"thumb","width":T,"height":T,"fit":"cover","format":"legacy","quality":85}]`,
  where `T` = ConfigApp `thumbnail_size`.
- That reproduces today's thumbnail in **name, size, fit and format**, not in pixels. Imagick
  isn't GD; `cover` center-crops where GD top-cropped portraits (§0.4); and jpg flattens onto
  white where GD produced black. Changelog **improvement:**, and a deviation to report.
- The validator is used by the admin form now and by the §4.1 provisioning API later.

### 7.4 Upload flow (`UploadPipeline::Handle()`, final shape)

1. Checks against the effective limit, staging, sniff/4151.
2. Verbatim or strip + convert (Phase 2 guards).
3. `sha256`, `getimagesize()`.
4. **Header properties**: processor `mode=header`, images only. Failure → nulls, not an error.
   Cheap properties land in the upload response.
5. `put()` the original, insert the row.
6. Profile has specs **and** a handler exists for this type? → enqueue a job and set
   `processing_status=pending`. Otherwise `none`.
7. Below `inline_max_bytes` **and** `inline_max_pixels`, and not a bulk import → run that job
   **now**, through the same `JobRunner` cron uses. Existing keys (5 MB default) keep getting
   their thumbnail in the upload response: no visible change.
8. A failed processing run **never** fails the upload: the original is stored, status `failed`,
   `error` set.

`ImportPipeline` always queues (never inline). That also makes bulk import faster than today.

### 7.5 The queue: `cron.php --process`

- `php cron.php --process [--max-files=N] [--max-seconds=T] [--verbose]`, every minute.
  `--run` (deletions, daily) is untouched.
- A `flock` on `processing_tmp_path/process.lock`: a run still going makes the next one exit at
  once, so nothing piles up on shared hosting.
- Claim:
  `UPDATE processing_jobs SET status='running', attempts=attempts+1, lease_until=NOW()+INTERVAL (timeout+60) SECOND WHERE id=? AND (status='queued' OR (status='running' AND lease_until<NOW()))`.
  Claimed only if `LastAffectedRows()===1`.
- `attempts >= processing_max_attempts` → job `failed`, file `failed`.
- `Database::Instance()->Disconnect()` before each job (framework rule for loops).
- `JobRunner::Run(job)`:
  1. `fetch()` the original into the job dir.
  2. Run the processor (`mode=full`).
  3. `put()` each output at `{storage_uuid}/{token}.{ext}` using the **job's** tokens: a retry
     overwrites the same objects, so retries are idempotent.
  4. In one `Transaction()`: re-check that the job is still `running` and the file still exists.
     Replace the file's `file_renditions` rows, update `properties` (merge),
     `processing_status`/`error`, and job `done`.
  5. After the commit, delete the **previous** renditions' objects (best effort).
  6. If step 4's re-check fails (file deleted, job superseded), delete this job's own objects.

### 7.6 Regenerate: `POST /file/{uuid}/renditions`

- It marks any `queued`/`running` job of the file `superseded` and enqueues a new one from the
  key's **current** profile, with **new tokens**. Then it returns the File with `status=pending`.
- Idempotent in effect: calling it twice leaves one live job.
- **New URLs on regeneration, on purpose.** S3 objects carry
  `Cache-Control: immutable, max-age=31536000` (`S3StorageAdapter.php:50`), and Printful caches
  by URL. Overwriting a rendition in place would serve stale bytes for a year. Report this.
- Bulk regeneration per key or folder (`cron.php --regenerate --key=… [--folder=…]`): nice to
  have, last.

### 7.7 Response shape: one shared builder

`FileApi::FileView()` (`FileApi.php:120-139`) and `PublicShareApi::SharedFileView()`
(`PublicShareApi.php:128-145`) both build `thumbnail_url` today. Move the shared part into one
builder:

```json
"sha256": "…",
"verbatim": true,
"properties": {"width":4500,"height":5400,"orientation":null,"has_alpha_channel":true,"is_opaque":null,
               "dpi_x":300,"dpi_y":300,"bit_depth":8,"color_space":"srgb","has_icc_profile":true,
               "animated":false,"frame_count":1},
"processing": {"status":"pending","error":null},
"renditions": null,
"thumbnail_url": null
```

- `properties`: the stored JSON merged over the top-level `width`/`height`/`duration`, so old
  files get a partial object rather than null.
- `renditions`: `null` while `pending`, `{}` when there are none,
  `{name: {url,width,height,mime_type,size}}` otherwise.
- `thumbnail_url`: the `thumb` rendition's URL, or null.
- Public share payload: **only** `thumbnail_url` (via the alias). `sha256`, `properties`,
  `processing` and `renditions` stay private (decided).
- `GET /files` loads every listed file's renditions in **one** `IN (…)` query, not one per file.
  (Tags are already N+1 there. Not this plan's problem.)

### 7.8 Delete paths

`FileControl::DeleteFileAndStorage()` (`FileControl.php:45-62`) is the single choke point
(`Delete()`, `KeyControl::DeleteKeyNow()` and the admin all go through it). It also has to:
- delete every rendition object and row;
- mark the file's jobs `superseded`, then delete them, **before** `$file->Delete()`. No FK
  cascades here.

A worker mid-job on a deleted file cleans up after itself (§7.5 step 6).

### 7.9 Usage accounting (blueprint §7 Q3)

**Decided: renditions don't count.** Explorer creates them, and that's the 1.4 behavior too
(the thumbnail was never counted), so existing keys see no change.
- `total_size`, `usage_limit_mb`, `AdjustSize()` and `GET /key/usage` only ever see originals.
  Nothing changes in `Key`.
- `file_renditions.size` is still recorded per object. If this is ever revisited, the numbers
  are already there (one `SUM()`), and no backfill is needed.
- Uncounted storage stays bounded by the profile limits: at most 6 specs, at most 2048 px
  (§7.3).
- Docs (skill guide "Workflow 7", openapi `KeyUsage`): say that renditions are not counted.
- A deviation to report to Turbopod (the blueprint recommended counting them). One in their
  favor.

### 7.10 Admin

- Key form:
  - `rendition_profile` textarea (JSON), validated by `RenditionProfile`, errors shown inline;
  - a "Turbopod preset" button that fills thumb 300 contain webp + preview 1200 contain webp.
- File views in Browser admin: show `processing_status`/`error`, and a **Regenerate** POST action.
- `BrowserAdmin::OrphanCheck()`: also check rendition rows → storage.
- A small "Processing queue" page: counts by status, the last 20 failures with their errors.

### 7.11 Removed

`ImageProcessor::BuildThumbnail()`, `ResampleCalculator`, `UploadPipeline::DEFAULT_THUMBNAIL_SIZE`
(moved to `RenditionProfile`). `ImageProcessor` keeps the GD re-encode for the non-verbatim path.

---

## 8. Non-image files: no handlers in 1.5.0 (decided)

- Video poster frames and PDF page count / first page are **cut**. Audio, video, document and
  other files are stored exactly as uploaded, which is what happens today. They get
  `processing.status = "none"` and `renditions: {}`, and their `properties` are top-level
  `duration` only (audio/video, via getID3, read-only).
- Report this to Turbopod as a deviation: the blueprint marks these handlers optional.
- The handler dispatch in §6 is keyed by `file_type`, so a later version adds a handler without
  touching the queue, the schema or the response shape.

---

## 9. Phase 8: release   [S]

- Merge the per-phase migration blocks into `api/database/migrations/1.5.0_tier1.sql`, ordered
  and commented like the earlier ones. Mirror everything in `database.sql`.
- `docs/deploy.md`:
  - server requirements (+ Python 3 with `venv`, `proc_open`), and creating the venv
  from `api/processor/vips/requirements.txt`;
  - processor config;
  - the `--process` cron line;
  - running `run-fixtures.php` after deploy;
  - "Upgrading to 1.5.0" (dump, migration, config keys, cron, cache clear).
- `scripts/deploy.sh` `install`/checks: add a "processor runs the fixtures" step.
- Skill guide (`docs/skills.md`):
  - "Mental model";
  - "Workflow 1" (verbatim, sha256, properties, processing, renditions, polling);
  - "Workflow 5" (renditions + `thumbnail_url` alias);
  - error table (4009, 4131);
  - TypeScript types;
  - "Differences from Images3" table.
- `docs/openapi.yaml`:
  - `File`, `Key`, `KeyUsage`;
  - `POST /files` fields;
  - `POST /file/{uuid}/renditions`;
  - error codes.
- Changelog: set the date. `api/version` and the openapi `version:` were bumped in Phase 1.

---

## 10. Test checklist (manual, per phase, on both storage drivers)

- [ ] `no_convert` with `1 true yes on` / `0 false no off ""` / absent / `maybe` → expected value or 4009
- [ ] verbatim acceptance (§3.7): sha256 of the downloaded `url` = the local file's
- [ ] verbatim JPEG with GPS: stored with GPS; the renditions have no EXIF
- [ ] key `verbatim_default=1`: absent flag → verbatim; `verbatim=0` → converted
- [ ] non-verbatim 60 MB PNG → 4131, no PHP fatal, nothing stored, quota untouched
- [ ] per-key limit: 150 MB key accepts 140 MB; a 5 MB key refuses 6 MB with 4002; `GET /key` shows each effective limit
- [ ] small upload on an existing key: thumbnail in the upload response, `processing.status=ready`
- [ ] large upload: `pending`, `renditions: null`; after `cron.php --process`: `ready`, renditions present
- [ ] processor killed by timeout → `failed` with a reason; retried up to max attempts; never stuck `running`
- [ ] two overlapping `--process` runs → the second exits on the lock
- [ ] regenerate twice quickly → one live job; old rendition URLs 404 afterwards
- [ ] delete a file mid-processing → no orphan objects (`OrphanCheck` clean)
- [ ] key force-delete with pending jobs → succeeds (no FK error)
- [ ] public share of a processed file → `thumbnail_url` works, no `renditions`/`sha256` leak
- [ ] `run-fixtures.php` green in dev **and** on DreamHost
- [ ] `vendor/bin/magrathea check` + `models:verify` clean

---

## 11. Phase order and size

| Phase | Content | Size | Depends on | Status |
|---|---|---|---|---|
| 0 | DreamHost check, dev env, harness | S | — | check ✅ (manual items left), harness ✅, dev env (§1.3 Dockerfile/php.ini) open |
| 1 | **XXE fix**, boolean parsing, version bump | S | — | XXE ✅, bump ✅, booleans (§2.1–2.3) open |
| 2 | verbatim + guards (+ orientation fix) | M | 1 | open |
| 3 | sha256 + `fetch()` | S | — | open |
| 4 | per-key upload limit | S | — | open |
| 5 | processor + fixtures (standalone) | L | 0 (engine choice) | open |
| 6 | schema, queue, renditions, regenerate, admin | L | 3 (`fetch`), 5 | open |
| 7 | ~~optional handlers~~: cut, non-image files stay as-is (§8) | — | — | cut |
| 8 | migration file, docs, release | S | all | open |

Phases 1–4 are independent of the engine question and can start while §1.2 is pending.

---

## 12. Answers for Turbopod (blueprint §7 and §8)

| Item | State today | Deviations from the blueprint |
|---|---|---|
| §3.1 verbatim | missing; `no_convert` still strips + GD re-encodes (§0.2) | non-verbatim images above the conversion limits → 413 (`4131`) |
| §3.1 booleans | broken as described | none |
| §3.2 sha256 | missing | none |
| §3.3 per-key limit | missing (instance-wide only) | effective limit also includes the PHP limits |
| §3.4 pipeline | GD thumbnail only, sync, top-crop, black-on-transparent jpg | `mode` field in the job; default thumb same name/size/fit/format, different pixels; migrated thumbs have null dims; **regeneration mints new rendition URLs**; video/PDF handlers cut, non-image files get `none` (§8); renditions don't count toward usage, and rendition dimensions are capped at 2048 px (§7.9) |

- §7 Q1: §0.2. Q2: §0.4. Q3: no, §7.9. Q5: §1.2. Imagick works (8 s / 316 MB limited), but
  the first engine is **pyvips** (1.5 s / 127 MB). That's a deviation from "the first
  implementation is a PHP CLI script using Imagick", and it's invisible to Turbopod (same contract).
- §7 Q4 (consumers): only the boolean fix can affect anyone, and only a consumer that sends
  `no_convert`. Grep the other projects for `no_convert`. The explorer app doesn't send it.
- §7 Q6 (storage driver of Turbopod's instance): doesn't change this plan, which supports both
  drivers. Answer it in the reply to Turbopod.
- Feasibility of §4.1/§4.2 (Tier 2) is a separate answer. Nothing in this plan blocks either.
  `processing_jobs` and the effective-limit method are already shaped for them.

---

## 13. Out of scope

- Tier 2/3 (§4, §5). §3.3's "enforced on URL fetches and direct uploads" means: those features
  call `EffectiveMaxUploadSize()` when they exist.
- Explorer app (`app/`) UI for processing status or properties. The app keeps working through
  `thumbnail_url`.
- Streaming rewrite of `MetadataStripper`: the §3.4 guard bounds its memory instead.
- Dropping `files.thumbnail_token` / `thumbnail_path` (a later version).
- Dedup on upload (the index is ready; the behavior isn't asked for).

## 14. Risks

- ~~The engine on DreamHost~~: **retired**. pyvips does 105 MP in 1.5 s / 127 MB from cron (§1.2).
- **Python in a PHP project.** The venv depends on the system Python. A DreamHost OS or Python
  upgrade breaks it, and every upload then ends up `failed` until it's rebuilt. Mitigations:
  `run-fixtures.php` after every deploy and server change, failures visible on the admin queue
  page, a pinned `requirements.txt`, and the rebuild steps in `docs/deploy.md`.
- ~~`MAGICK_CONFIGURE_PATH` ignored~~: **retired**. Honored on DreamHost (§1.2 results). The
  coder allowlist and the forced decoder prefix stay anyway, as defense in depth.
- **Cron granularity**: if DreamHost only allows coarse intervals, `pending` lasts that long.
  Turbopod polls, so it's latency, not breakage.
- **Default-thumbnail pixel changes** are visible in the explorer app for new uploads (center
  crop, white background). Old thumbnails don't change.
- **A long branch.** Phases 1–4 touch `UploadPipeline::Handle()`, and so does Phase 6. Land them
  in order; don't run them in parallel.
