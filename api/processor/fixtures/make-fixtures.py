#!/usr/bin/env python3
"""
Regenerates the processor's contract fixtures and expected.json (dev only). The outputs are
committed: run-fixtures.php only reads them, so a server never needs this script.

    ~/explorer-venv/bin/python api/processor/fixtures/make-fixtures.py

Everything a property assertion depends on is written by hand, never left to an encoder:
PNG files are built chunk by chunk (pHYs, iCCP, the bomb's IHDR), JPEG segments are replaced
(JFIF density units, EXIF orientation, resolution and GPS). pyvips only encodes the pixels of
the JPEG, GIF and WebP files. Expectations are literals below, not values read back from the
engine: expected.json is generated from this file, so edit this file, not that one.

Every image is sized so each rendition comes out at exact integers (640x480 → 300x225), so
expected sizes don't depend on an engine's rounding.
"""

import json
import os
import struct
import sys
import zlib

HERE = os.path.dirname(os.path.abspath(__file__))

# The specs every full-mode run requests. `legacy` is Explorer's own format (png for a png
# original, jpg otherwise): the runner resolves it before the job reaches the engine.
RENDITIONS = [
	{"name": "thumb", "width": 300, "height": 300, "fit": "contain", "format": "webp", "quality": 85},
	{"name": "square", "width": 160, "height": 160, "fit": "cover", "format": "jpg", "quality": 85},
	{"name": "large", "width": 2048, "height": 2048, "fit": "contain", "format": "png"},
	{"name": "legacy", "width": 200, "height": 200, "fit": "cover", "format": "legacy", "quality": 85},
]

ALL_NULL = {k: None for k in (
	"width", "height", "orientation", "has_alpha_channel", "is_opaque", "dpi_x", "dpi_y",
	"bit_depth", "color_space", "has_icc_profile", "animated", "frame_count",
)}


def props(**values):
	base = {
		"orientation": None, "dpi_x": None, "dpi_y": None, "bit_depth": 8, "color_space": "srgb",
		"has_icc_profile": False, "animated": False, "frame_count": 1,
	}
	base.update(values)
	return {k: base.get(k) for k in ALL_NULL}


def renditions(width, height, source_mime, alpha):
	"""Expected output of RENDITIONS for an upright width x height source. alpha: True, False or None (either)."""
	def contain(box):
		scale = min(box / width, box / height, 1)
		return round(width * scale), round(height * scale)

	def cover(box):
		return min(box, width), min(box, height)

	legacy_png = source_mime == "image/png"
	out = {}
	for name, (w, h), mime, has_alpha in (
		("thumb", contain(300), "image/webp", alpha),
		("square", cover(160), "image/jpeg", False),
		("large", contain(2048), "image/png", alpha),
		("legacy", cover(200), "image/png" if legacy_png else "image/jpeg", alpha if legacy_png else False),
	):
		out[name] = {"width": w, "height": h, "mime_type": mime, "has_alpha": has_alpha}
	return out


# ------------------------------------------------------------------------------------- PNG

def png_chunk(kind, data):
	return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data) & 0xFFFFFFFF)


def png(width, height, color_type, bit_depth, row, extra_chunks=(), idat=None):
	"""row(y) → the raw row bytes (no filter byte). extra_chunks go between IHDR and IDAT. idat: compressed data to use as is."""
	if idat is None:
		idat = zlib.compress(b"".join(b"\x00" + row(y) for y in range(height)), 9)
	data = b"\x89PNG\r\n\x1a\n"
	data += png_chunk(b"IHDR", struct.pack(">IIBBBBB", width, height, bit_depth, color_type, 0, 0, 0))
	for chunk in extra_chunks:
		data += chunk
	data += png_chunk(b"IDAT", idat)
	data += png_chunk(b"IEND", b"")
	return data


def phys(dpi=None, ppm=None, unit=1):
	ppm = ppm if ppm is not None else round(dpi / 0.0254)
	return png_chunk(b"pHYs", struct.pack(">IIB", ppm, ppm, unit))


def iccp(profile, name=b"linear sRGB primaries"):
	return png_chunk(b"iCCP", name + b"\x00\x00" + zlib.compress(profile, 9))


