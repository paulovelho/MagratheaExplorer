<?php

namespace MagratheaExplorer\File;

use GdImage;
use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\ExplorerConfig;

/**
 * Upload-time GD work on non-verbatim images: a high-quality re-encode and the optional webp
 * conversion. Renditions (thumbnails, previews) aren't made here since 1.5.0: the processor
 * makes them (see Processing/FileProcessing), outside PHP's memory.
 *
 * Everything here holds the whole image in PHP memory (GD decodes at ~4 bytes per pixel,
 * MetadataStripper copies the file ~3 times), so it's bounded by magrathea.conf's
 * `convert_max_bytes`/`convert_max_pixels`: see AssertConvertible().
 */
class ImageProcessor {

	const DEFAULT_CONVERT_MAX_BYTES = 25 * 1024 * 1024;
	const DEFAULT_CONVERT_MAX_PIXELS = 40_000_000;
	/**
	 * A JPEG with EXIF orientation 5-8 is turned 90° onto a second full-size canvas before the
	 * re-encode, and the freed one stays fragmented under the webp buffer. Measured at 40 MP
	 * (PHP 8.4, memory_limit enforced): ~315 MB unrotated, ~470 MB turned. It counts 1.5x its
	 * pixels, so every conversion peaks about the same at a given convert_max_pixels.
	 */
	const TURNED_JPEG_PIXEL_WEIGHT = 1.5;

	public static function ConvertMaxBytes(): int {
		return ExplorerConfig::GetSize("convert_max_bytes", self::DEFAULT_CONVERT_MAX_BYTES);
	}

	public static function ConvertMaxPixels(): int {
		return ExplorerConfig::GetPositiveInt("convert_max_pixels", self::DEFAULT_CONVERT_MAX_PIXELS);
	}

	/**
	 * Non-verbatim images only, before MetadataStripper: refuses (4131, HTTP 413) an image too
	 * big to strip and re-encode in PHP memory, before anything is stored or counted. Pixels
	 * come from the header (getimagesize() decodes nothing); an SVG or a header getimagesize()
	 * can't read is checked on bytes only. A JPEG that has to be turned 90° counts
	 * TURNED_JPEG_PIXEL_WEIGHT times its pixels. Verbatim uploads skip this: PHP never decodes
	 * them.
	 */
	public static function AssertConvertible(string $path, string $extension): void {
		$bytes = (int)filesize($path);
		$maxBytes = self::ConvertMaxBytes();
		if($bytes > $maxBytes) {
			ErrorCodes::Instance()->ThrowException(4131, null, self::FormatNumber($bytes / 1048576)." MB, the limit is ".self::FormatNumber($maxBytes / 1048576)." MB");
		}
		[$width, $height] = self::GetDimensions($path, $extension);
		if($width === null || $height === null) return;
		$pixels = $width * $height;
		$weighted = $pixels;
		$ext = strtolower($extension);
		if(($ext === "jpg" || $ext === "jpeg") && self::ReadJpegOrientation($path) >= 5) {
			$weighted = (int)ceil($pixels * self::TURNED_JPEG_PIXEL_WEIGHT);
		}
		$maxPixels = self::ConvertMaxPixels();
		if($weighted > $maxPixels) {
			$detail = $width."x".$height." px is ".self::FormatNumber($pixels / 1e6)." MP";
			if($weighted !== $pixels) $detail .= ", ".self::FormatNumber($weighted / 1e6)." MP counting the 90° turn it needs";
			ErrorCodes::Instance()->ThrowException(4131, null, $detail.", the limit is ".self::FormatNumber($maxPixels / 1e6)." MP");
		}
	}

	private static function FormatNumber(float $value): string {
		return rtrim(rtrim(number_format($value, 2, ".", ""), "0"), ".");
	}

	public static function GetDimensions(string $path, string $extension): array {
		if(strtolower($extension) === "svg") return [null, null];
		$size = @getimagesize($path);
		if($size === false) return [null, null];
		return [$size[0], $size[1]];
	}

	/** More than one Graphic Control Extension block means more than one frame. */
	public static function IsAnimatedGif(string $path): bool {
		$data = @file_get_contents($path);
		if($data === false) return false;
		return substr_count($data, "\x00\x21\xF9\x04") > 1;
	}

	public static function IsGDWorking(): bool {
		return function_exists("gd_info");
	}

