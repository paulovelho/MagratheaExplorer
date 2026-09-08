<?php

namespace MagratheaExplorer\File;

use Magrathea2\Logger;

/**
 * Ported near-verbatim from MagratheaImages3's MetadataStripper.php (fully portable --
 * no Images3-specific dependency). Removes embedded metadata (EXIF/IPTC/XMP/comments)
 * without touching pixel data: every format is edited structurally (marker/chunk
 * removal) instead of being decoded and re-encoded.
 */
class MetadataStripper {

	private static $dropJpegMarkers = [0xE1, 0xED, 0xFE]; // APP1 (EXIF/XMP), APP13 (IPTC/Photoshop), COM
	private static $dropPngChunks = ["tEXt", "zTXt", "iTXt", "tIME", "eXIf"];
	private static $dropWebpChunks = ["EXIF", "XMP "];
	private static $editorNamespacePrefixes = ["inkscape", "sodipodi"];

	public static function Strip(string $path, string $extension): bool {
		try {
			switch (strtolower($extension)) {
				case "jpg":
				case "jpeg":
					return self::StripJpeg($path);
				case "png":
					return self::StripPng($path);
				case "webp":
					return self::StripWebp($path);
				case "gif":
					return self::StripGif($path);
				case "svg":
					return self::StripSvg($path);
				default:
					return true; // bmp: no metadata container to strip
			}
		} catch (\Throwable $ex) {
			Logger::Instance()->Log("metadata stripping failed for [".$path."]: ".$ex->getMessage());
			return false;
		}
	}

	public static function StripJpeg(string $path): bool {
		$data = file_get_contents($path);
		if ($data === false || substr($data, 0, 2) !== "\xFF\xD8") return false;

		$orientation = self::GetJpegOrientation($path);

		$length = strlen($data);
		$out = "\xFF\xD8";
		if ($orientation !== null) {
			$out .= self::BuildMinimalExifOrientationSegment($orientation);
		}

		$pos = 2;
		while ($pos < $length) {
			if (ord($data[$pos]) !== 0xFF) return false;

			$markerPos = $pos + 1;
			while ($markerPos < $length && ord($data[$markerPos]) === 0xFF) $markerPos++;
			if ($markerPos >= $length) return false;

			$marker = ord($data[$markerPos]);
			$segStart = $pos;
			$afterMarker = $markerPos + 1;

			if ($marker === 0xD9 || $marker === 0xDA) {
				// EOI, or SOS: from here on it's entropy-coded scan data (and,
				// for progressive JPEGs, further scans) -- copy verbatim to EOF.
				$out .= substr($data, $segStart);
				$pos = $length;
				break;
			}

			if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
				$out .= substr($data, $segStart, $afterMarker - $segStart);
				$pos = $afterMarker;
				continue;
			}

			if ($afterMarker + 2 > $length) return false;
			$segLen = (ord($data[$afterMarker]) << 8) | ord($data[$afterMarker + 1]);
			$segEnd = $afterMarker + $segLen;
			if ($segLen < 2 || $segEnd > $length) return false;

			if (!in_array($marker, self::$dropJpegMarkers, true)) {
				$out .= substr($data, $segStart, $segEnd - $segStart);
			}
			$pos = $segEnd;
		}

