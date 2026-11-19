#!/usr/bin/env python3
"""
MagratheaExplorer processor, engine #1: pyvips (libvips).

One file in, properties and renditions out. Reads a JSON job on stdin, writes a JSON result on
stdout. The contract (job, result, exit codes, every property's meaning) is in
api/processor/README.md, and api/processor/run-fixtures.php checks an engine against it.

A pure function: it reads only `input`, writes only inside `out_dir`, and has no network, no
database, no storage and no Explorer code. Explorer stores the outputs and deletes the job dir.

Exit codes: 0 = processed (some renditions may be in `errors`), 1 = the file can't be processed
(not what its type says, corrupt, truncated), 2 = the job itself is invalid. On 1 and 2, one line
`processor: <message>` goes to stderr and stdout stays empty.
"""

import os
import sys

# libvips reads these when it starts, which is when pyvips is imported. Explorer's runner sets
# them too: this covers a run by hand.
os.environ.setdefault("VIPS_CONCURRENCY", "1")
os.environ["VIPS_BLOCK_UNTRUSTED"] = "1"

import json
import math
import re
import resource
import struct
import xml.parsers.expat

EXIT_FAILED = 1
EXIT_BAD_JOB = 2

MAX_JOB_BYTES = 1024 * 1024
MAX_RENDITIONS = 16
NAME_RE = re.compile(r"^[a-z0-9_-]{1,32}$")
COLOR_RE = re.compile(r"^#[0-9a-fA-F]{6}$")

# Sniffed mime type → (forced loader, the libvips class its sniffer must pick). Only these
# loaders are unblocked (see setup_vips()): libvips can't open anything else, whatever the bytes.
RASTER = {
	"image/png": ("pngload", "VipsForeignLoadPng"),
	"image/jpeg": ("jpegload", "VipsForeignLoadJpeg"),
	"image/webp": ("webpload", "VipsForeignLoadWebp"),
	"image/gif": ("gifload", "VipsForeignLoadNsgif"),
}
SVG = "image/svg+xml"

FORMATS = {
	"webp": ("webp", "image/webp"),
	"png": ("png", "image/png"),
	"jpg": ("jpg", "image/jpeg"),
}

PROPERTY_KEYS = (
	"width", "height", "orientation", "has_alpha_channel", "is_opaque", "dpi_x", "dpi_y",
	"bit_depth", "color_space", "has_icc_profile", "animated", "frame_count",
)

BITS = {"uchar": 8, "char": 8, "ushort": 16, "short": 16, "uint": 32, "int": 32, "float": 32, "double": 64}
ALPHA_MAX = {"uchar": 255, "char": 127, "ushort": 65535, "short": 32767}
COLOR_SPACES = {
	"srgb": "srgb", "rgb16": "srgb",
	"b-w": "gray", "grey16": "gray",
	"cmyk": "cmyk",
	"lab": "lab", "labs": "lab", "labq": "lab",
}


class BadJob(Exception):
	"""The job is invalid: exit 2."""


class FileFailed(Exception):
	"""The input can't be processed: exit 1."""


def main(argv):
	if len(argv) > 1 and argv[1] == "--version":
		print(json.dumps({"engine": engine_name(import_vips())}))
		return 0
	try:
		job = read_job()
		apply_limits(job["limits"])
		result = run(job)
	except BadJob as e:
		return fail(EXIT_BAD_JOB, "invalid job: " + str(e))
	except FileFailed as e:
		return fail(EXIT_FAILED, str(e))
	except MemoryError:
		return fail(EXIT_FAILED, "out of memory (limits.memory_mb)")
	except Exception as e:  # never a traceback: the runner keeps stderr's tail as the reason
		return fail(EXIT_FAILED, "%s: %s" % (type(e).__name__, one_line(str(e))))
	sys.stdout.write(json.dumps(result, separators=(",", ":")))
	sys.stdout.flush()
	return 0


def fail(code, message):
	sys.stdout.flush()
	sys.stderr.write("processor: " + one_line(message)[:900] + "\n")
	sys.stderr.flush()
	return code


def one_line(text):
	return " ".join(str(text).split())


# ---------------------------------------------------------------------------------------- job