def rgba8(r, g, b, a):
	return bytes((r, g, b, a))


def rgba16(r, g, b, a):
	return struct.pack(">HHHH", r, g, b, a)


def linear_rgb_profile():
	"""
	An ICC v2 display profile with sRGB's primaries and a gamma 1.0 (linear) curve. Linear
	mid-gray 128 is ~188 in sRGB, so a rendition that went through the conversion is easy to tell
	from one that didn't (it would stay 128).
	"""
	def s15(v):
		return struct.pack(">i", int(round(v * 65536)))

	def xyz(x, y, z):
		return b"XYZ \x00\x00\x00\x00" + s15(x) + s15(y) + s15(z)

	text = b"Linear RGB (sRGB primaries), MagratheaExplorer test fixture"
	desc = b"desc\x00\x00\x00\x00" + struct.pack(">I", len(text) + 1) + text + b"\x00"
	desc += struct.pack(">II", 0, 0) + struct.pack(">HB", 0, 0) + b"\x00" * 67
	cprt = b"text\x00\x00\x00\x00" + b"No copyright, use freely\x00"
	curve = b"curv\x00\x00\x00\x00" + struct.pack(">IH", 1, 0x0100) + b"\x00\x00"
	tags = [
		(b"desc", desc), (b"cprt", cprt), (b"wtpt", xyz(0.9642, 1.0, 0.8249)),
		(b"rXYZ", xyz(0.4360747, 0.2225045, 0.0139322)),
		(b"gXYZ", xyz(0.3850649, 0.7168786, 0.0971045)),
		(b"bXYZ", xyz(0.1430804, 0.0606169, 0.7141733)),
		(b"rTRC", curve), (b"gTRC", curve), (b"bTRC", curve),
	]
	offset = 128 + 4 + 12 * len(tags)
	table, blob = b"", b""
	for sig, data in tags:
		while len(data) % 4:
			data += b"\x00"
		table += sig + struct.pack(">II", offset + len(blob), len(data))
		blob += data
	body = struct.pack(">I", len(tags)) + table + blob
	size = 128 + len(body)
	header = struct.pack(">I", size) + b"lcms" + struct.pack(">I", 0x02100000) + b"mntrRGB XYZ "
	header += struct.pack(">6H", 2026, 1, 1, 0, 0, 0) + b"acsp" + b"\x00" * 4 + struct.pack(">I", 0)
	header += b"\x00" * 8 + b"\x00" * 8 + struct.pack(">I", 0)
	header += s15(0.9642) + s15(1.0) + s15(0.8249) + b"\x00" * 4 + b"\x00" * 16 + b"\x00" * 28
	assert len(header) == 128
	return header + body


# ------------------------------------------------------------------------------------ JPEG

def gif_without_transparency(data):
	"""Clears the transparent-color flag of every Graphic Control Extension. cgif sets it on every frame."""
	data = bytearray(data)
	i = 13 + (3 * (2 << (data[10] & 7)) if data[10] & 0x80 else 0)

	def skip_sub_blocks(i):
		while data[i]:
			i += data[i] + 1
		return i + 1

	while data[i] != 0x3B:
		if data[i] == 0x21:
			if data[i + 1] == 0xF9:
				data[i + 3] &= 0xFE
			i = skip_sub_blocks(i + 2)
		elif data[i] == 0x2C:
			packed = data[i + 9]
			i += 10 + (3 * (2 << (packed & 7)) if packed & 0x80 else 0)
			i = skip_sub_blocks(i + 1)
		else:
			raise ValueError("unexpected GIF block 0x%02x" % data[i])
	return bytes(data)


def jfif(units, x, y):
	data = b"JFIF\x00\x01\x02" + struct.pack(">BHHBB", units, x, y, 0, 0)
	return b"\xff\xe0" + struct.pack(">H", len(data) + 2) + data


