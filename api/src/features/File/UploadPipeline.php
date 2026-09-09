<?php

namespace MagratheaExplorer\File;

use Magrathea2\ConfigApp;
use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\Folder\Folder;
use MagratheaExplorer\Key\Key;
use MagratheaExplorer\Storage\StorageFactory;

/**
 * Orchestrates a single upload end to end: size/quota checks, MIME sniffing, type-
 * dispatched processing (image pipeline / audio-video duration / pass-through),
 * token minting, storage put, and the `files` row + key counters. See
 * ImageProcessor/MetadataStripper/MediaMetadataReader/TokenGenerator for the pieces.
 */
class UploadPipeline {

	const DEFAULT_MAX_UPLOAD_SIZE = 5 * 1024 * 1024; // 5MB
	const DEFAULT_THUMBNAIL_SIZE = 200;

	private static array $mimeByExtension = [
		"jpg" => "image/jpeg",
		"jpeg" => "image/jpeg",
		"png" => "image/png",
		"webp" => "image/webp",
		"gif" => "image/gif",
		"bmp" => "image/bmp",
		"svg" => "image/svg+xml",
	];

	/**
	 * @param array $uploadedFile One entry of $_FILES (name/type/tmp_name/error/size)
	 * @param string[] $tagNames  Tag names to attach after a successful upload
	 * @param bool $enforceQuota  False lets a bulk import (see ImportPipeline) run past the
	 *                            key's usage_limit/usage_limit_mb caps; active/expiration/
	 *                            pending-deletion checks always apply regardless.
	 */
	public static function Handle(Key $key, Folder $folder, array $uploadedFile, bool $noConvert, array $tagNames = [], bool $enforceQuota = true): File {
		self::AssertUploadOk($uploadedFile);
		$uploadSize = (int)($uploadedFile["size"] ?? 0);
		self::AssertMaxSize($uploadSize);
		// Checked before any processing work happens -- against the raw upload size, a
		// conservative upper bound; the real deduction below uses the final stored size.
		$key->AssertCanImport($uploadSize, $enforceQuota);

		$workingPath = self::MoveToWorkingCopy($uploadedFile["tmp_name"]);
		$tempFiles = [$workingPath];

		try {
			$detected = MimeSniffer::Detect($workingPath);
			$fileType = $detected["file_type"];
			$mimeType = $detected["mime_type"];
			$extension = $detected["extension"];

			$width = null;
			$height = null;
			$duration = null;
			$finalPath = $workingPath;
			$finalExtension = $extension;
			$thumbnailPath = null;
			$thumbnailExtension = null;

			if($fileType === "image") {
				MetadataStripper::Strip($workingPath, $extension);
				$reencoded = ImageProcessor::ReencodeAndConvert($workingPath, $extension, $noConvert);
				$finalPath = $reencoded["path"];
				$finalExtension = $reencoded["extension"];
				if($finalPath !== $workingPath) $tempFiles[] = $finalPath;

				[$width, $height] = ImageProcessor::GetDimensions($finalPath, $finalExtension);

				$thumbSize = self::ParseSize(ConfigApp::Instance()->Get("thumbnail_size"), self::DEFAULT_THUMBNAIL_SIZE);
				$thumb = ImageProcessor::BuildThumbnail($finalPath, $finalExtension, $thumbSize);
				if($thumb !== null) {
					$thumbnailPath = $thumb["path"];
					$thumbnailExtension = $thumb["extension"];
					$tempFiles[] = $thumbnailPath;
				}
				$mimeType = self::$mimeByExtension[$finalExtension] ?? $mimeType;
			} elseif($fileType === "audio" || $fileType === "video") {
				$meta = MediaMetadataReader::ReadDuration($workingPath);
				$duration = $meta["duration"];
			}
			// document/other: stored as-is, no processing

			$storedSize = (int)filesize($finalPath);

			$fileToken = TokenGenerator::GenerateUnique();
			$thumbnailToken = $thumbnailPath !== null ? TokenGenerator::GenerateUnique() : null;

			// Content-Disposition is set once, at upload time -- there's no PHP in the read
			// path to correct it later. Forced to attachment for file_type=other and SVG:
			// the real stored-XSS protection, since nosniff has no PutObject equivalent.
			$disposition = ($fileType === "other" || $finalExtension === "svg") ? "attachment" : "inline";
			$storagePath = $fileToken.".".$finalExtension;

			$storage = StorageFactory::Instance()->Get();
			try {
				$storage->put($storagePath, $finalPath, ["content_type" => $mimeType, "disposition" => $disposition]);
				if($thumbnailPath !== null) {
					$thumbStoragePath = $thumbnailToken.".".$thumbnailExtension;
					$storage->put($thumbStoragePath, $thumbnailPath, [
						"content_type" => self::$mimeByExtension[$thumbnailExtension] ?? "image/jpeg",
						"disposition" => "inline",
					]);
				}
			} catch(\Throwable $ex) {
				ErrorCodes::Instance()->ThrowException(5001, null, $ex->getMessage());
			}

			$file = new File();
			$file->token = $fileToken;
			$file->thumbnail_token = $thumbnailToken;
			$file->key_id = $key->id;
			$file->folder_id = $folder->id;
			$file->name = $uploadedFile["name"] ?? "upload";
			$file->storage_path = $storagePath;
			$file->extension = $finalExtension;
			$file->mime_type = $mimeType;
			$file->file_type = $fileType;
			$file->size = $storedSize;
			$file->width = $width;
			$file->height = $height;
			$file->duration = $duration;
			$file->no_convert = $noConvert ? 1 : 0;
			$file->Insert();

			$key->IncrementUses();
			$key->AdjustSize($storedSize);

			foreach($tagNames as $tagName) {
				$tag = TagControl::GetOrCreate($tagName);
				FileTagControl::Attach((int)$file->id, (int)$tag->id);
			}

			return $file;
		} finally {
			foreach($tempFiles as $tmp) {
				if(is_file($tmp)) @unlink($tmp);
			}
		}
	}