def read_job():
	raw = sys.stdin.buffer.read(MAX_JOB_BYTES + 1)
	if len(raw) > MAX_JOB_BYTES:
		raise BadJob("larger than 1 MB")
	try:
		job = json.loads(raw)
	except ValueError as e:
		raise BadJob("not JSON (%s)" % e)
	if not isinstance(job, dict):
		raise BadJob("not an object")

	for key in ("input", "mime_type", "out_dir", "mode"):
		if not isinstance(job.get(key), str) or job[key] == "":
			raise BadJob("`%s` must be a non-empty string" % key)
	if job["mode"] not in ("header", "full"):
		raise BadJob("`mode` must be header or full")
	if not os.path.isabs(job["input"]) or not os.path.isfile(job["input"]):
		raise BadJob("`input` must be an absolute path to a file")
	if not os.path.isabs(job["out_dir"]) or not os.path.isdir(job["out_dir"]):
		raise BadJob("`out_dir` must be an absolute path to a directory")
	if not isinstance(job.get("file_type", ""), str):
		raise BadJob("`file_type` must be a string")

	limits = job.get("limits")
	if not isinstance(limits, dict):
		raise BadJob("`limits` must be an object")
	for key in ("memory_mb", "max_pixels"):
		if not is_int(limits.get(key)) or limits[key] < 1:
			raise BadJob("`limits.%s` must be a positive integer" % key)

	specs = job.get("renditions", [])
	if not isinstance(specs, list) or len(specs) > MAX_RENDITIONS:
		raise BadJob("`renditions` must be a list of at most %d specs" % MAX_RENDITIONS)
	names = set()
	job["renditions"] = []
	for spec in specs:
		spec = read_spec(spec)
		if spec["name"] in names:
			raise BadJob("rendition `%s` appears twice" % spec["name"])
		names.add(spec["name"])
		job["renditions"].append(spec)
	return job


def read_spec(spec):
	if not isinstance(spec, dict):
		raise BadJob("a rendition spec must be an object")
	name = spec.get("name")
	if not isinstance(name, str) or not NAME_RE.match(name):
		raise BadJob("rendition name must match [a-z0-9_-]{1,32}")
	for key in ("width", "height"):
		if not is_int(spec.get(key)) or not 1 <= spec[key] <= 16384:
			raise BadJob("rendition `%s`: `%s` must be an integer from 1 to 16384" % (name, key))
	if spec.get("fit") not in ("contain", "cover"):
		raise BadJob("rendition `%s`: `fit` must be contain or cover" % name)
	if spec.get("format") not in FORMATS:
		raise BadJob("rendition `%s`: `format` must be webp, png or jpg" % name)
	quality = spec.get("quality", 85)
	if not is_int(quality) or not 1 <= quality <= 100:
		raise BadJob("rendition `%s`: `quality` must be an integer from 1 to 100" % name)
	background = spec.get("background", "#ffffff")
	if not isinstance(background, str) or not COLOR_RE.match(background):
		raise BadJob("rendition `%s`: `background` must be #rrggbb" % name)
	return {
		"name": name, "width": spec["width"], "height": spec["height"], "fit": spec["fit"],
		"format": spec["format"], "quality": quality,
		"background": [int(background[i:i + 2], 16) for i in (1, 3, 5)],
	}


def is_int(value):
	return isinstance(value, int) and not isinstance(value, bool)


def apply_limits(limits):
	"""The memory cap the job asks for, on this process: Explorer can't set it from outside."""
	cap = limits["memory_mb"] * 1024 * 1024
	resource.setrlimit(resource.RLIMIT_DATA, (cap, cap))
	resource.setrlimit(resource.RLIMIT_CORE, (0, 0))


def empty_properties():
	return {key: None for key in PROPERTY_KEYS}


def run(job):
	mime = job["mime_type"]
	if job.get("file_type", "image") == "image" and mime in RASTER:
		return run_raster(job)
	if job.get("file_type", "image") == "image" and mime == SVG:
		return {
			"handled": True, "renders": False, "properties": svg_properties(job["input"]),
			"renditions": [], "errors": {}, "engine": "pyvips (svg: expat)",
		}
	return {
		"handled": False, "renders": False, "properties": empty_properties(),
		"renditions": [], "errors": {}, "engine": "pyvips",
	}