def exif(entries):
	"""
	A little-endian TIFF/EXIF block. entries: IFD0 as {tag: (type, value)}; a "gps" key holds
	the GPS IFD the same way. Types: 3 SHORT, 4 LONG, 5 RATIONAL (value: list of (num, den)),
	2 ASCII, 1 BYTE.
	"""
	gps = entries.pop("gps", None)
	ifds = [entries] + ([gps] if gps else [])
	if gps:
		entries[0x8825] = (4, 0)  # patched below

	def ifd_size(ifd):
		return 2 + 12 * len(ifd) + 4

	def data_size(ifd):
		total = 0
		for kind, value in ifd.values():
			raw = encode(kind, value)
			if len(raw) > 4:
				total += len(raw) + (len(raw) % 2)
		return total

	def encode(kind, value):
		if kind == 3:
			return struct.pack("<H", value)
		if kind == 4:
			return struct.pack("<I", value)
		if kind == 5:
			return b"".join(struct.pack("<II", n, d) for n, d in value)
		return bytes(value)

	offsets, cursor = [], 8
	for ifd in ifds:
		offsets.append(cursor)
		cursor += ifd_size(ifd) + data_size(ifd)
	if gps:
		entries[0x8825] = (4, offsets[1])

	out = b"II*\x00" + struct.pack("<I", offsets[0])
	for ifd, start in zip(ifds, offsets):
		data_at = start + ifd_size(ifd)
		table, data = struct.pack("<H", len(ifd)), b""
		for tag in sorted(ifd):
			kind, value = ifd[tag]
			raw = encode(kind, value)
			count = len(value) if kind in (5, 2, 1) else 1
			if len(raw) <= 4:
				table += struct.pack("<HHI", tag, kind, count) + raw.ljust(4, b"\x00")
			else:
				table += struct.pack("<HHII", tag, kind, count, data_at + len(data))
				data += raw + (b"\x00" if len(raw) % 2 else b"")
		out += table + struct.pack("<I", 0) + data
	return b"\xff\xe1" + struct.pack(">H", len(out) + 8) + b"Exif\x00\x00" + out


def jpeg_with(encoded, segments):
	"""Drops the encoder's APP0–APP13 and COM segments (keeps APP14 Adobe, which CMYK needs) and puts `segments` first."""
	assert encoded[:2] == b"\xff\xd8"
	out, i = [b"\xff\xd8"] + list(segments), 2
	while i < len(encoded):
		marker = encoded[i + 1]
		if marker == 0xDA:
			out.append(encoded[i:])
			break
		size = struct.unpack(">H", encoded[i + 2:i + 4])[0]
		if not (0xE0 <= marker <= 0xED or marker == 0xFE):
			out.append(encoded[i:i + 2 + size])
		i += 2 + size
	return b"".join(out)


# ------------------------------------------------------------------------------- fixtures