	public static function LoadGD(string $path, string $extension): GdImage|false {
		if(!self::IsGDWorking()) {
			ErrorCodes::Instance()->ThrowException(5002, null, "GD library not installed");
		}
		switch(strtolower($extension)) {
			case "jpg":
			case "jpeg":
				$gd = @imagecreatefromjpeg($path);
				return $gd === false ? false : self::ApplyExifOrientation($gd, self::ReadJpegOrientation($path));
			case "png":
				return @imagecreatefrompng($path);
			case "webp":
				return @imagecreatefromwebp($path);
			case "gif":
				return @imagecreatefromgif($path);
			case "bmp":
				return @imagecreatefrombmp($path);
			default:
				return false;
		}
	}

	/** EXIF orientation of a JPEG, 1-8. 1 when absent, invalid, unreadable, or ext-exif is missing. */
	public static function ReadJpegOrientation(string $path): int {
		if(!function_exists("exif_read_data")) return 1;
		$exif = @exif_read_data($path);
		$orientation = is_array($exif) ? (int)($exif["Orientation"] ?? 1) : 1;
		return ($orientation >= 1 && $orientation <= 8) ? $orientation : 1;
	}

	/**
	 * Turns stored pixels into display pixels. GD writes no EXIF, so without this every image GD
	 * re-encodes from a rotated JPEG came out sideways or mirrored. Orientations 5-8 rotate onto
	 * a second full-size canvas: twice the pixel memory for a moment, still bounded by
	 * convert_max_pixels.
	 * false when GD can't rotate.
	 */
	public static function ApplyExifOrientation(GdImage $gd, int $orientation): GdImage|false {
		// imagerotate() turns counter-clockwise: 270 = 90 clockwise
		switch($orientation) {
			case 2: imageflip($gd, IMG_FLIP_HORIZONTAL); return $gd;
			case 3: imageflip($gd, IMG_FLIP_BOTH); return $gd;      // 180, in place
			case 4: imageflip($gd, IMG_FLIP_VERTICAL); return $gd;
			case 5: imageflip($gd, IMG_FLIP_HORIZONTAL); return imagerotate($gd, 90, 0);  // transpose
			case 6: return imagerotate($gd, 270, 0);
			case 7: imageflip($gd, IMG_FLIP_HORIZONTAL); return imagerotate($gd, 270, 0); // transverse
			case 8: return imagerotate($gd, 90, 0);
			default: return $gd;
		}
	}

	public static function SaveGD(GdImage $gd, string $path, string $extension, int $quality = 90): bool {
		switch(strtolower($extension)) {
			case "png":
				return imagepng($gd, $path, (int)round(9 - ($quality / 100 * 9)));
			case "webp":
				return imagewebp($gd, $path, $quality);
			case "gif":
				return imagegif($gd, $path);
			case "bmp":
				return imagebmp($gd, $path);
			case "jpg":
			case "jpeg":
			default:
				return imagejpeg($gd, $path, $quality);
		}
	}

	private static function PreserveAlpha(GdImage $gd, string $extension): void {
		if(strtolower($extension) !== "png") return;
		imagealphablending($gd, false);
		imagesavealpha($gd, true);
	}

	/**
	 * Re-encodes at high quality and converts to webp unless opted out (or the format
	 * can't safely be converted: SVG is already vector, animated GIF would lose its
	 * animation under bare GD). `noConvert` only keeps the format: the pixels still go through
	 * GD (8 bits per channel, no ICC/DPI/EXIF written, JPEG recompressed at q90). Metadata
	 * stripping happens separately, even when no re-encode applies here. Verbatim uploads
	 * skip both.
	 *
	 * @return array{path: string, extension: string} `path` equals $localTmpFile
	 *   unchanged when no re-encode applies (SVG, animated GIF, unreadable format).
	 */
	public static function ReencodeAndConvert(string $localTmpFile, string $extension, bool $noConvert): array {
		$ext = strtolower($extension);
		if($ext === "svg") return ["path" => $localTmpFile, "extension" => $ext];
		if($ext === "gif" && self::IsAnimatedGif($localTmpFile)) return ["path" => $localTmpFile, "extension" => $ext];

		$gd = self::LoadGD($localTmpFile, $ext);
		if($gd === false) return ["path" => $localTmpFile, "extension" => $ext];

		$finalExt = $noConvert ? $ext : "webp";
		self::PreserveAlpha($gd, $finalExt);
		$outPath = $localTmpFile.".reencoded";
		if(!self::SaveGD($gd, $outPath, $finalExt, 90)) {
			imagedestroy($gd);
			ErrorCodes::Instance()->ThrowException(5002, null, "re-encode failed");
		}
		imagedestroy($gd);
		return ["path" => $outPath, "extension" => $finalExt];
	}

}
