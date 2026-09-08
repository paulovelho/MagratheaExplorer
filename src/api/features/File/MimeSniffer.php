<?php

namespace MagratheaExplorer\File;

use MagratheaExplorer\ErrorCodes;

/**
 * Detects a file's real type via finfo -- never the client-supplied extension or
 * Content-Type -- and maps it to one of the five `files.file_type` buckets. A small
 * denylist blocks obviously dangerous types even under file_type='other', since that
 * bucket otherwise accepts anything.
 */
class MimeSniffer {

	private static array $imageMimes = [
		"image/jpeg" => "jpg",
		"image/png" => "png",
		"image/webp" => "webp",
		"image/gif" => "gif",
		"image/bmp" => "bmp",
		"image/x-bmp" => "bmp",
		"image/svg+xml" => "svg",
	];

	private static array $audioMimes = [
		"audio/mpeg" => "mp3",
		"audio/mp4" => "m4a",
		"audio/x-m4a" => "m4a",
		"audio/wav" => "wav",
		"audio/x-wav" => "wav",
		"audio/ogg" => "ogg",
		"audio/flac" => "flac",
		"audio/aac" => "aac",
	];

	private static array $videoMimes = [
		"video/mp4" => "mp4",
		"video/quicktime" => "mov",
		"video/webm" => "webm",
		"video/x-msvideo" => "avi",
		"video/x-matroska" => "mkv",
	];

	private static array $documentMimes = [
		"application/pdf" => "pdf",
		"application/msword" => "doc",
		"application/vnd.openxmlformats-officedocument.wordprocessingml.document" => "docx",
		"application/vnd.ms-excel" => "xls",
		"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" => "xlsx",
		"text/plain" => "txt",
		"text/csv" => "csv",
	];

	/** Never storable, even as file_type='other' -- executable/script content types. */
	private static array $deniedMimes = [
		"application/x-msdownload",
		"application/x-executable",
		"application/x-sh",
		"application/x-httpd-php",
		"text/x-php",
		"application/java-archive",
	];

	public static function Detect(string $localTmpFile): array {
		$finfo = new \finfo(FILEINFO_MIME_TYPE);
		$mime = $finfo->file($localTmpFile);
		if($mime === false) {
			ErrorCodes::Instance()->ThrowException(4151, null, "could not detect file type");
		}

		if(in_array($mime, self::$deniedMimes, true)) {
			ErrorCodes::Instance()->ThrowException(4151, null, $mime);
		}

		foreach([
			["image", self::$imageMimes],
			["audio", self::$audioMimes],
			["video", self::$videoMimes],
			["document", self::$documentMimes],
		] as [$fileType, $map]) {
			if(isset($map[$mime])) {
				return ["file_type" => $fileType, "mime_type" => $mime, "extension" => $map[$mime]];
			}
		}

		return ["file_type" => "other", "mime_type" => $mime, "extension" => self::GuessExtension($mime)];
	}

	private static function GuessExtension(string $mime): ?string {
		$parts = explode("/", $mime);
		return $parts[1] ?? null;
	}

}
