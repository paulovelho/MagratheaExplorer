<?php

namespace MagratheaExplorer\File;

use Magrathea\DB\Database;
use Magrathea\MagratheaHelper;
use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\Folder\Folder;
use MagratheaExplorer\Key\Key;
use MagratheaExplorer\Processing\FileProcessing;
use MagratheaExplorer\Storage\StorageFactory;
use MagratheaExplorer\Storage\StorageGarbage;

/**
 * Orchestrates a single upload end to end: size/quota checks, MIME sniffing, type-
 * dispatched processing (image pipeline / audio-video duration / pass-through),
 * sha256 of the stored bytes, token minting, storage put, the `files` row + key counters,
 * then properties and renditions (FileProcessing). See ImageProcessor/MetadataStripper/
 * MediaMetadataReader/TokenGenerator for the pieces.
 *
 * Images: verbatim stores the bytes as sent (sniff and content checks only); otherwise
 * metadata is stripped and the image re-encoded through GD, within ImageProcessor's
 * conversion limits (4131 above them). Renditions come from the processor, from the stored
 * bytes, never from GD: in the request for small files, from `cron.php --process` otherwise.
 */
class UploadPipeline {

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
	 * @param UploadOptions $options Build it with UploadOptions::Resolve(), which applies the
	 *                               key's defaults. Its enforceQuota=false lets a bulk import
	 *                               (see ImportPipeline) run past the key's usage caps;
	 *                               active/expiration/pending-deletion always apply.
	 *                               inlineProcessing=false queues every job (bulk import).
	 */
	public static function Handle(Key $key, Folder $folder, array $uploadedFile, UploadOptions $options): File {
		self::AssertAcceptable($key, $uploadedFile);
		$uploadSize = (int)($uploadedFile["size"] ?? 0);
		// Checked before any processing work happens -- against the raw upload size, a
		// conservative upper bound; the real deduction below uses the final stored size.
		$key->AssertCanImport($uploadSize, $options->enforceQuota);

		// A scratch file of our own (a source_url fetch) is the working copy already: no second copy.
		$workingPath = $options->consumeSource ? (string)$uploadedFile["tmp_name"] : self::MoveToWorkingCopy($uploadedFile["tmp_name"]);
		$tempFiles = [$workingPath];

		try {
			$detected = MimeSniffer::Detect($workingPath);
			self::AssertAllowedType($options->allowedFileTypes, $detected["file_type"]);
			UploadValidator::Validate($workingPath, $detected);
			$fileType = $detected["file_type"];
			$mimeType = $detected["mime_type"];
			$extension = $detected["extension"];

			$width = null;
			$height = null;
			$duration = null;
			$finalPath = $workingPath;
			$finalExtension = $extension;

			if($fileType === "image") {
				// Verbatim: $workingPath is the upload, byte for byte (move_uploaded_file(),
				// copy() and a fetch don't alter it), stored under the sniffed extension.
				if(!$options->verbatim) {
					// Strip and re-encode hold the whole image in memory: refused above the
					// conversion limits, before anything is stored or counted.
					ImageProcessor::AssertConvertible($workingPath, $extension);
					MetadataStripper::Strip($workingPath, $extension);
					$reencoded = ImageProcessor::ReencodeAndConvert($workingPath, $extension, $options->noConvert);
					$finalPath = $reencoded["path"];
					$finalExtension = $reencoded["extension"];
					if($finalPath !== $workingPath) $tempFiles[] = $finalPath;
				}

				// header only: decodes nothing, whatever the size
				[$width, $height] = ImageProcessor::GetDimensions($finalPath, $finalExtension);
				$mimeType = self::$mimeByExtension[$finalExtension] ?? $mimeType;
			} elseif($fileType === "audio" || $fileType === "video") {
				$meta = MediaMetadataReader::ReadDuration($workingPath);
				$duration = $meta["duration"];
			}
			// document/other: stored as-is, no processing

			$storedSize = (int)filesize($finalPath);
			// The bytes put() stores, not necessarily the ones sent: equal only when nothing
			// touched them (verbatim, or not an image: a non-verbatim SVG is stripped in place,
			// so the path alone doesn't tell). Then the upload's hash, when the caller has it
			// (Idempotency-Key), is the stored one. hash_file() streams: flat memory.
			$untouched = $fileType !== "image" || $options->verbatim;
			$sha256 = $untouched && $options->uploadSha256 !== null
				? $options->uploadSha256
				: hash_file("sha256", $finalPath);
			if($sha256 === false) {
				ErrorCodes::Instance()->ThrowException(5001, null, "could not hash the staged file");
			}

			// Header properties now, from the staged bytes, so they go into the row; renditions
			// after the insert. Never throws: processing doesn't fail an upload.
			$processing = FileProcessing::Prepare($key, $fileType, $finalPath, $mimeType, $storedSize, $width, $height, $options->inlineProcessing);

			$fileToken = TokenGenerator::GenerateUnique();

			// Content-Disposition is set once, at upload time -- there's no PHP in the read
			// path to correct it later. Forced to attachment for file_type=other and SVG:
			// the real stored-XSS protection, since nosniff has no PutObject equivalent.
			// The client's original filename rides along in the header so a download gets
			// that name instead of the opaque {token}.{ext} storage key.
			$dispositionType = File::DispositionTypeFor($fileType, $finalExtension);
			$disposition = self::BuildDisposition($dispositionType, $uploadedFile["name"] ?? "upload");
			$storagePath = $key->StoragePathFor($fileToken, $finalExtension);

			$storage = StorageFactory::Instance()->Get();
			try {
				$storage->put($storagePath, $finalPath, ["content_type" => $mimeType, "disposition" => $disposition]);
			} catch(\Throwable $ex) {
				ErrorCodes::Instance()->ThrowException(5001, null, $ex->getMessage());
			}

			$file = new File();
			// $file->uuid is left unset -- File declares it "uuid", so Insert() mints
			// it (see MagratheaModel.php's CreateInsertQuery()).
			$file->token = $fileToken;
			$file->key_id = $key->id;
			$file->folder_id = $folder->id;
			$file->name = $uploadedFile["name"] ?? "upload";
			$file->storage_path = $storagePath;
			$file->extension = $finalExtension;
			$file->mime_type = $mimeType;
			$file->file_type = $fileType;
			$file->size = $storedSize;
			$file->sha256 = $sha256;
			$file->width = $width;
			$file->height = $height;
			$file->duration = $duration;
			$file->properties = $processing->PropertiesJson();
			$file->processing_status = $processing->status;
			$file->processing_error = $processing->error;
			$file->no_convert = $options->noConvert ? 1 : 0;
			$file->verbatim = $options->verbatim ? 1 : 0;
			try {
				// The row, the key's counters and the caller's record of it (onStored: the
				// Idempotency-Key's resource) commit together, or none of them does.
				Database::Instance()->Transaction(function() use ($file, $key, $storedSize, $options) {
					$file->Insert();
					if($options->onStored !== null) ($options->onStored)($file);
					$key->IncrementUses();
					$key->AdjustSize($storedSize);
				});
			} catch(\Throwable $ex) {
				// No row points at the stored object: without this it's an orphan (admin → Browser).
				// A delete that fails is queued for `cron.php --process` (or logged, if the queue
				// can't be written either: the insert may have failed because the DB is down).
				StorageGarbage::Delete($storage, [$storagePath]);
				throw $ex;
			}

			foreach($options->tagNames as $tagName) {
				$tag = TagControl::GetOrCreate($tagName);
				FileTagControl::Attach((int)$file->id, (int)$tag->id);
			}

			// Queues the job and, for a small file, runs it now from the staged copy (still
			// here: the finally below removes it). Never throws.
			FileProcessing::Start($file, $processing, $finalPath);
			return $file;
		} finally {
			foreach($tempFiles as $tmp) {
				if(is_file($tmp)) @unlink($tmp);
			}
		}
	}

