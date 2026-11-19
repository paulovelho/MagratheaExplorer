<?php

namespace MagratheaExplorer\Processing;

use Magrathea\DB\Database;
use Magrathea\Logger;
use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\ExplorerConfig;
use MagratheaExplorer\File\File;
use MagratheaExplorer\File\FileControl;
use MagratheaExplorer\File\FileRenditionControl;
use MagratheaExplorer\Key\Key;
use MagratheaExplorer\Storage\StorageFactory;
use MagratheaExplorer\Storage\StorageGarbage;

/**
 * When a file gets processed, and how. Only images have a handler in 1.5.0 (plan §8): every
 * other file is `none`, and so is everything when there's no `processor_command`.
 *
 * Upload (UploadPipeline): Prepare() before the row is inserted, Start() after.
 *   - Small enough (`inline_max_bytes` and `inline_max_pixels`), and not a bulk import: the job
 *     runs in the upload request, from the staged copy. The response carries the renditions.
 *   - Otherwise a header run (properties from headers only, nothing decoded) puts the cheap
 *     properties in the response, and the job waits for `cron.php --process`. A bulk import
 *     skips the header run: it has no response, and the queued run reads everything.
 *   - No specs in the key's profile, no handler for the type (BMP) or properties only (SVG):
 *     `none`, with whatever properties the header run read.
 * Processing never fails an upload: the original is stored first, and every failure ends up in
 * the file's `processing_status`/`processing_error`.
 */
final class FileProcessing {

	/** The header run reads a few header bytes: past this it's hung. */
	const HEADER_TIMEOUT_S = 15;
	/** An inline run holds the upload request. Slower ones fail here and cron retries them with the full timeout. */
	const INLINE_TIMEOUT_S = 30;
	const DEFAULT_INLINE_MAX_BYTES = 16 * 1024 * 1024;
	const DEFAULT_INLINE_MAX_PIXELS = 40_000_000;
	const DEFAULT_MAX_ATTEMPTS = 3;

	public static function InlineMaxBytes(): int {
		return ExplorerConfig::GetSize("inline_max_bytes", self::DEFAULT_INLINE_MAX_BYTES);
	}

	public static function InlineMaxPixels(): int {
		return ExplorerConfig::GetPositiveInt("inline_max_pixels", self::DEFAULT_INLINE_MAX_PIXELS);
	}

	public static function MaxAttempts(): int {
		return ExplorerConfig::GetPositiveInt("processing_max_attempts", self::DEFAULT_MAX_ATTEMPTS);
	}

	/**
	 * Upload, before the row exists. Never throws: a configuration that can't be read makes the
	 * file `failed`, not the upload.
	 * @param string $path the staged file, exactly what put() stores
	 * @param ?int $width/$height from getimagesize() (null for SVG or an unreadable header)
	 * @param bool $inlineAllowed false for bulk imports: they always queue
	 */
	public static function Prepare(Key $key, string $fileType, string $path, string $mimeType, int $size, ?int $width, ?int $height, bool $inlineAllowed): ProcessingPlan {
		if($fileType !== "image") return ProcessingPlan::None();
		try {
			$runner = ProcessorRunner::FromConfig();
			if($runner === null) return ProcessingPlan::None();
			try {
				$specs = $key->RenditionSpecs();
			} catch(\InvalidArgumentException $ex) {
				return ProcessingPlan::Failed("this key's rendition profile is invalid: ".$ex->getMessage());
			}
			$inline = $inlineAllowed && $specs !== [] && $size <= self::InlineMaxBytes()
				&& ($width === null || $height === null || $width * $height <= self::InlineMaxPixels());
			self::MaxAttempts(); // read now: a bad value must not surface after the insert
		} catch(\Throwable $ex) {
			Logger::Instance()->Log("processing settings: ".$ex->getMessage());
			return ProcessingPlan::Failed("the processing settings can't be read (".$ex->getMessage().")");
		}

		// The full run gives every property: no header run before it. Nor for a bulk import:
		// nobody reads its response, and the header run would start the processor once per file.
		if($inline) return new ProcessingPlan("pending", null, null, $specs, true, $runner);
		if(!$inlineAllowed && $specs !== []) return new ProcessingPlan("pending", null, null, $specs, false, $runner);

		$header = self::HeaderRun($runner, $path, $mimeType, $fileType);
		$properties = $header !== null && $header->ok && $header->handled ? $header->properties : null;
		if($specs === []) return ProcessingPlan::None($properties);
		if($header !== null && $header->ok && (!$header->handled || !$header->renders)) {
			return ProcessingPlan::None($properties);
		}
		// A failed header run says nothing about the full one: queue it, it shows the real error.
		return new ProcessingPlan("pending", null, $properties, $specs, false, $runner);
	}

