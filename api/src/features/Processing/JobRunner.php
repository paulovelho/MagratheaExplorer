<?php

namespace MagratheaExplorer\Processing;

use Magrathea\DB\Database;
use Magrathea\Logger;
use MagratheaExplorer\File\File;
use MagratheaExplorer\File\FileControl;
use MagratheaExplorer\File\FileRendition;
use MagratheaExplorer\File\FileRenditionControl;
use MagratheaExplorer\Key\Key;
use MagratheaExplorer\Storage\StorageGarbage;
use MagratheaExplorer\Storage\StorageFactory;

/**
 * Runs one claimed job (ProcessingJobControl::Claim()), the same way for an upload's inline run,
 * `cron.php --process` and a regenerate:
 *   1. the original goes into the job dir: the staged upload at upload time (byte-identical
 *      to the stored object), else fetched from storage;
 *   2. the processor runs (mode `full`);
 *   3. each rendition is stored at `{storage_uuid}/{token}.{ext}` with the job's own tokens,
 *      so a retry overwrites the same objects;
 *   4. one transaction: the job must still be this attempt's and the file must still exist,
 *      then the file's rendition rows are replaced, its properties merged, its status set,
 *      and the job closed;
 *   5. after the commit, the previous renditions' objects are deleted (one that fails goes into
 *      StorageGarbage, which `cron.php --process` retries);
 *   6. when step 4 finds the job superseded or gone (a regenerate, a delete), this run deletes
 *      the objects it stored.
 * A run that fails (processor, storage) goes back to the queue while attempts remain
 * (ProcessingJobControl::RecordFailure()). A run that finishes is final, even when some
 * renditions failed: those failures repeat every time.
 */
final class JobRunner {

	/** The lease covers the processor's timeout plus this, for the fetch and the stores. */
	const LEASE_MARGIN_S = 60;

	/** Renditions stored and committed: the file is ready, failed (some renditions failed) or none. */
	const DONE = "done";
	/** The run failed and the job went back to the queue. */
	const RETRY = "retry";
	/** The run failed on its last attempt: job and file are failed. */
	const FAILED = "failed";
	/** The job stopped being this run's while it ran (superseded, deleted, or its file deleted). */
	const LOST = "lost";

	public static function LeaseSeconds(ProcessorRunner $runner): int {
		return $runner->timeoutS + self::LEASE_MARGIN_S;
	}

	/**
	 * @param ?string $localOriginal the staged upload (inline runs); null = fetch the original from storage
	 * @param ?int $timeoutS overrides the processor's timeout (inline runs use a shorter one)
	 * @return array{outcome: string, status: ?string, error: ?string, seconds: float}
	 *         status: the file's processing status after the run (null = this run didn't set it)
	 */
	public static function Run(int $jobId, int $attempt, ProcessorRunner $runner, int $maxAttempts, ?string $localOriginal = null, ?int $timeoutS = null): array {
		$started = hrtime(true);
		$report = fn(string $outcome, ?string $status = null, ?string $error = null) => [
			"outcome" => $outcome, "status" => $status, "error" => $error, "seconds" => (hrtime(true) - $started) / 1e9,
		];

		$job = ProcessingJobControl::GetRowWhere(["id" => $jobId]);
		$file = $job !== null ? FileControl::GetRowWhere(["id" => (int)$job->file_id]) : null;
		if($job === null || $file === null) return $report(self::LOST);
		$fail = function(string $error, ?string $logDetail = null) use ($job, $file, $attempt, $maxAttempts, $report) {
			Logger::Instance()->Log("processing job ".$job->id." (file ".$file->id.") attempt ".$attempt.": ".$error.($logDetail !== null ? " -- ".$logDetail : ""));
			$status = ProcessingJobControl::RecordFailure((int)$job->id, (int)$file->id, $attempt, $maxAttempts, $error);
			return match($status) {
				ProcessingJob::QUEUED => $report(self::RETRY, "pending", $error),
				ProcessingJob::FAILED => $report(self::FAILED, "failed", $error),
				default => $report(self::LOST, null, $error),
			};
		};

		try {
			$specs = $job->Specs();
		} catch(\InvalidArgumentException $ex) {
			return $fail("the job's rendition specs are invalid: ".$ex->getMessage());
		}
		$tokens = $job->Tokens();
		foreach($specs as $spec) {
			if(!isset($tokens[$spec["name"]])) return $fail("the job has no token for rendition `".$spec["name"]."`");
		}

		$storage = StorageFactory::Instance()->Get();
		$key = new Key($file->key_id);
		$dir = null;
		$stored = [];
		try {
			try {
				$dir = ProcessorJobDir::Create("job-".$job->id."-".$attempt);
			} catch(\Throwable $ex) {
				return $fail("could not create the job folder in processing_tmp_path", $ex->getMessage());
			}
			if($localOriginal !== null) {
				if(!@copy($localOriginal, $dir->input)) return $fail("could not copy the upload into the job folder");
			} else {
				try {
					$storage->fetch($file->storage_path, $dir->input);
				} catch(\Throwable $ex) {
					return $fail("could not fetch the original from storage", $ex->getMessage());
				}
			}

			$result = $runner->Run($dir, $dir->input, (string)$file->mime_type, (string)$file->file_type, ProcessorRunner::MODE_FULL, $specs, $timeoutS);
			if(!$result->ok) return $fail((string)$result->error);

			foreach($result->renditions as $name => $output) {
				$name = (string)$name;
				$path = $key->StoragePathFor($tokens[$name], $output["extension"]);
				try {
					$storage->put($path, $output["path"], ["content_type" => $output["mime_type"], "disposition" => "inline"]);
				} catch(\Throwable $ex) {
					StorageGarbage::Delete($storage, array_column($stored, "storage_path"));
					return $fail("could not store rendition `".$name."`", $ex->getMessage());
				}
				$stored[$name] = $output + ["token" => $tokens[$name], "storage_path" => $path];
			}

			// The processor may have run for minutes: past a short `wait_timeout`, the connection
			// would be dead. The commit gets a fresh one.
			Database::Instance()->Disconnect();
			$commit = self::Commit($job, $attempt, $file, $specs, $result, $stored);
			if($commit === null) {
				if(self::JobGone((int)$job->id)) {
					StorageGarbage::Delete($storage, array_column($stored, "storage_path"));
				} elseif($stored !== []) {
					// another attempt of this same job owns it now, and writes the same objects
					Logger::Instance()->Log("processing job ".$job->id." attempt ".$attempt.": lost the job to a later attempt; its objects are left to that one");
				}
				return $report(self::LOST);
			}
			$keep = array_column($stored, "storage_path");
			StorageGarbage::Delete($storage, array_filter(
				array_map(fn(FileRendition $r) => (string)$r->storage_path, $commit["previous"]),
				fn($path) => !in_array($path, $keep, true)
			));
			return $report(self::DONE, $commit["status"], $commit["error"]);
		} finally {
			$dir?->Remove();
		}
	}

