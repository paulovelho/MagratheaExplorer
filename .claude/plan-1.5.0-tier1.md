# 1.5.0 — Turbopod Tier 1: implementation plan

Covers §3 of `.claude/blueprint-1.5.0.md` (verbatim storage, sha256, per-key upload limit,
processing pipeline). Everything ships together as **1.5.0** but is built in phases. Each phase
leaves the branch working, with its own docs, and can be reviewed by itself.

Written against the working tree on `version/1.4.1`, which holds the unmerged **1.4.0**
(MagratheaPHP 3) work.

**Status (2026-11-18):** done: §1.4 (test harness), Phase 1 (§2.0–2.4), Phase 2 (§3),
Phase 3 (§4), Phase 4 (§5), Phase 5 (§6, as built in §6.7) and Phase 6 (§7, as built in §7.12).
The 1.5.0 migration hasn't been applied to any real database yet (Phases 3, 4 and 6 checked it
on throwaway ones, §4.5, §5.5, §7.12), Phase 2's §3.7 acceptance run is pending (see §3.8), S3
`fetch()` and the rendition `put()`s haven't met a bucket, and `run-fixtures.php` hasn't run on
DreamHost. Phase 8 is open.

Every decision is settled: the XXE fix ships in 1.5.0 (§2.0), oversize non-verbatim images →
413 (§3.4), renditions don't count toward usage (§7.9), there are no video/PDF handlers (§8),
and public share links show only `thumbnail_url` (§7.7). The DreamHost check ran (§1.2): the
engine is pyvips, **written in Python** (decided 2026-11-18).

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
  **Measured in Phase 2** (PHP 8.4, dev image, `memory_limit` enforced): a full re-encode is
  ~8 bytes per pixel (the GD canvas plus the encoder's own copy): 40 MP → ~315 MB, JPEG or RGBA
  PNG, webp or png. A JPEG turned 90° for its EXIF orientation → ~470 MB (§3.8).
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
- **Engine #1: pyvips, written in Python.** Decided 2026-11-18 (over Imagick to keep the
  project PHP-only). Built in Phase 5 (§6.7).
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
- **Decided (2026-11-18): no Docker changes for now.** Neither item above is done. The dev venv
  is built by hand from `requirements.txt` (`docs/deploy.md` "Image processor"). The dev image
  has GD but no Python; the host has Python but no GD, so pixel checks only run where both are
  (§6.7).

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

### 2.1 New: `api/src/shared/RequestBool.php` ✅ done (with Phase 2)

```php
/** null = field absent. Throws 4009 on anything not in the two lists. */
public static function Parse(array $input, string $field): ?bool
```

- Absent key → `null` (the caller applies its default).
- PHP `bool` passes through (JSON bodies). Int `1`/`0` are accepted.
- Strings, trimmed and case-insensitive: `1 true yes on` → true; `0 false no off ""` → false.
- Anything else → `4009 "Invalid boolean value: <field>"` (HTTP 400).
- **As built:** JSON `null` counts as absent. `ParseValue()` holds the parsing alone (null = not
  a boolean), for callers with their own error: `ExplorerConfig` reads magrathea.conf booleans
  with it (5004 on garbage). Tests: `tests/Shared/RequestBoolTest.php`.

### 2.2 Use it ✅

- `FileApi::Upload()`: `no_convert` (and `verbatim` in Phase 2).
- Every new flag from here on goes through it.

### 2.3 Error code ✅

`error_codes.conf`: `4009 = "Invalid boolean value"`. `api/cache/error_codes.json` is a cache of
`/error-codes`: clear it on deploy. **It wasn't in "Updating an instance": added** to
`docs/deploy.md` (delete `cache_path/*.json` or use the admin's Cache page; `/changelog` is
cached the same way).

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

## 3. Phase 2: verbatim storage (§3.1)   [M] ✅ done (deviations in §3.8)

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
**Not run yet:** it needs a running instance with the migration applied. Until Phase 5 builds
the fixture, any 16-bit PNG with an iCCP chunk will do.

### 3.8 As built (2026-11-17)

Decided during the work (asked, answered):
- **Instance fallback for `verbatim_default`.** `access_keys.verbatim_default` is
  `tinyint(1) NULL`, not `NOT NULL DEFAULT 0`: null = magrathea.conf `verbatim_default` (any
  `RequestBool` value; absent = false = strip + convert). `Key::VerbatimByDefault()` is the
  effective value. Resolution: request `verbatim` ?? key ?? conf ?? false.
- **Bulk import follows the key's effective default** (it has no flags; `no_convert` off). The
  import form says which one applies.
- **Orientation fix at GD load (§3.5), not only in the re-encode**: `LoadGD()` applies EXIF
  orientation to JPEGs, so the verbatim GD thumbnail (from an original that keeps its tag)
  comes out upright too. Skipped when ext-exif is missing.
- **Turned JPEGs count 1.5× in the 4131 check.** Orientations 5–8 rotate onto a second canvas,
  and the freed one stays fragmented under the encoder's buffer: 40 MP → ~470 MB vs ~315 MB.
  At the defaults, a portrait JPEG converts up to ~26.7 MP (a 24 MP one passes); measured
  242 MB right under that ceiling. `FitsInMemory()` (thumbnail) isn't weighted: a 40 MP turned
  verbatim thumbnail peaks at 316 MB.