	/**
	 * Upload, after the row is inserted with the plan's status: queues the job and, when the plan
	 * says so, runs it now. Never throws.
	 * @param string $stagedPath the staged upload (the inline run reads it instead of fetching it back)
	 */
	public static function Start(File $file, ProcessingPlan $plan, string $stagedPath): void {
		if($plan->status !== "pending" || $plan->runner === null) return;
		try {
			$job = ProcessingJobControl::Enqueue((int)$file->id, $plan->specs);
		} catch(\Throwable $ex) {
			Logger::Instance()->Log("processing: could not queue file ".$file->id.": ".$ex->getMessage());
			self::SetStatus((int)$file->id, "failed", "processing couldn't be queued");
			return;
		}
		if(!$plan->inline) return;
		try {
			$maxAttempts = self::MaxAttempts();
			$attempt = ProcessingJobControl::Claim((int)$job->id, JobRunner::LeaseSeconds($plan->runner), $maxAttempts);
			if($attempt === null) return; // a queue run took it first
			JobRunner::Run((int)$job->id, $attempt, $plan->runner, $maxAttempts, $stagedPath, min($plan->runner->timeoutS, self::INLINE_TIMEOUT_S));
		} catch(\Throwable $ex) {
			// The job stays claimed until its lease runs out; then a queue run retries it.
			Logger::Instance()->Log("processing: inline run of file ".$file->id." failed: ".$ex->getMessage());
		}
	}

	/**
	 * Makes the file's renditions again from its key's **current** profile, with new tokens (new
	 * URLs: storage objects are cached as immutable, so a rendition is never overwritten in
	 * place). Any queued or running job of the file is superseded; the new one is queued, never
	 * run inline. The old renditions stay until the new job commits, and stay for good if it
	 * fails. With an empty profile, the renditions are deleted and the file is `none`.
	 * Not an image: nothing to do (status `none`).
	 * @throws 5004 no processor, or its settings can't be read
	 */
	public static function Regenerate(File $file): void {
		$fileId = (int)$file->id;
		if($file->file_type !== "image") {
			ProcessingJobControl::SupersedeForFile($fileId);
			self::SetStatus($fileId, "none", null);
			return;
		}
		$runner = ProcessorRunner::FromConfig();
		if($runner === null) {
			ErrorCodes::Instance()->ThrowException(5004, null, "no `processor_command` in magrathea.conf: renditions can't be made");
		}
		try {
			$specs = (new Key($file->key_id))->RenditionSpecs();
		} catch(\InvalidArgumentException $ex) {
			ProcessingJobControl::SupersedeForFile($fileId);
			self::SetStatus($fileId, "failed", ProcessingJobControl::Truncate("this key's rendition profile is invalid: ".$ex->getMessage()));
			return;
		}

		$db = Database::Instance();
		if($specs === []) {
			$previous = $db->Transaction(function() use ($fileId) {
				ProcessingJobControl::SupersedeForFile($fileId);
				$previous = FileRenditionControl::GetForFile($fileId);
				FileRenditionControl::DeleteForFile($fileId);
				self::SetStatus($fileId, "none", null);
				return $previous;
			});
			StorageGarbage::Delete(StorageFactory::Instance()->Get(), array_map(fn($r) => (string)$r->storage_path, $previous));
			return;
		}
		// One transaction: a worker never sees the new job before the file says pending, so its
		// commit can't be overwritten by a late `pending`.
		$db->Transaction(function() use ($fileId, $specs) {
			ProcessingJobControl::SupersedeForFile($fileId);
			self::SetStatus($fileId, "pending", null);
			ProcessingJobControl::Enqueue($fileId, $specs);
		});
	}

	/**
	 * `cron.php --regenerate`: Regenerate() on every image of a key, or of one folder of it.
	 * @param ?callable $onFile fn(File $file, ?string $error)
	 * @return array{queued: int, failed: array<int, string>}
	 */
	public static function RegenerateMany(int $keyId, ?int $folderId = null, ?callable $onFile = null): array {
		if(ProcessorRunner::FromConfig() === null) {
			ErrorCodes::Instance()->ThrowException(5004, null, "no `processor_command` in magrathea.conf: renditions can't be made");
		}
		$where = "`key_id` = ".$keyId." AND `file_type` = 'image'".($folderId !== null ? " AND `folder_id` = ".$folderId : "");
		$queued = 0;
		$failed = [];
		$lastId = 0;
		while(true) {
			$batch = FileControl::RunQuery("SELECT * FROM `files` WHERE ".$where." AND `id` > ".$lastId." ORDER BY `id` LIMIT 100");
			if($batch === []) break;
			foreach($batch as $file) {
				$lastId = (int)$file->id;
				$error = null;
				try {
					self::Regenerate($file);
					$queued++;
				} catch(\Throwable $ex) {
					$error = $ex->getMessage();
					$failed[(int)$file->id] = $error;
				}
				if($onFile !== null) $onFile($file, $error);
			}
		}
		return ["queued" => $queued, "failed" => $failed];
	}

	/** Properties from headers only (mode `header`), on the staged file. null = the run couldn't even start. */
	private static function HeaderRun(ProcessorRunner $runner, string $path, string $mimeType, string $fileType): ?ProcessorResult {
		$dir = null;
		try {
			$dir = ProcessorJobDir::Create("header-".bin2hex(random_bytes(8)));
			$result = $runner->Run($dir, $path, $mimeType, $fileType, ProcessorRunner::MODE_HEADER, [], min($runner->timeoutS, self::HEADER_TIMEOUT_S));
			if(!$result->ok) Logger::Instance()->Log("processing: header run failed: ".$result->error);
			return $result;
		} catch(\Throwable $ex) {
			Logger::Instance()->Log("processing: header run failed: ".$ex->getMessage());
			return null;
		} finally {
			$dir?->Remove();
		}
	}

	private static function SetStatus(int $fileId, string $status, ?string $error): void {
		Database::Instance()->PrepareAndExecute(
			"UPDATE `files` SET `processing_status` = ?, `processing_error` = ? WHERE `id` = ?",
			["string", "string", "int"],
			[$status, $error, $fileId]
		);
	}

}