# -------------------------------------------------------------------------------------- vips

def import_vips():
	import pyvips
	return pyvips


def engine_name(pyvips):
	return "pyvips %s / libvips %d.%d.%d" % (
		pyvips.__version__, pyvips.version(0), pyvips.version(1), pyvips.version(2))


def setup_vips():
	"""
	The libvips version of a restrictive policy.xml: every loader is blocked, then only the four
	the processor uses are unblocked. The wheel ships svgload (librsvg) and others: none of them
	can run, whatever a file's bytes look like.
	"""
	pyvips = import_vips()
	pyvips.block_untrusted_set(True)
	pyvips.operation_block_set("VipsForeignLoad", True)
	for _loader, cls in RASTER.values():
		pyvips.operation_block_set(cls, False)
	pyvips.concurrency_set(1)
	pyvips.cache_set_max(0)
	pyvips.cache_set_max_mem(0)
	pyvips.cache_set_max_files(0)
	return pyvips


def sniffed_loader(pyvips, path):
	"""The class libvips' own sniffer picks for this file, as thumbnail() will."""
	from pyvips import ffi, vips_lib
	name = vips_lib.vips_foreign_find_load(path.encode())
	return None if name == ffi.NULL else ffi.string(name).decode()


def run_raster(job):
	pyvips = setup_vips()
	path, mime = job["input"], job["mime_type"]
	loader, cls = RASTER[mime]
	try:
		image = getattr(pyvips.Image, loader)(path)  # header only: libvips decodes lazily
	except pyvips.Error as e:
		raise FileFailed("not a readable %s: %s" % (mime, vips_message(e)))

	props = raster_properties(image, path, mime)
	result = {
		"handled": True, "renders": True, "properties": props,
		"renditions": [], "errors": {}, "engine": engine_name(pyvips),
	}
	if job["mode"] == "header":
		return result

	pixels = props["width"] * props["height"]
	if pixels > job["limits"]["max_pixels"]:
		reason = "image is %d px, above max_pixels (%d)" % (pixels, job["limits"]["max_pixels"])
		result["errors"] = {spec["name"]: reason for spec in job["renditions"]}
		return result

	# thumbnail() opens the file by name, through libvips' sniffer: it has to land on the same
	# loader as the type Explorer sniffed, or the file isn't what it claims to be.
	found = sniffed_loader(pyvips, path)
	if found is None or not found.startswith(cls):
		raise FileFailed("content doesn't match %s (libvips sees %s)" % (mime, found or "nothing it can load"))

	if props["is_opaque"] is None:
		props["is_opaque"] = opaque(pyvips, path, loader, props, job["limits"]["max_pixels"])

	for spec in job["renditions"]:
		rendition, error = render(pyvips, path, props, spec, job["out_dir"])
		if rendition is not None:
			result["renditions"].append(rendition)
		else:
			result["errors"][spec["name"]] = error
	return result


def vips_message(error):
	return one_line(getattr(error, "detail", "") or getattr(error, "message", "") or str(error))[:300]


def raster_properties(image, path, mime):
	fields = set(image.get_fields())
	props = empty_properties()
	props["width"] = image.width
	props["height"] = image.height  # one frame's height: page-height, n=1 is the default

	if "orientation" in fields:
		orientation = image.get("orientation")
		props["orientation"] = orientation if is_int(orientation) and 1 <= orientation <= 8 else None

	# libvips adds an alpha band when the format or color type carries one: PNG color types 4/6
	# or tRNS, WebP's alpha flag, a GIF with any frame declaring a transparent index.
	props["has_alpha_channel"] = bool(image.hasalpha())
	# true without an alpha channel; otherwise it takes a pass over the pixels (full mode)
	props["is_opaque"] = True if not props["has_alpha_channel"] else None

	props["bit_depth"] = BITS.get(image.format)
	props["color_space"] = COLOR_SPACES.get(image.interpretation, image.interpretation)
	props["has_icc_profile"] = "icc-profile-data" in fields

	frames = image.get("n-pages") if "n-pages" in fields else 1
	props["frame_count"] = frames if is_int(frames) and frames >= 1 else 1
	props["animated"] = props["frame_count"] > 1

	exif = image.get("exif-data") if "exif-data" in fields else None
	props["dpi_x"], props["dpi_y"] = declared_dpi(path, mime, exif)
	return props