Other deviations from §3.1–3.6:
- `GET /key` `verbatim_default` is the **effective** bool, not the raw column (same idea as
  §5.2's effective `max_upload_size`).
- Key admin: a **select** (instance default / verbatim / stripped + converted), not a
  checkbox: the column is tri-state.
- `UploadOptions{verbatim, noConvert, tagNames, enforceQuota}`: no `inlineProcessing` yet,
  Phase 6 adds it when something reads it. `noConvert` is forced true when verbatim (so
  `files.no_convert` = 1 on verbatim rows). `UploadOptions::Resolve()` is the one place flags
  meet key defaults.
- `files.verbatim` records the resolved request, for every file type (non-images are stored as
  sent anyway).
- New `shared/ExplorerConfig.php`: magrathea.conf readers (`GetBool`, `GetSize` accepting
  "25M", `GetPositiveInt`); garbage → 5004 naming the key, never its value. `ParseSize` moved
  there from `UploadPipeline` (ConfigApp sizes keep their lenient fallback). Phase 5/6's keys
  (§6.2) should go through it.
- 4131's message is `"Image too large to convert (send it with verbatim=1)"`, with a detail
  (`"7000x6000 px is 42 MP, the limit is 40 MP"`). `413 = "Payload Too Large"` added.
- The bytes limit applies to every non-verbatim image, SVG included (the stripper loads the
  file into memory); the pixel limit to whatever `getimagesize()` can read.
- The migration is `api/database/migrations/1.5.0_tier1.sql`, started now: later phases append
  to it (Phase 8 only reviews it). Mirrored in `database.sql`.
- Framework quirk found: `Config::Get()` treats an **empty** environment section as missing and
  throws. `tests/Support/TestConfig::Set()` always adds a sentinel key.

Tests: `tests/Shared/{RequestBool,ExplorerConfig}Test.php`, `tests/File/{UploadOptions,ImageProcessor}Test.php`.
The orientation tests need GD, which the host PHP lacks: run the suite in the dev image too
(`docker run --rm -v "$PWD":/app -w /app magratheaexplorer-magrathea_explorer php vendor/bin/phpunit`
from `api/`). 96 tests green there. `UploadPipeline::Handle()` itself has no test (DB +
storage); §10's manual checks cover it.

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
first, fetch, hash, update. ~~Same flock as §7.5.~~ Its own lock (§4.5).

### 4.5 As built (2026-11-17) ✅

Decided during the work (asked, answered):
- **The backfill has its own lock** (`backfill-sha256.lock`), not §7.5's `process.lock`. A
  shared one would stall the every-minute queue, and every upload with it, for as long as a
  backfill runs. The backfill is light (stream + hash, ~20 MB of PHP), so running it next to
  the processor costs nothing on DreamHost.

Other deviations and additions:
- **`processing_tmp_path` (§6.2) arrives now**, for the lock and the fetched copies. Absent =
  `api/tmp` (gitignored, §1.3). `ExplorerConfig::ProcessingTmpPath()` creates it, 5004 if it
  can't be created or written. `deploy.sh install`'s runtime-dirs step creates and chowns it.
  Phase 6's job dirs go there.
- **`shared/CronLock.php`**: `TryAcquire(name)` → null when taken. flock, so the kernel releases
  it when a run dies, killed or not. Phase 6's `--process` uses it as `process`.
- **S3 `fetch()` uses `getChunks()`, not `getContentAsResource()`.** async-aws mirrors every
  response body into `php://temp` (memory up to 2 MB, then a file in `sys_get_temp_dir()`), so
  both ways need ~2× the object in temp disk during a fetch; PHP memory stays flat. `getChunks()`
  at least writes as the bytes arrive. Content-Length is checked against what was written. For
  Phase 6: a 150 MB print original costs ~300 MB of temp disk per fetch, half of it in the
  **system** temp dir, outside `processing_tmp_path`. If DreamHost's `/tmp` can't take that, the
  fix is presign + Symfony HttpClient streaming straight to the file (no mirror).
- **`fetch()` contract**: on failure, nothing is left at the target (both drivers), so no
  caller hashes or processes a partial copy.
- **Backfill** (`features/File/FileHashBackfill.php`; `cron.php` parses flags and holds the lock):
  - a fetched size ≠ `files.size` fails the file (the object isn't the file its row describes);
  - failures don't count toward `--max-files`, and the id cursor moves past them, so a stubborn
    file can't stall a run. Every run retries them. Exit code 1 when any file failed;
  - `updated_at = updated_at` in the UPDATE: hashing doesn't count as a change to the file;
  - `Disconnect()` before each file: a long fetch can outlast `wait_timeout`;
  - a bare `--max-files` / `--max-seconds` (no value) is refused: getopt drops it, which would
    mean "no limit".
- `UploadPipeline` throws 5001 if `hash_file()` fails (never seen; it beats storing `false`).
- Docs: openapi `File.sha256` (+ `SharedFile` exclusion), skill guide (File example, meaning,
  TS type, SharedFile), `deploy.md` (`processing_tmp_path`, the backfill under Cron, with a
  capped cron line for DreamHost), conf sample, changelog (**improvement:** + **db:**).
- **Migration check**: on a throwaway MariaDB 11, the 1.4.0 schema + `1.5.0_tier1.sql` gives the
  same `files`/`access_keys` as a fresh `database.sql` (index order aside). MariaDB dropped the
  implicit FK index `key_id` when `idx_files_key_sha256` arrived (it serves the FK), so there's
  no redundant index. That check found a Phase 2 drift, now fixed: the migration's
  `verbatim_default` lacked `database.sql`'s COMMENT.
- Tests: `tests/Storage/LocalStorageAdapterTest.php` (fetch), `tests/Shared/CronLockTest.php`,
  `ExplorerConfigTest` (+3, `processing_tmp_path`), helper `tests/Support/ScratchDir.php`.
  108 green in the dev image, 101 + 2 GD skips on the host. Plus a one-off integration run
  (throwaway MariaDB, local storage, dev image; not committed): sha256 of a verbatim png, a
  converted jpg and a txt; `FileView`; the backfill (`--max-files` stop, missing and
  wrong-size rows, order, `updated_at` kept, no temp leftovers, rerun); `models:verify` clean.
  **Not run: S3 `fetch()`**, since there's no bucket here. Its first real test is a backfill on R2.

Not done, worth considering later: the admin's **Duplicates** page groups by
`(key, folder, name)` and deletes all but the newest, so it can delete copies whose content
differs. With `sha256` filled in, it could show which copies are identical. Out of this plan's
scope (§13).

---

## 5. Phase 4: per-key upload limit (§3.3)   [S] ✅ done (deviations in §5.5)

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

### 5.5 As built (2026-11-17) ✅

- **Schema**: `access_keys.max_upload_size bigint(20) NULL` with a COMMENT, appended to
  `1.5.0_tier1.sql` and mirrored in `database.sql`.
- **`Key`**: `EffectiveMaxUploadSize()` = `min(ConfiguredMaxUploadSize(), PhpMaxUploadSize())`.
  `ConfiguredMaxUploadSize()` (key ?? instance) is public too: the admin compares the two to
  flag a key PHP caps. `InstanceMaxUploadSize()` reads ConfigApp `max_upload_size`, leniently as
  before (empty, `0` or unreadable → 5 MB). `DEFAULT_MAX_UPLOAD_SIZE` moved from `UploadPipeline`
  to `Key`; `SystemApi` follows, and `/settings` is unchanged.
- **PHP limits**: `IniSizeToBytes()` treats `0`/negative/unset as "no limit", which is what PHP
  does with `post_max_size = 0`. The old code did the same, by accident (`empty("0")`).
  `ini_parse_quantity()` would be the right parser, but it's PHP 8.2 and the floor is 8.1.
- **4002 carries a detail** (asked for nothing; it was free): `"6.20 MB, the limit is 5.00 MB"`,
  exact bytes when both round to the same text (`"1048577 bytes, the limit is 1048576 bytes
  (1.00 MB)"`, found in the integration run), no size when PHP dropped the body
  (`UPLOAD_ERR_INI_SIZE`, `post_max_size` overflow). `UploadPipeline::RefuseTooLarge()` is the
  one builder, FileApi's early check included. `AssertMaxSize()` is gone.
- **Admin**: "Max upload (MB)", MiB like `usage_limit_mb`, decimals allowed, stored in bytes.
  `KeyControl::MaxUploadMbForForm()` prints enough decimals that a value not set in whole MB
  (an SQL UPDATE) saves back unchanged. `ParseMaxUploadMb()` refuses 0, garbage, exponents,
  units and anything above 1 TB. `KeyAdmin::Save()` validates **before** `Create()`, so a bad
  value never leaves a half-made key; it redirects back to the form with `invalid=…` (a flag,
  never a message, in the URL: feature actions render outside the admin layout), and nothing
  is saved. The form shows the instance default as placeholder, "PHP allows X (…ini values…)"
  under the field, and a warning when PHP caps the key. The key list has a "Max upload" column
  (effective, "(default)", "capped by PHP").
- **Import** follows the key's effective limit, PHP's limits included, as it did before 1.5.0
  with the instance one. `post_max_size` means nothing to a file read from disk, so a big
  import can be refused for no real reason. Left as it was.
- **Docs**: openapi (`Key.max_upload_size`, `Settings.max_upload_size`, `POST /files` limits,
  4002), skill guide (Step 0, error table), `deploy.md` "Large uploads (per-key limits)" under
  step 4 (the deploy guide has no DreamHost section: DreamHost notes are inline), changelog
  (**improvement:**, **db:**). Added there beyond §5.4: `.user.ini` is cached for
  `user_ini.cache_ttl` and invisible to the CLI; PHP's temp dir holds each upload in flight;
  web server and **Cloudflare proxy** body limits (100 MB on Free/Pro) answer 413 before PHP.
- **Tests**: `tests/Key/UploadLimitTest.php` (37: ini parsing, instance default, key vs
  instance, PHP cap, MB parsing, form round trip). 138 + 2 GD skips on the host, 145 in the dev
  image. Plus a one-off integration run (throwaway MariaDB 11, dev image, local storage, PHP at
  3M/4M; not committed): 1.4.0 schema + the migration gives the same `access_keys` as a fresh
  `database.sql`; `models:verify` clean; real `UploadPipeline::Handle()` calls at the instance
  limit, a key limit below it, a key limit above PHP's; `GET /key`'s value; a refused form value
  changes nothing; the form and list views render their warnings.

---

## 6. Phase 5: the processor, standalone (§3.4.1–3.4.3, 3.4.6, 3.4.7)   [L] ✅ done (deviations in §6.7)

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
command there would turn any admin login into shell access. **Found in Phase 5: so is
magrathea.conf** (MagratheaPHP's Setup → Configuration File page). The command stays there
anyway (decided), and the docs say an admin login means shell access (§6.7). Per environment
section:

```ini
processor_command      = '["/home/u/explorer-venv/bin/python","/home/u/explorer/api/processor/vips/processor.py"]'  ; argv, JSON
processing_tmp_path    = "/home/u/explorer/api/tmp"
processor_timeout_s    = "120"
processor_memory_mb    = "384"        ; measured in Phase 5 (§6.7); was a guess of 128
max_pixels             = "200000000"
inline_max_bytes       = "16777216"
inline_max_pixels      = "40000000"
convert_max_bytes      = "26214400"   ; §3.4
convert_max_pixels     = "40000000"   ; §3.4
cron_process_max_files = "20"
cron_process_max_s     = "50"
processing_max_attempts = "3"
verbatim_default       = "false"      ; §3.8, already read by Phase 2
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
DreamHost after every deploy.** ~~`make-fixtures.php` builds them with Imagick/GD~~
`make-fixtures.py` builds them with pyvips plus hand-written chunks (pHYs, iCCP, JFIF, EXIF
APP1, the bomb IHDR), and the outputs are committed (§6.7).

### 6.7 As built (2026-11-18) ✅

Decided during the work (asked, answered):
- **Engine: pyvips, written in Python** (§1.2). The fixtures come from `make-fixtures.py`
  (pyvips plus hand-written chunks), not `make-fixtures.php`. §6.6's last paragraph predated
  the decision, and neither the dev image nor the host PHP has Imagick; the host has no GD.
- **SVG DTD: the §2.0 whitelist, not "DOCTYPE refused"** (§6.5).
  - The engine parses with expat, parameter entities off. These exit 1: an ENTITY with
    SYSTEM/PUBLIC/NDATA, a parameter entity, a value containing `&`, `%` or `<`, a value over
    1 KB or a subset over 64 KB, ATTLIST/ELEMENT/NOTATION, a PI inside the DTD.
  - External DTD references pass (never fetched). Parsing stops at the root element.
  - Checked against every SVG in `api/tests/fixtures/svg/` (same verdicts as `UploadValidator`),
    in UTF-16, and with the 2 MB comment padding.
  - Refusing every DOCTYPE would have given every Illustrator SVG null dimensions, with no
    visible error.
- **No Docker changes** (§1.3 stays open on purpose). The dev venv is built by hand.
- **`processor_command` stays in magrathea.conf, knowing an admin login can rewrite it.**
  - §6.2's premise is wrong for this app. MagratheaPHP's admin page Setup → Configuration File
    (`Admin/views/pages/config.php`) saves the whole POST body into the environment section,
    new keys included, and Explorer's admin menu shows it.
  - So any admin login can set `processor_command` to `["/bin/sh","-c",…]` and get shell as
    the web user.
  - Decided: keep it there, and say so in `deploy.md` and the conf sample: treat admin
    accounts as shell accounts.
  - A framework fix (save only existing keys, or a protected-keys list) would close it. That's
    outside this repo.

Other deviations and additions:
- **Contract additions** (`api/processor/README.md` "Contract"):
  - result fields `handled` (false = no handler for the type → status `none`, exit 0),
    `renders` (false = properties only, e.g. SVG) and `engine` (a version string);
  - exit codes: 1 = the file can't be processed, 2 = invalid job; the reason is a
    `processor: …` line on stderr;
  - the job has no `"properties": true` (the blueprint's suggestion): `mode` covers it.
- **`is_opaque` is true in header mode when there's no alpha channel.** That needs no decode.
  §6.4 said header mode never gives `is_opaque`.
- **The failure reason is stderr's tail, not its first 500 bytes.** It's the `processor:` line,
  else the last line: a Python traceback ends with the exception (`No module named 'pyvips'`,
  the broken-venv case). Cut to 500 characters, with paths masked (`{input}`, `{job}`, `{tmp}`),
  because `processing.error` will reach API clients in Phase 6.
- **The runner trusts less than §6.3 said.** Beyond the realpath check:
  - a rendition can't be a symlink or a hard link (nlink = 1), and `getimagesize()` must read
    it as the requested type;
  - width, height and size are measured, never copied from the result;
  - a property outside its type or range becomes null;
  - unknown or duplicate rendition names are dropped, and a requested one that's in neither
    list becomes an error;
  - stdout is capped at 1 MB (over it, the process is killed);
  - the environment is only PATH, TMPDIR, VIPS_CONCURRENCY and VIPS_BLOCK_UNTRUSTED, and the
    cwd is the job's `tmp/`;
  - peak RSS is sampled from /proc (VmHWM), for diagnostics.
- **The job dir belongs to the caller, not the runner.** `ProcessorJobDir` holds `original`,
  `out/` and `tmp/`, all 0700. Phase 6's JobRunner fetches the original into it before the run
  and stores the outputs after, so the runner can't remove it. `Create()` wipes a leftover of the
  same name (a run killed before its `finally`), and removal never follows symlinks.
- **The runner resolves `legacy`** (`ResolveFormat()`), so engines only see webp, png and jpg.
- `Run()` takes an optional timeout override: Phase 6's inline header run at upload shouldn't
  wait 120 s on a hung processor.
- **`ExplorerConfig::GetArgv()`**: a JSON array of non-empty strings, single-quoted in the ini
  (parse_ini_file keeps it as written). Garbage → 5004. A relative or non-executable argv[0]
  fails the run (not a 5004).
- **Measured defaults** (§6.2 called them guesses):
  - `processor_memory_mb` = **384**, not 128. Thumbnail memory follows the image's width, not
    its pixel count: 7000, 14142 and 28000 px wide → ~86, 141 and 185 MB RSS for one 1200 px
    rendition.
  - A whole job on a 200 MP square peaks at 150 MB (container) to 210 MB (dev host), and fails
    at 128. A 28000×3500 panorama peaks at ~260 MB and loses its preview at 256.
  - `max_pixels` stays 200 MP and `processor_timeout_s` 120: a 200 MP job takes ~5 s here.
  - Consistent with DreamHost's 127 MB at 105 MP (§1.2).
- **libvips quirk** (8.18.7): `thumbnail()` without `output_profile` ignores the ICC profile.
  With it, a 16-bit RGBA source comes out at alpha 254 where it was opaque.
  - The engine applies `icc_transform("srgb")` itself, after the resize (the order thumbnail()
    uses anyway), and treats the image as untagged when lcms rejects the profile.
  - Cost: the transform runs on the 8-bit thumbnail.
  - GD reads alpha as 7 bits, so run-fixtures can't guard this one.
- **GIF alpha**: libvips adds an alpha band only when some frame declares a transparent index,
  and `has_alpha_channel` follows it. cgif declares one on every frame, so the generator clears
  the flag in `animated.gif`.
- **The pyvips wheel has no BMP loader.** It does ship `svgload`, `heifload`, `tiffload` and
  more, all blocked. BMP → `handled: false`.
  - ⚠ For Phase 6: today GD thumbnails BMP. Only BMPs stored as BMP lose that (verbatim or
    `no_convert`; a default upload converts to webp). Still a regression to decide: keep GD
    for BMP, accept no thumbnail, or convert.
- **`requirements.txt` pins** pyvips 3.2.0, pyvips-binary 8.18.7 (= DreamHost's), cffi 2.1.1
  and pycparser 3.11.
- **Fixtures**: 23 files (~100 KB) plus `expected.json`, all generated.
  - Beyond §6.6's table:
    - `rgba-one-pixel.png`: one pixel at alpha 254 → is_opaque false;
    - `rgb.png`: a declared 72 DPI, reported as 72;
    - `jfif-dpcm.jpg`: 118 dots/cm → 300;
    - `cmyk.jpg`: CMYK without ICC → 3-band sRGB;
    - `transparent.gif` and `alpha.webp`: one fixture per unblocked loader;
    - `mismatch.png`: a PNG sent as image/jpeg → fails;
    - `plain.bmp`: handled false;
    - SVGs: `dims` (in mm), `viewbox`, `relative`, `illustrator`, `xxe`, `entity-nested`.
  - `orient6.jpg` also carries GPS, and EXIF 240 DPI vs JFIF 72 (EXIF wins).
  - `rgba16-icc-phys300.png` carries a hand-built ICC profile: linear gamma, sRGB primaries. That
    makes "renditions are sRGB" checkable, since linear gray 128 → ~188.
  - Every full run requests 4 specs (webp contain, jpg cover, png 2048, legacy), on images
    sized so every result is an exact integer.
- **run-fixtures.php checks more than §6.6 asked**:
  - every rendition's alpha and metadata (no EXIF, XMP, IPTC or text), read from the file
    itself;
  - pixel samples with GD: sRGB conversion, orientation direction, CMYK color, frame 0, jpg
    flattened onto white;
  - every failure carries a message;
  - the generated image is a 14142² RGBA PNG (5 MB, built in 2 s), with Turbopod's thumb 300
    and preview 1200;
  - no DB: it reads magrathea.conf, and `--command` tries an engine before it's configured.
- **Docs done now**: `api/processor/README.md` (contract, engine notes), `docs/deploy.md`
  ("Image processor" under step 4, requirements, config keys), and the conf sample (processor
  keys, admin warning). Left for Phase 8 (§9): the `deploy.sh` step and "Upgrading to 1.5.0".
  No changelog entry: nothing is user-visible yet, and Phase 6's entry covers it.
- **For Phase 6** (all done, §7.12):
  - All-digit rendition names become PHP int array keys. The runner casts them back to
    strings, and the DB layer must too, or `RenditionProfile` should forbid them. → forbidden.
  - Job dirs left by killed cron runs (different attempt numbers) aren't swept: `--process`
    should sweep `job-*` older than a day. → swept (`job-*` and `header-*`).
  - The inline and cron keys (`inline_max_*`, `cron_process_*`, `processing_max_attempts`)
    aren't in the conf sample yet, since nothing reads them. → in the sample and `deploy.md`.

Tests:
- `tests/Processing/{ProcessorRunner,ProcessorJobDir}Test.php`: 26 cases against fake
  processors (PHP scripts run by PHP_BINARY, no Python), plus `ExplorerConfigTest` +2 for
  `GetArgv`.
- phpunit: 166 green on the host (2 GD skips, as before), 173 in the dev image.
- run-fixtures: 876 checks green on the host (8 pixel checks skipped, no GD). 884 green in a
  throwaway container from the dev image, with Python 3.13 installed at run time and GD
  present; the Dockerfile is untouched.
- **Not run: on DreamHost.**

---

## 7. Phase 6: wiring (§3.4.3–3.4.5)   [L] ✅ done (deviations in §7.12)

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
- A `flock` on `processing_tmp_path/process.lock` (`CronLock::TryAcquire("process")`, §4.5): a
  run still going makes the next one exit at once, so nothing piles up on shared hosting.
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
✅ Removed, with `ImageProcessor::FitsInMemory()` (the §3.4 interim guard) and its test.

### 7.12 As built (2026-11-18) ✅

Decided during the work (asked, answered):
- **BMP: no renditions** (§6.7's open regression). A BMP stored as BMP (verbatim or `no_convert`)
  is `handled: false` → status `none`, `renditions: {}`, properties = getimagesize's dims. No GD
  path survives for it: §7.11 removed all of it. A default upload converts BMP to webp, which
  gets its thumb.
- **Inline runs read the staged copy**, not the object fetched back from storage (§4.3 said "one
  code path for upload, retry and regenerate"). The staged file is byte-identical to what `put()`
  stored; fetching it back would cost a storage round trip (and 2× temp disk on S3, §4.5) inside
  every small upload. `JobRunner::Run()` takes `$localOriginal`; everything after the input step
  is the same code. Cron and regenerate fetch.
- **Failures:**
  - A run that fails (exit ≠ 0, killed, timeout, storage error) goes back to the queue until
    `processing_max_attempts` (3). Meanwhile the file stays `pending` with `processing_error` =
    the last failure; after the last attempt, job and file are `failed`.
  - A run that finishes with some renditions failed is final: file and job `failed`, the
    renditions that were made are kept, and the error names the ones that weren't. Those failures
    repeat (the 28000 px panorama's preview).
  - A failed inline run counts as attempt 1; cron retries it with the full timeout.
  - The queue never retries a job in the same run (id cursor): the next minute's run does.
- **All-digit rendition names are refused** by `RenditionProfile` ("use something like `w300`").

Other deviations and additions:
- **Header runs:** none before an inline run (the full run gives every property: one processor
  start per small upload, not two), and none on bulk import (no response to fill; the import
  would start Python once per file). Measured in the dev image: a 640×480 PNG upload takes
  ~128 ms with inline processing vs ~29 ms with no processor; a queued one with its header run,
  ~114 ms.
- **Inline timeouts:** header run min(`processor_timeout_s`, 15 s), inline full run min(…, 30 s).
  A hung processor can't hold an upload for 120 s; a cut inline run is retried by cron.
- **`inline_max_bytes` compares the stored size**, after conversion (a 500 KB PNG becomes a 2 KB
  webp).
- **Processing never fails an upload, config included.** Garbage in a processing key
  (`processor_*`, `inline_*`, `processing_max_attempts`) or an invalid stored key profile makes
  every image upload `failed` with the key or the problem named, instead of a 5004. A failure to
  queue the job → `failed` ("processing couldn't be queued"). The conversion keys (§3.4) still
  5004, as before.
- **Status rules:** `none` for non-images (§8), no `processor_command`, an empty profile (`[]`),
  `handled: false` (BMP) and `renders: false` (SVG). A small SVG goes through an inline run like
  any image (nothing says "properties only" before the run), so its properties come from it.
- **Job ownership** (`ProcessingJobControl::LockIfOwned()`, `SELECT … FOR UPDATE`): the job must be
  `running` **with this run's attempt number**. A run whose lease expired and was claimed again
  by a later attempt can't commit, and it never deletes its objects either: same tokens, same
  paths as the live attempt. Only a superseded or deleted job deletes what it stored (§7.5 step 6
  as written would have deleted the live attempt's objects). Tested with a forced lease expiry.
- **`Database::Disconnect()` before the commit**: the processor may run longer than a short
  `wait_timeout`.
- **Queue housekeeping, every `--process` run:** fail running jobs whose lease expired on their
  last attempt, and queued jobs left with no attempts after `processing_max_attempts` was
  lowered (`FailExhausted()`); fail files `pending` with no live job for 10 minutes, which only an
  upload that died between its insert and its enqueue can leave (`FailOrphanedPending()`); delete
  finished jobs older than 30 days (`PurgeFinished()`); remove `job-*`/`header-*` folders older
  than a day (`SweepJobDirs()`, via `ProcessorJobDir::RemoveLeftover()`, never following links).
- **Delete path:** `DeleteFileAndStorage()` deletes the jobs (no "superseded then deleted": the
  missing row is the worker's signal), then reads the renditions, deletes their objects with the
  original's, then the rows. Jobs first, so a worker that committed before is seen, and one that
  hasn't can't. Tested with a slow processor and a delete mid-run: no objects left.
- **`BrowserAdmin::PurgeOrphan()` was broken by the new FKs** (a raw row delete, outside
  `DeleteFileAndStorage()`): it now deletes jobs, rendition rows and their objects too.
- **Regenerate:**
  - supersede + `pending` + enqueue in one transaction, so a worker never commits before the
    file says pending;
  - old renditions stay until the new job commits, and for good if it fails;
  - an empty profile deletes the renditions → `none`;
  - a non-image → `none`, no error;
  - an invalid key profile → `failed`;
  - no processor → 5004.
- **Response (§7.7):**
  - `renditions` and `thumbnail_url` are both null while `pending`, during a regenerate or a
    retry too: a rendition URL that's about to stop working isn't handed out.
  - `properties` is never null. An absent key = never computed; a null key = computed, not known.
    A stored null doesn't hide a known top-level value.
  - `{}` encodes as an object.
  - `FileResponse` builds both views; the share listing preloads renditions too.
- **`GET /key` gets `rendition_profile`** (effective; null when the stored one is broken), like
  `verbatim_default` and `max_upload_size`.
- **Admin:**
  - Key form: a JSON textarea. A refused profile comes back through a session flash (the exact
    message and what was typed), since feature actions render outside the layout and the URL only
    carries a flag.
  - The preset button is labeled "Preset: thumb 300 + preview 1200 (webp, contain)", not
    "Turbopod preset": a client's name doesn't belong in the product's UI.
  - The key list has a "Renditions" column.
  - Browser: a processing column (status, rendition names, error) and a "regenerate" POST.
  - The orphan check also lists rendition rows whose object is gone, with a purge that refuses
    while the object exists.
  - A read-only "Processing" page: job and file counts by status, the oldest queued job, the
    last 20 failures, and whether the processor is configured.
- **`cron.php --regenerate --key=ID [--folder=ID]`** is in (§7.6's nice-to-have): it queues, and
  `--process` drains. Modes are now mutually exclusive (`--process --run` → usage, exit 1).
- **`--process` output**: quiet when there's nothing to do (cron mails every line); exit 1 only
  when the run itself fails, not when jobs do.
- **Migration:** the thumbnail UPDATE keeps `updated_at` (`updated_at = updated_at`), and
  `thumbnail_path` gets a COMMENT ("unused since 1.5.0") in both files for parity. The `File`
  model drops `thumbnail_token`/`thumbnail_path` (models:verify: INFO).
- `TokenGenerator` checks `file_renditions.token` instead of `files.thumbnail_token` (every old
  thumbnail token was copied there).
- ConfigApp `thumbnail_size` is clamped to 1–2048 (the validator's bound); unreadable = 200.
- Docs written now, not left to Phase 8: openapi (`File.properties/processing/renditions`, new
  `FileProperties`/`Processing`/`Rendition`/`RenditionSpec`, `Key.rendition_profile`, `KeyUsage`,
  `SharedFile`, `POST /files`, `POST /file/{uuid}/renditions`, 5004), skill guide (mental model,
  Step 0, Workflows 1/4/5/6/7, error table, TS types with a polling helper, checklist,
  Images3 table), `deploy.md` (keys, "Image processor": where it runs, profiles, retries, BMP,
  **no processor = no thumbnails**; Cron: `--process` every minute, `--regenerate`), conf
  sample, processor README, changelog (**improvement:** ×2, **breaking:**, **new:**, **db:**).

Tests:
- phpunit: `tests/Processing/RenditionProfileTest.php` (normalization, bounds, 21 refusals, the
  default, the preset, a key's specs), `tests/File/FileResponseTest.php` (properties merge,
  status), `tests/Processing/ProcessingQueueTest.php` (job-folder sweep, the key form's parse).
  202 green on the host (2 GD skips), 209 in the dev image.
- Integration run (not committed; throwaway MariaDB 11 + the dev image with Python 3.13 and the
  pinned pyvips installed at run time, local storage, the real processor): 123 checks, all
  green. It covers:
  - the API controllers called directly: inline thumb, the preset key on the 16-bit fixture,
    queued + cron, the CLI and its lock;
  - regenerate twice (one live job, new URLs, old objects gone);
  - SVG, BMP verbatim and converted, txt, animated GIF;
  - every rendition failing (no retry); a hung processor (inline cut → cron retries → failed at 3,
    never stuck running);
  - lease expiry with a late attempt; an exhausted job; delete mid-run; key force-delete with
    pending jobs;
  - public file and folder shares; `GET /files`; `GET /key`; an invalid stored profile; no
    processor; an empty profile; bulk import; the orphan-pending sweep; `--regenerate` on
    null-dim thumbs; renditions not counted; no job folders left.
- Plus:
  - the 11 admin views rendered with a logged-in user, warnings included;
  - the POST handlers in subprocesses: a refused profile saves nothing and creates no key, a
    valid one is stored normalized, empty = default, a form without the field leaves it alone;
    regenerate; purge orphan file and rendition;
  - the migration on the 1.4.0 schema (`07cb4f4`) with thumbnails: same tables as a fresh
    `database.sql`, index order aside; tokens and URLs kept; `updated_at` unchanged;
  - `models:verify` and `check` clean (`check`'s one WARNING was the scratch setup's missing
    backups folder);
  - run-fixtures: 878 green in that container.
- **Not run:** S3/R2 (rendition `put()`s, `fetch()` from the queue), DreamHost (cron, the web
  PHP's `proc_open`), the HTTP router (routes were called as controllers; the new route is one
  `Add()` line), and a POST through the admin's CSRF check.

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
- [ ] `sha256` in the upload response = `sha256sum` of the download, verbatim and converted alike
- [ ] `--backfill-sha256` on a 1.4.x copy: every old file hashed, `updated_at` unchanged; a second concurrent run exits on the lock
- [ ] verbatim JPEG with GPS: stored with GPS; the renditions have no EXIF (renditions ✅ local, §7.12)
- [ ] key `verbatim_default=1`: absent flag → verbatim; `verbatim=0` → converted
- [ ] non-verbatim 60 MB PNG → 4131, no PHP fatal, nothing stored, quota untouched
- [ ] per-key limit: 150 MB key accepts 140 MB; a 5 MB key refuses 6 MB with 4002; `GET /key` shows each effective limit
- [x] small upload on an existing key: thumbnail in the upload response, `processing.status=ready` (local, §7.12)
- [x] large upload: `pending`, `renditions: null`; after `cron.php --process`: `ready`, renditions present (local)
- [x] processor killed by timeout → `failed` with a reason; retried up to max attempts; never stuck `running` (local)
- [x] two overlapping `--process` runs → the second exits on the lock
- [x] regenerate twice quickly → one live job; old rendition URLs 404 afterwards (local: objects gone)
- [x] delete a file mid-processing → no orphan objects (`OrphanCheck` clean) (local)
- [x] key force-delete with pending jobs → succeeds (no FK error)
- [x] public share of a processed file → `thumbnail_url` works, no `renditions`/`sha256` leak (local)
- [ ] `run-fixtures.php` green in dev **and** on DreamHost (dev ✅ 2026-11-18, §6.7; DreamHost pending)
- [ ] `vendor/bin/magrathea check` + `models:verify` clean (✅ on a throwaway DB, §7.12; not on a real instance)

---

## 11. Phase order and size

| Phase | Content | Size | Depends on | Status |
|---|---|---|---|---|
| 0 | DreamHost check, dev env, harness | S | — | check ✅ (manual items left), harness ✅, dev env (§1.3 Dockerfile/php.ini): no Docker changes for now (decided) |
| 1 | **XXE fix**, boolean parsing, version bump | S | — | ✅ |
| 2 | verbatim + guards (+ orientation fix) | M | 1 | ✅ code + tests; migration not applied, §3.7 acceptance pending |
| 3 | sha256 + `fetch()` | S | — | ✅ code + tests + backfill; S3 `fetch()` not run against a bucket; migration not applied |
| 4 | per-key upload limit | S | — | ✅ code + tests + docs; migration not applied |
| 5 | processor + fixtures (standalone) | L | 0 (engine choice) | ✅ code + fixtures + tests + docs; run-fixtures not run on DreamHost |
| 6 | schema, queue, renditions, regenerate, admin | L | 3 (`fetch`), 5 | ✅ code + tests + docs + integration run (local storage); migration not applied, S3 not run |
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
| §3.4 pipeline | GD thumbnail only, sync, top-crop, black-on-transparent jpg | `mode` field in the job; default thumb same name/size/fit/format, different pixels; migrated thumbs have null dims; **regeneration mints new rendition URLs**; video/PDF handlers cut, non-image files get `none` (§8); renditions don't count toward usage, and rendition dimensions are capped at 2048 px (§7.9); `renditions`/`thumbnail_url` are null while `pending`; a finished run with failed renditions is final (`failed`, no retry); BMP stored as BMP gets none; rendition names can't be all digits (§7.12) |

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
- **An admin login is shell access** (found in Phase 5, §6.7). The framework's Configuration
  File page writes `magrathea.conf`, `processor_command` included. Accepted and documented. The
  real fix is in MagratheaPHP.
- ~~**BMP loses its thumbnail in Phase 6**~~: **decided**, no renditions for a BMP stored as BMP
  (§7.12). Documented in the changelog (**breaking:**) and `deploy.md`.
- **No processor, no thumbnails.** Since Phase 6, PHP makes no thumbnail at all. An instance
  upgraded to 1.5.0 without `processor_command` (and the venv) stores every new image with
  `processing.status: none` and `thumbnail_url: null`, so the explorer app shows no thumbnails
  for new uploads. That includes **this dev environment**: the Docker image has no Python
  (§1.3, "no Docker changes for now"). The upgrade notes (Phase 8) must say it first.
- **Large uploads lose their immediate thumbnail.** Above the inline limits (16 MB / 40 MP) the
  upload response has `thumbnail_url: null`, and the explorer app shows nothing until a reload
  after cron. Clients that read `thumbnail_url` from the upload response need to poll.
- **Memory is per width, not per pixel.** `max_pixels` doesn't bound the processor's memory
  for very wide images. Over the cap, the run fails cleanly (a rendition error or exit 1); it
  doesn't take the host down.
