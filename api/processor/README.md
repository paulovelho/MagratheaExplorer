# The processor

Explorer extracts image properties and makes renditions (thumbnails, previews) through a
separate program: the **processor**. One file in, properties and files out. The command is
set per instance (`processor_command` in `magrathea.conf`), so the engine can be replaced
without touching Explorer. Setting it up on a server: [docs/deploy.md](../../docs/deploy.md#image-processor).

```
processor/
  vips/processor.py         engine #1: pyvips (libvips), Python 3 (run on 3.10 and 3.13)
  vips/requirements.txt     pinned; the venv is built from it
  fixtures/                 contract fixtures + expected.json (committed)
  fixtures/make-fixtures.py regenerates them (dev only, needs pyvips)
  run-fixtures.php          checks the configured engine against the fixtures
  diag/dreamhost-check.php  the 1.5.0 engine feasibility test, kept for new servers
```

This folder is outside `api/src`: not served by the web server, not autoloaded, and the engine
contains no Explorer code. Explorer's side is `src/features/Processing/`: `ProcessorRunner.php`
runs the engine; `FileProcessing.php` (what happens at upload and on regenerate), `JobRunner.php`
(one job, the same code inline and from the queue) and `ProcessingQueue.php` (`cron.php --process`)
use it.

## Running the fixtures

```sh
php api/processor/run-fixtures.php            # the engine in magrathea.conf
php api/processor/run-fixtures.php --command='["/home/u/explorer-venv/bin/python","/home/u/explorer/api/processor/vips/processor.py"]'
```

It runs every fixture in both modes through `ProcessorRunner`, exactly as Explorer will. Then it
generates a PNG at `max_pixels` and checks that the engine gets through it under
`processor_memory_mb`. Exit code 0 means every check passed. `--verbose` lists every check,
`--keep` leaves the job dirs in `processing_tmp_path`, `--skip-big` skips the generated image,
and `--only=a.png,b.jpg` runs only those fixtures. Pixel checks need GD and are skipped
without it.

Run it in dev, and on the server after every deploy or server change: a Python upgrade breaks
the venv silently, and this is where it shows.

To change a fixture, edit `fixtures/make-fixtures.py` and rerun it (it rewrites the files and
`expected.json`): `~/explorer-venv/bin/python api/processor/fixtures/make-fixtures.py`.

## Contract

Explorer starts the command with `proc_open` and an argv array (never a shell). Its working
directory is the job's `tmp/`, and its whole environment is `PATH=/usr/local/bin:/usr/bin:/bin`,
`TMPDIR` (the job's `tmp/`), `VIPS_CONCURRENCY=1` and `VIPS_BLOCK_UNTRUSTED=1`. It writes the
job to stdin and closes it. Then it reads stdout (capped at 1 MB) and stderr together until the
process exits. It kills the process (SIGKILL) at `processor_timeout_s`.

### Job (stdin)

```json
{
  "input": "/home/u/explorer/api/tmp/job-123-1/original",
  "file_type": "image",
  "mime_type": "image/png",
  "mode": "full",
  "out_dir": "/home/u/explorer/api/tmp/job-123-1/out",
  "renditions": [
    {"name": "thumb", "width": 300, "height": 300, "fit": "contain", "format": "webp", "quality": 85},
    {"name": "preview", "width": 1200, "height": 1200, "fit": "contain", "format": "jpg", "quality": 85, "background": "#ffffff"}
  ],
  "limits": {"memory_mb": 384, "max_pixels": 200000000}
}
```

- `mime_type` is what Explorer sniffed. The engine opens the file **as that type and nothing
  else**: a PNG sent as `image/jpeg` fails.
- `mode`:
  - `header` reads properties from headers only and must not decode pixels, because it runs
    inline at upload. `renditions` is empty.
  - `full` gives every property and every rendition.
- A rendition spec has `name` (`[a-z0-9_-]{1,32}`, unique), `width`/`height` (a bounding box),
  `fit` (`contain` or `cover`), `format` (`webp`, `png` or `jpg`), `quality` (1–100, default 85)
  and `background` (`#rrggbb`, jpg only, default white). Explorer resolves its own `legacy`
  format (png for a png original, jpg otherwise) before sending the job.
- `limits.memory_mb` is the engine's memory cap. Explorer can't set it from outside, so the
  engine applies it to itself. `limits.max_pixels` is the decompression-bomb guard.

The engine reads only `input` and writes only inside `out_dir`. It has no network access, no
database, no storage and no Explorer code.

### Result (stdout, exit 0)

```json
{
  "handled": true,
  "renders": true,
  "properties": {"width": 4500, "height": 5400, "orientation": null, "has_alpha_channel": true, "is_opaque": false,
                 "dpi_x": 300, "dpi_y": 300, "bit_depth": 8, "color_space": "srgb", "has_icc_profile": true,
                 "animated": false, "frame_count": 1},
  "renditions": [
    {"name": "thumb", "path": "/home/u/explorer/api/tmp/job-123-1/out/thumb.webp", "width": 250, "height": 300, "mime_type": "image/webp", "size": 18342}
  ],
  "errors": {"preview": "can't save jpg: …"},
  "engine": "pyvips 3.2.0 / libvips 8.18.7"
}
```

- `handled: false`: this engine has no handler for the type. Explorer records processing
  status `none`, which is not an error. Nothing else counts.
- `renders: false`: the handler gives properties only (SVG is never rasterized).
- `properties`: every key always present, `null` when unknown or not computed.
- `renditions`: the ones made. `errors`: the ones that failed, by name, each with a reason.
  A spec in neither becomes an error on Explorer's side.
- Image bigger than `max_pixels` (width × height from the header): exit 0, the header
  properties, every rendition in `errors`, nothing decoded.

### Failure (exit ≠ 0)

- `1`: the file can't be processed (not what its type says, corrupt, truncated).
- `2`: the job is invalid.

The engine writes one line `processor: <reason>` to stderr and nothing to stdout. Explorer takes
the reason from that line, or from stderr's last line when there's none (a Python traceback
ends with the exception). Whatever the engine wrote to `out_dir` is ignored. A decode error
fails the whole job, even halfway through the renditions: the file is broken, not one
rendition.

### What Explorer checks

Nothing from the engine is trusted:

- A rendition counts only if its `path` is absolute and is a regular file inside `out_dir`:
  not a symlink, not a hard link. `getimagesize()` must read it as the requested format. Its
  dimensions and size are measured, not copied from the result.
- A property outside its type or range becomes `null`.
- Error text is cut to 500 characters, with the job's paths masked (`{input}`, `{job}`,
  `{tmp}`).

### Properties

| key | meaning |
|---|---|
| `width`, `height` | pixels as stored, before EXIF orientation. Animated: one frame. SVG: the root's declared size in CSS px (96 per inch), from `width`/`height`, else the `viewBox` |
| `orientation` | EXIF orientation 1–8, `null` when the file has none |
| `has_alpha_channel` | the format or color type carries alpha: PNG color type 4/6 or `tRNS`, WebP's alpha flag, a GIF with any frame declaring a transparent index |
| `is_opaque` | `true` with no alpha channel, or when every pixel of every frame is fully opaque (one pass at full resolution, never a downscaled copy). `null` in header mode when there's an alpha channel, and for an animation whose frames together exceed `max_pixels` |
| `dpi_x`, `dpi_y` | declared resolution, rounded to an integer (11811 px/m is 300). PNG: pHYs, then EXIF. JPEG: EXIF, then JFIF. WebP: EXIF. `null` when the file declares none, never 72 by default. JFIF units 0 (aspect ratio only) is `null` |
| `bit_depth` | bits per channel after decoding: 8 or 16 (a palette PNG is 8) |
| `color_space` | `srgb` (any RGB: the ICC profile, if any, says which), `gray`, `cmyk`, `lab`, else libvips' name |
| `has_icc_profile` | an embedded ICC profile |
| `animated`, `frame_count` | more than one frame; the number of frames |

### Renditions

- EXIF orientation is applied.
- Converted to sRGB when the source has an ICC profile, or is CMYK or 16-bit. Always 8-bit.
- **No metadata**: no EXIF, XMP, IPTC or ICC. Renditions are public, and an original stored
  verbatim keeps its GPS: its thumbnail must not.
- Never upscaled:
  - `contain` fits inside the box and keeps the aspect ratio;
  - `cover` crops to fill the box, centered. Where the image is smaller than the box on a
    side, that side keeps the image's size.
- webp and png keep transparency. jpg flattens onto `background`.
- Animated images: frame 0.

## Engine #1: pyvips

- **Loaders.** It blocks every libvips loader, then unblocks only the four it uses (`png`,
  `jpeg`, `webp`, `gif`). This is the libvips version of a restrictive `policy.xml`. The wheel
  ships `svgload` (librsvg) and others, and none of them can run, whatever a file's bytes look
  like. The loader is chosen from `mime_type`. `thumbnail()` opens files by name through libvips'
  sniffer, so the engine checks first that the sniffer picks the same loader.
- **Memory.** `RLIMIT_DATA` = `limits.memory_mb`, set at startup, before libvips loads.
  Measured (1.5.0, `VIPS_CONCURRENCY=1`):
  - thumbnail memory grows with the image's **width**, not its pixel count. For one 1200 px
    rendition: 7000 px wide ~86 MB, 14142 px ~141 MB, 28000 px ~185 MB of RSS;
  - a whole job (opacity pass and two renditions): a 200 MP square peaks at 150–210 MB
    depending on the machine, and fails at 128 MB. A 28000×3500 panorama peaks at ~260 MB, and
    at 256 MB its 1200 px rendition fails;
  - hence the default of 384 MB. Wider still runs out, and that ends as a clean failure: a
    rendition in `errors`, or exit 1;
  - small files use ~35 MB.
- **SVG** is parsed by expat, never rendered. The DTD gets the same rule as Explorer's upload
  validator (`UploadValidator::Svg()`): plain internal entities only. An external DTD reference
  is never fetched. Parsing stops at the root element.
- **No BMP.** The wheel has no BMP loader, so BMP is `handled: false`.
- **libvips quirk.** `thumbnail()` with `output_profile` turns opaque pixels of a 16-bit RGBA
  source into alpha 254 (8.18.7). The engine applies the ICC profile itself after the resize.
  run-fixtures.php can't see this: GD reads alpha as 7 bits.