def opaque(pyvips, path, loader, props, max_pixels):
	"""
	true when every pixel of every frame is fully opaque. One sequential pass over the alpha band
	at full resolution: a downscaled copy averages single semi-transparent pixels away.
	"""
	frames = props["frame_count"]
	if props["width"] * props["height"] * frames > max_pixels:
		return None  # an animation too big to scan whole: unknown, not an error
	options = {"access": "sequential", "fail_on": "error"}
	if frames > 1:
		options["n"] = -1
	try:
		image = getattr(pyvips.Image, loader)(path, **options)
		if not image.hasalpha():
			return True
		top = ALPHA_MAX.get(image.format)
		if top is None:
			return None
		return image[image.bands - 1].min() >= top
	except pyvips.Error as e:
		raise FileFailed("can't decode the image: " + vips_message(e))


def render(pyvips, path, props, spec, out_dir):
	"""(rendition, None) or (None, error). A decode error fails the whole job (FileFailed)."""
	ext, mime_type = FORMATS[spec["format"]]
	options = {
		"height": spec["height"],
		"size": "down",  # never upscaled
		"crop": "centre" if spec["fit"] == "cover" else "none",
		"fail_on": "error",
	}
	try:
		# thumbnail() turns the image upright from its EXIF orientation and shrinks on load where
		# the format allows it. GIF and WebP: frame 0. It ignores the ICC profile unless given
		# output_profile, and with it a 16-bit RGBA source comes out at alpha 254 where it was
		# opaque (libvips 8.18.7): the profile is applied here instead, to the small image,
		# which is the order thumbnail() uses anyway.
		image = pyvips.Image.thumbnail(path, spec["width"], **options)
		if props["has_icc_profile"]:
			try:
				image = image.icc_transform("srgb", embedded=True)
			except pyvips.Error:
				pass  # a profile lcms can't use: treated as untagged, as a browser would
		if image.interpretation != "srgb":
			image = image.colourspace("srgb")  # 16-bit, gray, CMYK without a profile
		if image.format != "uchar":
			image = image.cast("uchar")
		if spec["format"] == "jpg" and image.hasalpha():
			image = image.flatten(background=spec["background"])
		image = image.copy_memory()  # decode here: a broken file fails now, not inside a save
	except pyvips.Error as e:
		raise FileFailed("can't decode the image: " + vips_message(e))

	target = os.path.join(out_dir, spec["name"] + "." + ext)
	try:
		# keep="none": no EXIF, XMP, IPTC or ICC. Renditions are public, and a verbatim original
		# keeping its GPS doesn't mean its thumbnail should.
		if spec["format"] == "webp":
			image.webpsave(target, Q=spec["quality"], keep="none")
		elif spec["format"] == "png":
			image.pngsave(target, keep="none")
		else:
			image.jpegsave(target, Q=spec["quality"], keep="none", optimize_coding=True)
	except pyvips.Error as e:
		if os.path.lexists(target):
			os.unlink(target)
		return None, "can't save %s: %s" % (spec["format"], vips_message(e))
	return {
		"name": spec["name"], "path": target, "width": image.width, "height": image.height,
		"mime_type": mime_type, "size": os.path.getsize(target),
	}, None


# ---------------------------------------------------------------------------------- headers

def declared_dpi(path, mime, exif):
	"""
	(dpi_x, dpi_y) as the file declares them, else (None, None): never a default. libvips can't
	be asked, it fills in 72 DPI when a file declares nothing. PNG: pHYs, then EXIF. JPEG: EXIF,
	then JFIF. WebP: EXIF. GIF has no resolution.
	"""
	try:
		if mime == "image/png":
			return png_phys(path) or exif_resolution(exif) or (None, None)
		if mime == "image/jpeg":
			return exif_resolution(exif) or jfif_density(path) or (None, None)
		if mime == "image/webp":
			return exif_resolution(exif) or (None, None)
	except (OSError, ValueError, struct.error):
		pass
	return None, None


def dpi_pair(x, y):
	x, y = round_dpi(x), round_dpi(y)
	return (x, y) if x is not None and y is not None else None