	/**
	 * Builds a Content-Disposition value carrying the original filename: a quoted
	 * ASCII-only `filename` for legacy clients plus an RFC 5987 `filename*` for correct
	 * Unicode handling everywhere else. $originalName is client-supplied and untrusted,
	 * so both are stripped of path separators, quotes, and non-printable/non-ASCII bytes
	 * before being embedded in a header value.
	 */
	private static function BuildDisposition(string $dispositionType, string $originalName): string {
		$originalName = basename($originalName);
		$asciiFallback = preg_replace('/[^\x20-\x7E]/', '_', $originalName);
		$asciiFallback = str_replace(['"', '\\'], '_', $asciiFallback);
		if($asciiFallback === "") $asciiFallback = "download";
		return $dispositionType.'; filename="'.$asciiFallback.'"; filename*=UTF-8\'\''.rawurlencode($originalName);
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

	/**
	 * The checks that need nothing but the upload entry: it arrived (4001) and isn't above the key's
	 * effective limit (4002). Handle() runs them first; FileApi runs them before hashing an upload
	 * for its Idempotency-Key, so a file about to be refused is never read.
	 */
	public static function AssertAcceptable(Key $key, array $uploadedFile): void {
		self::AssertUploadOk($key, $uploadedFile);
		$uploadSize = (int)($uploadedFile["size"] ?? 0);
		if($uploadSize > $key->EffectiveMaxUploadSize()) self::RefuseTooLarge($key, $uploadSize);
	}

	/** sha256 of the uploaded bytes, streamed. Call AssertAcceptable() first. */
	public static function HashUpload(array $uploadedFile): string {
		$hash = @hash_file("sha256", (string)$uploadedFile["tmp_name"]);
		if($hash === false) {
			ErrorCodes::Instance()->ThrowException(5001, null, "could not hash the uploaded file");
		}
		return $hash;
	}

	private static function AssertUploadOk(Key $key, array $file): void {
		$error = $file["error"] ?? UPLOAD_ERR_NO_FILE;
		// Checked before the tmp_name/no-file check: a file that exceeds
		// upload_max_filesize gets error=UPLOAD_ERR_INI_SIZE *and* an empty tmp_name, so
		// checking tmp_name first would misreport it as "missing file" instead of "too large".
		if(in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
			self::RefuseTooLarge($key, null); // PHP stopped reading it: the size is unknown
		}
		if($error === UPLOAD_ERR_NO_FILE || empty($file["tmp_name"])) {
			ErrorCodes::Instance()->ThrowException(4001, null, "file");
		}
		if($error !== UPLOAD_ERR_OK) {
			ErrorCodes::Instance()->ThrowException(4001, null, "upload error code ".$error);
		}
	}

	/** 4151 when the sniffed type isn't one an upload token allows. null = any type. */
	public static function AssertAllowedType(?array $allowed, string $fileType): void {
		if($allowed === null || in_array($fileType, $allowed, true)) return;
		ErrorCodes::Instance()->ThrowException(4151, null, $fileType." isn't allowed by this upload token (allowed: ".implode(", ", $allowed).")");
	}

	/**
	 * 4002, with the key's effective limit (Key::EffectiveMaxUploadSize()) in the detail, and the
	 * upload's size when it's known. For every way an upload turns out too big, FileApi's included.
	 */
	public static function RefuseTooLarge(Key $key, ?int $size): never {
		self::RefuseAbove($key->EffectiveMaxUploadSize(), $size);
	}

	/** 4002 against any limit (an upload token's max_size), same detail as RefuseTooLarge(). */
	public static function RefuseAbove(int $limit, ?int $size, string $suffix = ""): never {
		$limitText = MagratheaHelper::FormatSize($limit);
		if($size === null) {
			$detail = "the limit is ".$limitText;
		} else {
			$sizeText = MagratheaHelper::FormatSize($size);
			// just over the limit, both round to the same "1.00 MB": exact bytes then
			$detail = $sizeText !== $limitText
				? $sizeText.", the limit is ".$limitText
				: $size." bytes, the limit is ".$limit." bytes (".$limitText.")";
		}
		ErrorCodes::Instance()->ThrowException(4002, null, $detail.$suffix);
		throw new \LogicException("unreachable"); // ThrowException() always throws
	}

}