	private static function MoveToWorkingCopy(string $uploadedTmpName): string {
		$working = tempnam(sys_get_temp_dir(), "explorer_up_");
		$moved = is_uploaded_file($uploadedTmpName)
			? move_uploaded_file($uploadedTmpName, $working)
			: copy($uploadedTmpName, $working); // supports non-HTTP callers (CLI/tests)
		if(!$moved) {
			ErrorCodes::Instance()->ThrowException(5001, null, "could not stage uploaded file");
		}
		return $working;
	}

	private static function AssertUploadOk(array $file): void {
		$error = $file["error"] ?? UPLOAD_ERR_NO_FILE;
		// Checked before the tmp_name/no-file check: a file that exceeds
		// upload_max_filesize gets error=UPLOAD_ERR_INI_SIZE *and* an empty tmp_name, so
		// checking tmp_name first would misreport it as "missing file" instead of "too large".
		if(in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
			ErrorCodes::Instance()->ThrowException(4002);
		}
		if($error === UPLOAD_ERR_NO_FILE || empty($file["tmp_name"])) {
			ErrorCodes::Instance()->ThrowException(4001, null, "file");
		}
		if($error !== UPLOAD_ERR_OK) {
			ErrorCodes::Instance()->ThrowException(4001, null, "upload error code ".$error);
		}
	}

	private static function AssertMaxSize(int $uploadSize): void {
		$configured = self::ParseSize(ConfigApp::Instance()->Get("max_upload_size"), self::DEFAULT_MAX_UPLOAD_SIZE);
		$phpLimit = min(
			self::ParseSize(ini_get("post_max_size"), PHP_INT_MAX),
			self::ParseSize(ini_get("upload_max_filesize"), PHP_INT_MAX)
		);
		$limit = min($configured, $phpLimit);
		if($uploadSize > $limit) {
			ErrorCodes::Instance()->ThrowException(4002);
		}
	}

	/** Accepts a plain byte count or a PHP-ini-style size ("5M", "512K", "1G"). */
	private static function ParseSize($value, int $default): int {
		if(empty($value)) return $default;
		$value = trim((string)$value);
		if(preg_match('/^(\d+)([KMG]?)$/i', $value, $m) !== 1) return $default;
		$bytes = (int)$m[1];
		switch(strtoupper($m[2])) {
			case "G": $bytes *= 1024;
			case "M": $bytes *= 1024;
			case "K": $bytes *= 1024;
		}
		return $bytes;
	}

}