def round_dpi(value):
	if value is None or not math.isfinite(value):
		return None
	value = int(round(value))  # 11811 px/m is 299.9994 DPI: a print check wants 300
	return value if 1 <= value <= 1000000 else None


def png_phys(path):
	with open(path, "rb") as f:
		if f.read(8) != b"\x89PNG\r\n\x1a\n":
			return None
		while True:
			head = f.read(8)
			if len(head) < 8:
				return None
			length, kind = struct.unpack(">I4s", head)
			if kind in (b"IDAT", b"IEND"):
				return None  # pHYs must come before the image data
			if kind == b"pHYs" and length == 9:
				ppu_x, ppu_y, unit = struct.unpack(">IIB", f.read(9))
				if unit != 1:
					return None  # unit 0 only gives the aspect ratio
				return dpi_pair(ppu_x * 0.0254, ppu_y * 0.0254)
			f.seek(length + 4, os.SEEK_CUR)


def jfif_density(path):
	with open(path, "rb") as f:
		if f.read(2) != b"\xff\xd8":
			return None
		while True:
			marker = f.read(2)
			if len(marker) < 2 or marker[0] != 0xFF:
				return None
			if marker[1] in (0xDA, 0xD9):  # start of scan, end of image
				return None
			if marker[1] == 0x01 or 0xD0 <= marker[1] <= 0xD7:
				continue  # markers without a length
			size = struct.unpack(">H", f.read(2))[0]
			if size < 2:
				return None
			data = f.read(size - 2)
			if marker[1] == 0xE0 and data[:5] == b"JFIF\x00" and len(data) >= 12:
				unit, x, y = struct.unpack(">BHH", data[7:12])
				if unit == 1:
					return dpi_pair(x, y)
				if unit == 2:
					return dpi_pair(x * 2.54, y * 2.54)
				return None  # unit 0: aspect ratio only, the "72 DPI" trap


def exif_resolution(exif):
	"""XResolution/YResolution/ResolutionUnit from IFD0 of a TIFF-structured EXIF block."""
	if not exif:
		return None
	data = bytes(exif)
	if data.startswith(b"Exif\x00\x00"):
		data = data[6:]
	if data[:4] == b"II*\x00":
		order = "<"
	elif data[:4] == b"MM\x00*":
		order = ">"
	else:
		return None
	ifd = struct.unpack(order + "I", data[4:8])[0]
	count = struct.unpack(order + "H", data[ifd:ifd + 2])[0]
	values, unit = {}, 2  # ResolutionUnit defaults to inches
	for i in range(min(count, 512)):
		entry = data[ifd + 2 + i * 12: ifd + 14 + i * 12]
		if len(entry) < 12:
			break
		tag, kind, n, value = struct.unpack(order + "HHI4s", entry)
		if tag in (0x011A, 0x011B) and kind == 5 and n == 1:  # RATIONAL
			offset = struct.unpack(order + "I", value)[0]
			num, den = struct.unpack(order + "II", data[offset:offset + 8])
			values[tag] = num / den if den else None
		elif tag == 0x0128 and kind == 3:  # SHORT
			unit = struct.unpack(order + "H", value[:2])[0]
	if unit not in (2, 3) or 0x011A not in values or 0x011B not in values:
		return None
	scale = 1 if unit == 2 else 2.54
	x, y = values[0x011A], values[0x011B]
	return dpi_pair(x * scale if x else None, y * scale if y else None)


# -------------------------------------------------------------------------------------- svg

SVG_MAX_INTERNAL_SUBSET = 65536
SVG_MAX_ENTITY_VALUE = 1024
SVG_UNITS = {None: 1.0, "px": 1.0, "pt": 96 / 72, "pc": 16.0, "mm": 96 / 25.4, "cm": 96 / 2.54, "in": 96.0}
SVG_LENGTH = re.compile(r"^\s*\+?((?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?)\s*(px|pt|pc|mm|cm|in)?\s*$")


class _RootReached(Exception):
	pass


