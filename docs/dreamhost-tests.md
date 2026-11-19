# DreamHost: setting up and testing the image processor

Since 1.5.0, Explorer runs a separate program, the **processor** (Python + libvips), for image
properties and renditions. This page sets it up on a DreamHost shared account and checks that
it works there. The general version is [deploy.md → Image processor](deploy.md#image-processor),
and the processor's contract is in [api/processor/README.md](../api/processor/README.md).

Do it once when setting up 1.5.0. After that, repeat step 3 after every deploy and after any
server change (see [When to run it again](#when-to-run-it-again)). Step 6 checks uploads from a
URL, on instances that turn them on.

## Before you start

SSH in as the site's user: the one PHP and cron run as on DreamHost. Every command below runs
as that user, never as anyone else, or the venv and the job folders end up with the wrong
owner.

Set two variables for the session:

```sh
SITE=~/files.example.com          # the folder holding the Explorer checkout (api/, docs/, …)
PHP=/usr/local/php84/bin/php      # the site's PHP version
```

On DreamHost, plain `php` on the command line is often a different version from the site's.
`ls -d /usr/local/php8*` lists the builds. Use the one the site runs (Panel → Websites →
Manage → PHP version). The 1.5.0 check found 8.4 at `/usr/local/php84/bin/php`.

## 1. Build the Python environment

```sh
rm -rf ~/explorer-venv
python3 -m venv ~/explorer-venv
~/explorer-venv/bin/pip install -r "$SITE/api/processor/vips/requirements.txt"
```

**What it's for:** it installs the processor's only dependencies, pyvips and libvips, in a
private Python environment (a *venv*) outside the site folder.

- DreamHost's Python (Ubuntu 24.04) refuses `pip install --user` (PEP 668), so a venv is the way.
- `requirements.txt` pins exact versions. `pyvips-binary` is libvips itself, as a
  self-contained wheel: no system library is involved, and DreamHost runs the same libvips
  build as dev.
- The `rm -rf` is safe: the venv holds nothing but installed packages. The 1.5.0 feasibility
  check already created a `~/explorer-venv` without the pins. Rebuilding it makes it match
  `requirements.txt` exactly.

**Check:** the last line should list `pyvips-3.2.0`, `pyvips-binary-8.18.7`, `cffi` and
`pycparser`. Then:

```sh
~/explorer-venv/bin/python "$SITE/api/processor/vips/processor.py" --version
```

This should print `{"engine": "pyvips 3.2.0 / libvips 8.18.7"}`.

## 2. Tell Explorer where the processor is

```sh
echo "processor_command = '[\"$HOME/explorer-venv/bin/python\",\"$(readlink -f "$SITE")/api/processor/vips/processor.py\"]'"
```

**What it's for:** it prints the exact `processor_command` line, with the absolute paths filled
in. Paste it into `$SITE/api/configs/magrathea.conf`, inside the environment section that
`use_environment` picks (normally `[production]`):

```ini
[production]
	…
	processor_command = '["/home/<user>/explorer-venv/bin/python","/home/<user>/files.example.com/api/processor/vips/processor.py"]'
```

- It's a JSON list: the program, then its arguments. Explorer runs it as is, never through a
  shell. That's why it needs absolute paths, and the single quotes around the whole value.
- Without this line, Explorer has no processor at all.
- The other processor settings have defaults that fit DreamHost (`processor_timeout_s` 120,
  `processor_memory_mb` 384, `max_pixels` 200000000). Leave them out unless step 3 says
  otherwise.

## 3. Run the contract fixtures

```sh
cd "$SITE/api"
$PHP processor/run-fixtures.php
```

**What it's for:** it proves that the processor works here, the way Explorer will call it. It
runs the processor on every test file in `api/processor/fixtures/`, through the same PHP code
Explorer uses, and compares the results with `fixtures/expected.json`:

- properties: DPI, alpha, opacity, bit depth, ICC, orientation, frames;
- renditions: size, format, transparency, no EXIF/GPS left in them, colors converted to sRGB,
  photos turned upright;
- refusals: decompression bombs, truncated files, a file that isn't the type it claims, SVGs
  with external entities.

Then it generates a 200-megapixel PNG (5 MB, a few seconds) and checks that the processor gets
through it under the memory limit. It reads `magrathea.conf` and never touches the database.

**Reading the result:** one line per fixture, `ok` or `FAIL`, with timings and the peak memory
of each run. Then a summary:

```
ok    rgba-transparent.png       header 0.07 s, 23 MB peak full 0.14 s, 36 MB peak
…
ok    generated 14142x14142 RGBA PNG full 4.58 s, 149 MB peak

Engine: pyvips 3.2.0 / libvips 8.18.7
884 checks passed, 0 failed.
```

Every check must pass. The exit code is 0 when they all pass, 1 when one failed, and 2 when it
couldn't run at all (no `processor_command`, unreadable config). Write down the peak memory on
the generated PNG: it's the number DreamHost's process watcher sees.

Useful flags:

| flag | does |
|---|---|
| `--verbose` | lists every check, not only the failures |
| `--skip-big` | skips the generated 200 MP image |
| `--only=orient6.jpg,cmyk.jpg` | runs only these fixtures |
| `--keep` | leaves each job folder in `api/tmp/`, to look at the renditions |
| `--command='["/path/python","/path/processor.py"]'` | tries a processor without editing `magrathea.conf` |

## 4. Check the web PHP

The command line and the website don't necessarily run the same PHP configuration. Explorer
starts the processor from web requests too, so the **web** PHP needs `proc_open`. Since 1.5.0
it also needs the XMLReader extension: without it, every SVG upload fails. `php -m` doesn't
show the web PHP, so ask it directly with a throwaway page:

```sh
cd "$SITE/api/src"
CHECK="check-$(openssl rand -hex 8).php"
cat > "$CHECK" <<'PHP'
<?php
header("Content-Type: text/plain");
echo "PHP ", PHP_VERSION, " (", PHP_SAPI, ")\n";
echo "proc_open: ", function_exists("proc_open") ? "yes" : "NO", "\n";
echo "XMLReader: ", class_exists("XMLReader") ? "yes" : "NO", "\n";
echo "curl (source_url): ", extension_loaded("curl") ? "yes" : "NO", "\n";
echo "intl (source_url, optional): ", function_exists("idn_to_ascii") ? "yes" : "no", "\n";
echo "max_execution_time: ", ini_get("max_execution_time"), "\n";
PHP
curl -s "https://files.example.com/$CHECK"
rm "$CHECK"
```

**What it's for:** `proc_open` and `XMLReader` must say `yes`, and so must `curl` where
`source_url_enabled` is on (step 6). `intl` only matters for international host names. The random file name keeps anyone from finding the
page in the seconds it exists, and the `rm` deletes it. Check that the `rm` ran.

## 5. Optional: run it once from cron

```cron
*/5 * * * * cd /home/<user>/files.example.com/api && /usr/local/php84/bin/php processor/run-fixtures.php > /home/<user>/run-fixtures-cron.log 2>&1
```

**What it's for:** the processing queue (`cron.php --process`, every minute) runs the processor from cron. That's where DreamHost's
process watcher and the command line's own `php.ini` apply. This line runs the same check under
the same conditions.

- Add it with `crontab -e`, below the `###--- END DREAMHOST BLOCK` line: lines above it get
  overwritten.
- Wait for one run, read `~/run-fixtures-cron.log`, then **delete the line**.

If the log ends early, or shows `killed by signal 9` on the generated PNG, the watcher killed
it. See below.

## 6. Uploads from a URL

Only where `magrathea.conf` has `source_url_enabled = "true"`. Two things are unknown on
DreamHost until measured: whether its resolver answers `dns_get_record()` quickly, and how long
its FastCGI layer lets one request run.

**DNS, from the site's PHP:**

```sh
time $PHP -r '$r = dns_get_record("example.com", DNS_A | DNS_AAAA); var_dump(is_array($r) && count($r) > 0);'
```

`bool(true)` in well under a second. Anything else, or seconds, means every `source_url` upload
gets 5021 "DNS lookup failed" (a warning before `bool(false)`), 40012 "the host doesn't resolve
to a public address" (no warning), or waits that long: ask DreamHost support.

**The longest download, through the website** (from your own machine). Put a file as large as
the biggest key limit (150 MB for Turbopod) in R2, make a presigned GET URL for it (an hour is
plenty), then:

```sh
URL='https://<account>.r2.cloudflarestorage.com/<bucket>/big-150mb.bin?X-Amz-…'   # the presigned URL
time curl -s -w '\nHTTP %{http_code}\n' -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" -H "Idempotency-Key: dh-check-$(date +%s)" \
  -d "{\"source_url\": \"$URL\", \"verbatim\": true}" \
  https://files.example.com/api/v1/files
```

- **Expected:** HTTP 200, a File whose `sha256` is the local file's (`sha256sum big-150mb.bin`),
  and the time it took. Write the time down here: it's the margin left under
  `source_url_timeout_s` (90 s) and under whatever DreamHost allows.
- **An HTML error page, a 500 or a 504 that isn't Explorer's JSON,** at a round number of
  seconds: that's DreamHost's FastCGI timeout. Note it. If it's under ~120 s, lower
  `source_url_timeout_s` to leave room for the upload to storage and processing after the
  download, or keep key limits smaller.
- **5021 "timed out after 90 s":** the download from R2 to DreamHost is slower than ~1.7 MB/s.
  Raise `source_url_timeout_s` only if the FastCGI timeout leaves room (and never above 90
  behind Cloudflare).
- Delete the test file afterwards: `DELETE /file/{uuid}` with the same key.

## When to run it again

Run step 3 again:

- **after every deploy**;
- **after DreamHost upgrades the server** (OS or Python). The venv points at the system's Python
  binary: when that binary changes, every processor run fails until the venv is rebuilt (step 1);
- after changing `processor_command`, `processor_memory_mb`, `processor_timeout_s` or
  `max_pixels`.

## When something fails

| message | meaning | fix |
|---|---|---|
| `processor_command isn't set in magrathea.conf` | step 2 is missing, or the line went into a section `use_environment` doesn't pick | step 2 |
| `processor_command[0] isn't an absolute path to an executable file` | the Python path is wrong, or the venv is gone | check the path; step 1 |
| `No module named 'pyvips'`, `No such file or directory`, `cannot execute` | the venv is broken: DreamHost upgraded Python | step 1, then step 3 |
| `killed by signal 9` | something outside Explorer killed the processor: usually DreamHost's process watcher | rerun once (the watcher can be transient). If it persists on the generated PNG, lower `max_pixels` (e.g. `100000000`) and `processor_memory_mb` (e.g. `256`) together, and rerun |
| `out of memory` on the generated PNG | `processor_memory_mb` is too low for `max_pixels` | raise `processor_memory_mb`, or lower `max_pixels` |
| `time limit exceeded (120 s)` | the server is far slower than expected (a 200 MP run takes ~5 s) | look at the timings; raise `processor_timeout_s` only if they're all slow |
| `the processor can't be started (proc_open failed)` | `proc_open` is disabled | step 4; ask DreamHost support |
| `N skipped` in the summary | pixel checks need GD, missing from this PHP | use the site's PHP build (`$PHP`), which has GD |
| any other `FAIL` | the engine doesn't do what the contract says on this server | keep the full `--verbose` output for the report |