		return file_put_contents($path, $out) !== false;
	}

	private static function GetJpegOrientation(string $path): ?int {
		$exif = @exif_read_data($path);
		if ($exif === false || !isset($exif["Orientation"])) return null;
		$orientation = (int)$exif["Orientation"];
		if ($orientation <= 1) return null;
		return $orientation;
	}

	private static function BuildMinimalExifOrientationSegment(int $orientation): string {
		$tiff = "II" . pack("v", 42) . pack("V", 8);
		$tiff .= pack("v", 1); // one IFD0 entry
		$tiff .= pack("v", 0x0112) . pack("v", 3) . pack("V", 1) . pack("v", $orientation) . pack("v", 0);
		$tiff .= pack("V", 0); // no next IFD
		$appData = "Exif\0\0" . $tiff;
		return "\xFF\xE1" . pack("n", strlen($appData) + 2) . $appData;
	}

	public static function StripPng(string $path): bool {
		$data = file_get_contents($path);
		$signature = "\x89PNG\r\n\x1a\n";
		if ($data === false || substr($data, 0, 8) !== $signature) return false;

		$length = strlen($data);
		$out = $signature;
		$pos = 8;
		while ($pos + 8 <= $length) {
			$unpacked = unpack("N", substr($data, $pos, 4));
			$chunkLen = $unpacked[1];
			$type = substr($data, $pos + 4, 4);
			$chunkTotal = 8 + $chunkLen + 4; // length + type + data + crc
			if ($pos + $chunkTotal > $length) return false;

			if (!in_array($type, self::$dropPngChunks, true)) {
				$out .= substr($data, $pos, $chunkTotal);
			}
			$pos += $chunkTotal;
			if ($type === "IEND") break;
		}
		if ($pos < $length) return false;

		return file_put_contents($path, $out) !== false;
	}

	public static function StripWebp(string $path): bool {
		$data = file_get_contents($path);
		if ($data === false || substr($data, 0, 4) !== "RIFF" || substr($data, 8, 4) !== "WEBP") return false;

		$length = strlen($data);
		$pos = 12;
		$chunks = "";
		while ($pos + 8 <= $length) {
			$fourCc = substr($data, $pos, 4);
			$sizeUnpacked = unpack("V", substr($data, $pos + 4, 4));
			$chunkSize = $sizeUnpacked[1];
			$padding = ($chunkSize % 2 === 1) ? 1 : 0;
			$total = 8 + $chunkSize + $padding;
			if ($pos + $total > $length) return false;

			if (!in_array($fourCc, self::$dropWebpChunks, true)) {
				$chunks .= substr($data, $pos, $total);
			}
			$pos += $total;
		}
		if ($pos !== $length) return false;

		$riffSize = 4 + strlen($chunks); // "WEBP" + remaining chunks
		$final = "RIFF" . pack("V", $riffSize) . "WEBP" . $chunks;
		return file_put_contents($path, $final) !== false;
	}

	public static function StripGif(string $path): bool {
		$data = file_get_contents($path);
		$length = $data === false ? 0 : strlen($data);
		if ($length < 13 || (substr($data, 0, 6) !== "GIF87a" && substr($data, 0, 6) !== "GIF89a")) {
			return false;
		}

		$out = substr($data, 0, 6);
		$pos = 6;

		if ($pos + 7 > $length) return false;
		$screenPacked = ord($data[$pos + 4]);
		$out .= substr($data, $pos, 7);
		$pos += 7;

		if ($screenPacked & 0x80) {
			$gctSize = 3 * (2 ** (($screenPacked & 0x07) + 1));
			if ($pos + $gctSize > $length) return false;
			$out .= substr($data, $pos, $gctSize);
			$pos += $gctSize;
		}

		while ($pos < $length) {
			$byte = ord($data[$pos]);

			if ($byte === 0x3B) { // Trailer
				$out .= $data[$pos];
				$pos++;
				break;
			}

			if ($byte === 0x21) { // Extension introducer
				if ($pos + 2 > $length) return false;
				$label = ord($data[$pos + 1]);
				$blockStart = $pos;
				$cursor = self::SkipGifSubBlocks($data, $pos + 2, $length);
				if ($cursor === null) return false;

				if ($label !== 0xFE) { // keep everything except Comment Extension
					$out .= substr($data, $blockStart, $cursor - $blockStart);
				}
				$pos = $cursor;
				continue;
			}

			if ($byte === 0x2C) { // Image Descriptor
				if ($pos + 10 > $length) return false;
				$imgPacked = ord($data[$pos + 9]);
				$cursor = $pos + 10;
				if ($imgPacked & 0x80) {
					$lctSize = 3 * (2 ** (($imgPacked & 0x07) + 1));
					$cursor += $lctSize;
				}
				if ($cursor + 1 > $length) return false;
				$cursor++; // LZW minimum code size byte
				$cursor = self::SkipGifSubBlocks($data, $cursor, $length);
				if ($cursor === null) return false;

				$out .= substr($data, $pos, $cursor - $pos);
				$pos = $cursor;
				continue;
			}

			return false; // unexpected byte: bail out, keep original file untouched
		}

		return file_put_contents($path, $out) !== false;
	}

	private static function SkipGifSubBlocks(string $data, int $pos, int $length): ?int {
		while ($pos < $length) {
			$subLen = ord($data[$pos]);
			$pos++;
			if ($subLen === 0) return $pos;
			$pos += $subLen;
			if ($pos > $length) return null;
		}
		return null;
	}

	public static function StripSvg(string $path): bool {
		$content = file_get_contents($path);
		if ($content === false || trim($content) === "") return false;

		$doc = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $doc->loadXML($content, LIBXML_NONET | LIBXML_NOENT);
		libxml_use_internal_errors($previous);
		if (!$loaded) return false;

		self::RemoveSvgComments($doc);
		self::RemoveSvgElementsByTagName($doc, "metadata");
		self::RemoveSvgEditorNodes($doc);

		$result = $doc->saveXML();
		if ($result === false) return false;

		return file_put_contents($path, $result) !== false;
	}

	private static function RemoveSvgComments(\DOMDocument $doc): void {
		$xpath = new \DOMXPath($doc);
		foreach (iterator_to_array($xpath->query("//comment()")) as $comment) {
			$comment->parentNode->removeChild($comment);
		}
	}

	private static function RemoveSvgElementsByTagName(\DOMDocument $doc, string $tagName): void {
		foreach (iterator_to_array($doc->getElementsByTagName($tagName)) as $node) {
			$node->parentNode->removeChild($node);
		}
	}

	private static function RemoveSvgEditorNodes(\DOMDocument $doc): void {
		$xpath = new \DOMXPath($doc);
		foreach (iterator_to_array($xpath->query("//*")) as $node) {
			if (in_array($node->prefix, self::$editorNamespacePrefixes, true)) {
				if ($node->parentNode) $node->parentNode->removeChild($node);
			}
		}
		foreach (iterator_to_array($xpath->query("//@*")) as $attr) {
			if (in_array($attr->prefix, self::$editorNamespacePrefixes, true)) {
				$attr->ownerElement->removeAttributeNode($attr);
			}
		}
	}

}