def svg_properties(path):
	"""
	`width`/`height` declared on the root element, in CSS px (96 per inch), from its width/height
	attributes, else its viewBox. Everything else is null: SVG is never rasterized.

	The DTD gets the same rule as Explorer's upload validator (UploadValidator::Svg()), enforced
	again here: plain internal entities only (`<!ENTITY name "text">`, no & % < in the value,
	1 KB per value, 64 KB per subset). An external DTD reference is never fetched. Parsing stops
	at the root element, so memory stays flat whatever the file's size.
	"""
	parser = xml.parsers.expat.ParserCreate()
	parser.SetParamEntityParsing(xml.parsers.expat.XML_PARAM_ENTITY_PARSING_NEVER)
	state = {"in_dtd": False, "dtd_start": 0, "root": None}

	def refuse(reason):
		raise FileFailed("SVG " + reason)

	def start_doctype(_name, _system_id, _public_id, _has_internal_subset):
		state["in_dtd"] = True
		state["dtd_start"] = parser.CurrentByteIndex

	def end_doctype():
		state["in_dtd"] = False
		if parser.CurrentByteIndex - state["dtd_start"] > SVG_MAX_INTERNAL_SUBSET:
			refuse("DTD too large")

	def entity(_name, is_parameter, value, _base, system_id, public_id, notation):
		if is_parameter or value is None or system_id or public_id or notation:
			refuse("DTD declarations other than plain entities")
		if any(c in value for c in "&%<") or len(value.encode("utf-8")) > SVG_MAX_ENTITY_VALUE:
			refuse("DTD declarations other than plain entities")

	def other_declaration(*_args):
		refuse("DTD declarations other than plain entities")

	def processing_instruction(_target, _data):
		if state["in_dtd"]:
			refuse("DTD declarations other than plain entities")

	def start_element(name, attributes):
		state["root"] = (name, attributes)
		raise _RootReached()

	parser.StartDoctypeDeclHandler = start_doctype
	parser.EndDoctypeDeclHandler = end_doctype
	parser.EntityDeclHandler = entity
	parser.UnparsedEntityDeclHandler = other_declaration
	parser.NotationDeclHandler = other_declaration
	parser.ElementDeclHandler = other_declaration
	parser.AttlistDeclHandler = other_declaration
	parser.ExternalEntityRefHandler = lambda *_args: refuse("external entity reference")
	parser.ProcessingInstructionHandler = processing_instruction
	parser.StartElementHandler = start_element

	try:
		with open(path, "rb") as f:
			while True:
				chunk = f.read(65536)
				parser.Parse(chunk, not chunk)
				if not chunk:
					break
	except _RootReached:
		pass
	except xml.parsers.expat.ExpatError as e:
		raise FileFailed("SVG not well-formed: %s" % e)

	if state["root"] is None:
		raise FileFailed("SVG has no root element")
	name, attributes = state["root"]
	if name.rsplit(":", 1)[-1] != "svg":
		raise FileFailed("SVG root element is <%s>, not <svg>" % name[:40])

	props = empty_properties()
	width, height = svg_length(attributes.get("width")), svg_length(attributes.get("height"))
	box = svg_view_box(attributes.get("viewBox"))
	if box is not None:
		if width is None and height is None:
			width, height = box
		elif width is None:
			width = height * box[0] / box[1]
		elif height is None:
			height = width * box[1] / box[0]
	props["width"], props["height"] = svg_pixels(width), svg_pixels(height)
	if props["width"] is None or props["height"] is None:
		props["width"] = props["height"] = None
	return props


def svg_length(value):
	"""CSS px, or None for absent, relative (%, em, ex) and unparseable lengths."""
	if value is None:
		return None
	match = SVG_LENGTH.match(value)
	if not match:
		return None
	number = float(match.group(1)) * SVG_UNITS[match.group(2)]
	return number if math.isfinite(number) and number > 0 else None


def svg_view_box(value):
	if value is None:
		return None
	parts = value.replace(",", " ").split()
	if len(parts) != 4:
		return None
	try:
		width, height = float(parts[2]), float(parts[3])
	except ValueError:
		return None
	return (width, height) if math.isfinite(width) and math.isfinite(height) and width > 0 and height > 0 else None


def svg_pixels(value):
	if value is None:
		return None
	value = int(round(value))
	return value if 1 <= value <= 10000000 else None


if __name__ == "__main__":
	sys.exit(main(sys.argv))