def build(pyvips):
	fixtures = {}

	def add(name, data, mime, about, **expect):
		with open(os.path.join(HERE, name), "wb") as f:
			f.write(data)
		entry = {"mime_type": mime, "about": about}
		entry.update(expect)
		fixtures[name] = entry

	def vips_rgb(width, height, color):
		return (pyvips.Image.black(width, height, bands=3) + list(color)).cast("uchar").copy(interpretation="srgb")

	def halves(width, height, left, right, vertical=True):
		a = vips_rgb(width // 2 if vertical else width, height if vertical else height // 2, left)
		b = vips_rgb(width // 2 if vertical else width, height if vertical else height // 2, right)
		return a.join(b, "horizontal" if vertical else "vertical")

	# -- PNG: alpha and opacity
	add("rgba-transparent.png",
		png(640, 480, 6, 8, lambda y: rgba8(0, 0, 0, 0) * 320 + rgba8(30, 60, 200, 255) * 320),
		"image/png", "RGBA, left half fully transparent: a jpg rendition flattens it onto white",
		properties=props(width=640, height=480, has_alpha_channel=True, is_opaque=False),
		renditions=renditions(640, 480, "image/png", True),
		pixels={"square": [{"at": [0.1, 0.5], "rgb": [255, 255, 255], "tolerance": 6}],
				"thumb": [{"at": [0.9, 0.5], "rgb": [30, 60, 200], "tolerance": 12}]})

	add("rgba-opaque.png",
		png(640, 480, 6, 8, lambda y: rgba8(200, 40, 40, 255) * 640),
		"image/png", "RGBA with every pixel opaque: the classic print-on-demand trap",
		properties=props(width=640, height=480, has_alpha_channel=True, is_opaque=True),
		renditions=renditions(640, 480, "image/png", None))

	def one_pixel(y):
		row = bytearray(rgba8(40, 160, 40, 255) * 640)
		if y == 233:
			row[401 * 4 + 3] = 254
		return bytes(row)
	add("rgba-one-pixel.png", png(640, 480, 6, 8, one_pixel),
		"image/png", "RGBA, opaque except one pixel at alpha 254: is_opaque must come from every pixel, not a downscaled copy",
		properties=props(width=640, height=480, has_alpha_channel=True, is_opaque=False),
		renditions=renditions(640, 480, "image/png", None))

	add("rgb.png",
		png(640, 480, 2, 8, lambda y: bytes((90, 90, 200)) * 640, [phys(ppm=2835)]),
		"image/png", "RGB, pHYs 2835 px/m: a declared 72 DPI is reported as 72",
		properties=props(width=640, height=480, has_alpha_channel=False, is_opaque=True, dpi_x=72, dpi_y=72),
		renditions=renditions(640, 480, "image/png", False))

	add("no-phys.png",
		png(640, 480, 2, 8, lambda y: bytes((200, 200, 90)) * 640),
		"image/png", "RGB without pHYs: DPI null, never a default",
		properties=props(width=640, height=480, has_alpha_channel=False, is_opaque=True),
		renditions=renditions(640, 480, "image/png", False))

	def rgba16_row(y):
		gray = rgba16(32896, 32896, 32896, 65535)
		if y < 32:
			return rgba16(0, 0, 0, 0) * 32 + gray * 608
		return gray * 640
	add("rgba16-icc-phys300.png",
		png(640, 480, 6, 16, rgba16_row, [iccp(linear_rgb_profile()), phys(dpi=300)]),
		"image/png", "16-bit RGBA, linear-gamma ICC, pHYs 11811 px/m (300 DPI), a transparent corner. "
		"Linear gray 128 must come out ~188 in the sRGB renditions. Also the verbatim acceptance file (plan 3.7)",
		properties=props(width=640, height=480, has_alpha_channel=True, is_opaque=False, dpi_x=300, dpi_y=300,
						 bit_depth=16, has_icc_profile=True),
		renditions=renditions(640, 480, "image/png", True),
		pixels={"thumb": [{"at": [0.5, 0.5], "rgb": [188, 188, 188], "tolerance": 8}],
				"square": [{"at": [0.5, 0.5], "rgb": [188, 188, 188], "tolerance": 8}]})

	# -- PNG: refused or broken
	# Two rows of image data: the rest of the claimed 10 GP never existed.
	bomb_idat = zlib.compress((b"\x00" + b"\x00" * 100000 * 4) * 2, 9)
	add("bomb-header.png", png(100000, 100000, 6, 8, None, idat=bomb_idat),
		"image/png", "IHDR claims 100000x100000 (10 gigapixels) in a few KB: refused from the header, never decoded",
		properties=props(width=100000, height=100000, has_alpha_channel=True, is_opaque=None),
		rendition_errors="all")

	noisy = png(640, 480, 2, 8, lambda y: bytes((x * 7 + y * 13) % 251 for x in range(640 * 3)))
	add("truncated.png", noisy[:int(len(noisy) * 0.6)],
		"image/png", "a valid header, then the image data cut off at 60%: the header reads, a decode fails cleanly",
		properties=props(width=640, height=480, has_alpha_channel=False, is_opaque=True),
		full="fail")

	add("mismatch.png", png(64, 48, 2, 8, lambda y: bytes((1, 2, 3)) * 64),
		"image/jpeg", "a PNG declared as image/jpeg: the loader follows the declared type, so it fails",
		header="fail", full="fail")

	# -- JPEG
	flat = vips_rgb(640, 480, (120, 120, 120)).jpegsave_buffer(Q=90, keep="none")

	add("jfif-units0.jpg", jpeg_with(flat, [jfif(0, 72, 72)]),
		"image/jpeg", "JFIF density units 0 (aspect ratio only) with 72:72: the 72 DPI trap, DPI null",
		properties=props(width=640, height=480, has_alpha_channel=False, is_opaque=True),
		renditions=renditions(640, 480, "image/jpeg", False))

	add("jfif-dpcm.jpg", jpeg_with(flat, [jfif(2, 118, 118)]),
		"image/jpeg", "JFIF 118 dots per cm: 299.72 DPI, reported as 300",
		properties=props(width=640, height=480, has_alpha_channel=False, is_opaque=True, dpi_x=300, dpi_y=300),
		renditions=renditions(640, 480, "image/jpeg", False))

	# Stored landscape: top half red, bottom half blue. Orientation 6 = turn 90° clockwise to
	# display, so the upright image is portrait with blue on the left and red on the right.
	stored = halves(640, 480, (220, 30, 30), (30, 30, 220), vertical=False).jpegsave_buffer(Q=90, keep="none")
	add("orient6.jpg", jpeg_with(stored, [
		jfif(1, 72, 72),
		exif({
			0x0112: (3, 6),
			0x011A: (5, [(240, 1)]), 0x011B: (5, [(240, 1)]), 0x0128: (3, 2),
			"gps": {
				0x0000: (1, [2, 3, 0, 0]), 0x0001: (2, b"N\x00"),
				0x0002: (5, [(48, 1), (51, 1), (2400, 100)]),
				0x0003: (2, b"E\x00"), 0x0004: (5, [(2, 1), (17, 1), (4000, 100)]),
			},
		}),
	]), "image/jpeg", "EXIF orientation 6 + GPS + 240 DPI, JFIF 72 DPI: renditions turned upright (W/H swapped), "
		"no EXIF in them, and EXIF's resolution wins over JFIF's",
		properties=props(width=640, height=480, orientation=6, has_alpha_channel=False, is_opaque=True,
						 dpi_x=240, dpi_y=240),
		renditions=renditions(480, 640, "image/jpeg", False),
		pixels={"thumb": [{"at": [0.25, 0.5], "rgb": [30, 30, 220], "tolerance": 30},
						  {"at": [0.75, 0.5], "rgb": [220, 30, 30], "tolerance": 30}]})

	cyan = vips_rgb(640, 480, (0, 174, 239)).icc_transform("cmyk", input_profile="srgb")
	add("cmyk.jpg", jpeg_with(cyan.jpegsave_buffer(Q=90, keep="none"), []),
		"image/jpeg", "CMYK without an ICC profile: renditions converted to 3-band sRGB (still cyan), not saved as 4 bands",
		properties=props(width=640, height=480, has_alpha_channel=False, is_opaque=True, color_space="cmyk"),
		renditions=renditions(640, 480, "image/jpeg", False),
		pixels={"thumb": [{"at": [0.5, 0.5], "rgb": [0, 174, 239], "tolerance": 30}]})

	# -- GIF
	frames = [vips_rgb(320, 240, c) for c in ((220, 20, 20), (20, 220, 20), (20, 20, 220))]
	anim = pyvips.Image.arrayjoin(frames, across=1).copy(interpretation="srgb")
	anim.set_type(pyvips.GValue.gint_type, "page-height", 240)
	anim.set_type(pyvips.GValue.array_int_type, "delay", [200, 200, 200])
	anim.set_type(pyvips.GValue.gint_type, "loop", 0)
	add("animated.gif", gif_without_transparency(anim.gifsave_buffer(keep="none")),
		"image/gif", "3 frames (red, green, blue), no frame declares a transparent index: renditions show frame 0 (red)",
		properties=props(width=320, height=240, has_alpha_channel=False, is_opaque=True, animated=True, frame_count=3),
		renditions=renditions(320, 240, "image/gif", False),
		pixels={"thumb": [{"at": [0.5, 0.5], "rgb": [220, 20, 20], "tolerance": 20}]})

	half = halves(320, 240, (0, 0, 0), (240, 200, 20))
	alpha = halves(320, 240, (0,), (255,)).extract_band(0)
	transparent_gif = half.bandjoin(alpha).copy(interpretation="srgb")
	add("transparent.gif", transparent_gif.gifsave_buffer(keep="none"),
		"image/gif", "one frame, a transparent color index on the left half",
		properties=props(width=320, height=240, has_alpha_channel=True, is_opaque=False),
		renditions=renditions(320, 240, "image/gif", True))

	# -- WebP
	webp = halves(640, 480, (0, 0, 0), (20, 160, 160)).bandjoin(halves(640, 480, (0,), (255,)).extract_band(0))
	add("alpha.webp", webp.copy(interpretation="srgb").webpsave_buffer(lossless=True, keep="none"),
		"image/webp", "lossless RGBA, left half transparent, no EXIF: DPI null",
		properties=props(width=640, height=480, has_alpha_channel=True, is_opaque=False),
		renditions=renditions(640, 480, "image/webp", True))

	# -- no handler
	bmp_pixels = b"".join(bytes((255, 0, 0)) * 4 for _ in range(4))
	bmp = b"BM" + struct.pack("<IHHI", 54 + len(bmp_pixels), 0, 0, 54)
	bmp += struct.pack("<IiiHHIIiiII", 40, 4, 4, 1, 24, 0, len(bmp_pixels), 2835, 2835, 0, 0) + bmp_pixels
	add("plain.bmp", bmp, "image/bmp", "no handler for BMP in this engine: handled=false, not an error",
		handled=False)

	# -- SVG: properties only, never rasterized
	ns = 'xmlns="http://www.w3.org/2000/svg"'
	add("dims.svg", ('<svg %s width="210mm" height="297mm" viewBox="0 0 210 297"><rect width="210" height="297"/></svg>' % ns).encode(),
		"image/svg+xml", "width/height in mm: CSS px at 96 per inch (A4 = 794x1123)",
		properties=dict(ALL_NULL, width=794, height=1123), renders=False)

	add("viewbox.svg", ('<?xml version="1.0"?>\n<svg %s viewBox="0 0 640 360"><circle r="10"/></svg>' % ns).encode(),
		"image/svg+xml", "no width/height: the viewBox's size",
		properties=dict(ALL_NULL, width=640, height=360), renders=False)

	add("relative.svg", ('<svg %s width="100%%" height="100%%"><rect width="1" height="1"/></svg>' % ns).encode(),
		"image/svg+xml", "relative width/height and no viewBox: size unknown (null), not an error",
		properties=dict(ALL_NULL), renders=False)

	add("illustrator.svg", (
		'<?xml version="1.0" encoding="utf-8"?>\n'
		'<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd" [\n'
		'\t<!ENTITY ns_svg "http://www.w3.org/2000/svg">\n'
		'\t<!ENTITY ns_xlink "http://www.w3.org/1999/xlink">\n'
		'\t<!-- a comment -->\n'
		']>\n'
		'<svg version="1.1" xmlns="&ns_svg;" xmlns:xlink="&ns_xlink;" width="120px" height="80px"><g/></svg>\n'
	).encode(), "image/svg+xml", "an old Illustrator export: external DTD reference (never fetched) and plain entities pass",
		properties=dict(ALL_NULL, width=120, height=80), renders=False)

	add("xxe.svg", (
		'<?xml version="1.0"?>\n<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/hostname">]>\n'
		'<svg %s width="&x;" height="10"/>\n' % ns
	).encode(), "image/svg+xml", "an external entity: refused, never resolved",
		header="fail", full="fail")

	add("entity-nested.svg", (
		'<?xml version="1.0"?>\n<!DOCTYPE svg [<!ENTITY a "aaaa"><!ENTITY b "&a;&a;&a;&a;">]>\n'
		'<svg %s width="10" height="10"><text>&b;</text></svg>\n' % ns
	).encode(), "image/svg+xml", "an entity referencing another (billion laughs): refused",
		header="fail", full="fail")

	return fixtures


def main():
	import pyvips
	fixtures = build(pyvips)
	expected = {
		"_comment": "Generated by make-fixtures.py: edit that file and rerun it, not this one.",
		"generated_with": "pyvips %s / libvips %d.%d.%d" % (pyvips.__version__, pyvips.version(0), pyvips.version(1), pyvips.version(2)),
		"renditions": RENDITIONS,
		"fixtures": fixtures,
	}
	with open(os.path.join(HERE, "expected.json"), "w") as f:
		json.dump(expected, f, indent="\t")
		f.write("\n")
	print("%d fixtures written to %s" % (len(fixtures), HERE))
	return 0


if __name__ == "__main__":
	sys.exit(main())
