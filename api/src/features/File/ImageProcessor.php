<?php

namespace MagratheaExplorer\File;

use GdImage;
use MagratheaExplorer\ErrorCodes;

/**
 * Upload-time image processing: high-quality GD re-encode, optional webp conversion,
 * and a fixed-size square crop-to-fill thumbnail. Simpler than Images3's ImageResizer
 * (no arbitrary on-demand WxH resize, no generated-file disk cache, no placeholders) --
 * this project only ever produces two derivatives of an uploaded image, once, at upload
 * time. The crop-to-fill math itself is Images3's ResampleCalculator, ported near-verbatim.
 */
class ImageProcessor {

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
				return @imagecreatefromjpeg($path);
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
	 * animation under bare GD). Metadata stripping happens separately and always, even
	 * when no re-encode applies here.
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

	/**
	 * Square crop-to-fill thumbnail, generated once at upload time (never on-demand).
	 * Returns null when the source can't be rasterized by bare GD (SVG).
	 * @return array{path: string, extension: string}|null
	 */
	public static function BuildThumbnail(string $localTmpFile, string $extension, int $size): ?array {
		$ext = strtolower($extension);
		if($ext === "svg") return null;

		$gd = self::LoadGD($localTmpFile, $ext);
		if($gd === false) return null;

		$srcW = imagesx($gd);
		$srcH = imagesy($gd);
		$calc = new ResampleCalculator($srcW, $srcH, $size, $size);
		$data = $calc->Calculate();

		$working = $gd;
		if($data["resize"]) {
			$resized = imagecreatetruecolor($data["resize_w"], $data["resize_h"]);
			self::PreserveAlpha($resized, $ext);
			imagecopyresampled($resized, $gd, 0, 0, 0, 0, $data["resize_w"], $data["resize_h"], $srcW, $srcH);
			$working = $resized;
		}

		$thumbExt = ($ext === "png") ? "png" : "jpg";
		$thumb = imagecreatetruecolor($size, $size);
		self::PreserveAlpha($thumb, $thumbExt);
		imagecopyresampled(
			$thumb, $working,
			$data["dst_x"], $data["dst_y"],
			$data["src_x"], $data["src_y"],
			$data["dst_width"], $data["dst_height"],
			$data["src_width"], $data["src_height"]
		);

		$thumbPath = $localTmpFile.".thumb";
		$saved = self::SaveGD($thumb, $thumbPath, $thumbExt, 85);

		imagedestroy($thumb);
		if($working !== $gd) imagedestroy($working);
		imagedestroy($gd);

		if(!$saved) return null;
		return ["path" => $thumbPath, "extension" => $thumbExt];
	}

}