	/**
	 * Step 4. null = the job isn't this attempt's anymore, or the file is gone: nothing written.
	 * @param array<string, array> $stored rendition name => ProcessorRunner output + token + storage_path
	 * @return ?array{status: string, error: ?string, previous: FileRendition[]}
	 */
	private static function Commit(ProcessingJob $job, int $attempt, File $file, array $specs, ProcessorResult $result, array $stored): ?array {
		if(!$result->handled || !$result->renders || $specs === []) {
			$status = "none";   // no handler for the type (BMP), or properties only (SVG)
		} else {
			$status = $result->errors === [] ? "ready" : "failed";
		}
		$errors = [];
		foreach($result->errors as $name => $reason) {
			$errors[] = "rendition `".$name."`: ".$reason;
		}
		$error = $errors === [] ? null : ProcessingJobControl::Truncate(implode("; ", $errors));
		$properties = $file->StoredProperties();
		if($result->handled) {
			$properties ??= [];
			foreach($result->properties as $name => $value) {
				// the full run is authoritative, but a value it couldn't read doesn't erase one the header run did
				if($value !== null || !array_key_exists($name, $properties)) $properties[$name] = $value;
			}
		}

		$db = Database::Instance();
		return $db->Transaction(function() use ($db, $job, $attempt, $file, $stored, $status, $error, $properties) {
			if(!ProcessingJobControl::LockIfOwned((int)$job->id, $attempt)) return null;
			if(empty($db->QueryRow("SELECT `id` FROM `files` WHERE `id` = ".(int)$file->id." FOR UPDATE"))) return null;

			$previous = FileRenditionControl::GetForFile((int)$file->id);
			FileRenditionControl::DeleteForFile((int)$file->id);
			foreach($stored as $name => $output) {
				$rendition = new FileRendition();
				$rendition->file_id = (int)$file->id;
				$rendition->name = (string)$name;
				$rendition->token = $output["token"];
				$rendition->storage_path = $output["storage_path"];
				$rendition->extension = $output["extension"];
				$rendition->mime_type = $output["mime_type"];
				$rendition->width = $output["width"];
				$rendition->height = $output["height"];
				$rendition->size = $output["size"];
				$rendition->Insert();
			}
			$db->PrepareAndExecute(
				"UPDATE `files` SET `properties` = ?, `processing_status` = ?, `processing_error` = ? WHERE `id` = ?",
				["text", "string", "string", "int"],
				[$properties === null ? null : json_encode((object)$properties, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $status, $error, (int)$file->id]
			);
			$db->PrepareAndExecute(
				"UPDATE `processing_jobs` SET `status` = ?, `lease_until` = NULL, `error` = ? WHERE `id` = ?",
				["string", "string", "int"],
				[$status === "failed" ? ProcessingJob::FAILED : ProcessingJob::DONE, $error, (int)$job->id]
			);
			return ["status" => $status, "error" => $error, "previous" => array_values($previous)];
		});
	}

	/** The job was superseded or deleted (with its file, or by a regenerate): nobody will ever reference its tokens. */
	private static function JobGone(int $jobId): bool {
		$status = Database::Instance()->QueryOne("SELECT `status` FROM `processing_jobs` WHERE `id` = ".$jobId);
		return $status === null || $status === ProcessingJob::SUPERSEDED;
	}

}
