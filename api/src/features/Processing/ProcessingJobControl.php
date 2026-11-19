<?php

namespace MagratheaExplorer\Processing;

use Magrathea\DB\Database;
use Magrathea\MagratheaModelControl;
use MagratheaExplorer\File\TokenGenerator;

/**
 * The queue's state changes. Each one is a single conditional UPDATE, or a transaction that
 * locks the job row first: a worker, a regenerate and a delete can meet on the same job, and
 * whoever loses finds out from the row, never by overwriting it.
 */
class ProcessingJobControl extends MagratheaModelControl {
	protected static $model = ProcessingJob::class;

	/** Finished jobs (done, failed, superseded) are deleted after this many days. */
	const KEEP_FINISHED_DAYS = 30;

	/** A queued run of the file with these specs. Every rendition's token is minted now. */
	public static function Enqueue(int $fileId, array $specs): ProcessingJob {
		$tokens = [];
		foreach($specs as $spec) {
			$tokens[(string)$spec["name"]] = TokenGenerator::GenerateUnique();
		}
		$job = new ProcessingJob();
		$job->file_id = $fileId;
		$job->status = ProcessingJob::QUEUED;
		$job->attempts = 0;
		$job->profile = RenditionProfile::Encode($specs);
		// an object even when empty: `{}`, never `[]`
		$job->tokens = json_encode((object)$tokens, JSON_THROW_ON_ERROR);
		$job->Insert();
		return $job;
	}

	/**
	 * Takes the job for one run: `attempts` + 1 and a lease of $leaseS seconds. Only a queued
	 * job, or a running one whose lease ran out (its worker died), with attempts left.
	 * @return ?int this run's attempt number; null = not claimed (someone else has it, or it's
	 *              done, superseded, deleted or out of attempts)
	 */
	public static function Claim(int $jobId, int $leaseS, int $maxAttempts): ?int {
		$db = Database::Instance();
		$db->PrepareAndExecute(
			"UPDATE `processing_jobs` SET `status` = 'running', `attempts` = `attempts` + 1, `lease_until` = NOW() + INTERVAL ? SECOND"
			." WHERE `id` = ? AND `attempts` < ? AND (`status` = 'queued' OR (`status` = 'running' AND `lease_until` < NOW()))",
			["int", "int", "int"],
			[$leaseS, $jobId, $maxAttempts]
		);
		if($db->LastAffectedRows() !== 1) return null;
		return (int)$db->QueryOne("SELECT `attempts` FROM `processing_jobs` WHERE `id` = ".$jobId);
	}

	/** The next job a queue run can claim with an id above $afterId, oldest first; null = none. */
	public static function NextClaimable(int $afterId, int $maxAttempts): ?int {
		$id = Database::Instance()->QueryOne(
			"SELECT `id` FROM `processing_jobs` WHERE `id` > ".$afterId." AND `attempts` < ".$maxAttempts
			." AND (`status` = 'queued' OR (`status` = 'running' AND `lease_until` < NOW())) ORDER BY `id` LIMIT 1"
		);
		return $id === null ? null : (int)$id;
	}

	/**
	 * Locks the job row (inside a Transaction()) and says whether this attempt still owns it:
	 * running, with this attempt number. false = superseded, deleted, or claimed again by a later
	 * attempt after this one's lease ran out.
	 */
	public static function LockIfOwned(int $jobId, int $attempt): bool {
		$row = Database::Instance()->QueryRow(
			"SELECT `status`, `attempts` FROM `processing_jobs` WHERE `id` = ".$jobId." FOR UPDATE"
		);
		return !empty($row) && $row["status"] === ProcessingJob::RUNNING && (int)$row["attempts"] === $attempt;
	}

	/**
	 * A run of this attempt failed. While attempts remain, the job goes back to the queue and
	 * the file stays pending with the error (a retry is coming); after the last one, both are
	 * failed. Nothing changes when the attempt no longer owns the job.
	 * @return ?string the job's new status (queued or failed); null = not this attempt's job anymore
	 */
	public static function RecordFailure(int $jobId, int $fileId, int $attempt, int $maxAttempts, string $error): ?string {
		$error = self::Truncate($error);
		$db = Database::Instance();
		return $db->Transaction(function() use ($db, $jobId, $fileId, $attempt, $maxAttempts, $error) {
			if(!self::LockIfOwned($jobId, $attempt)) return null;
			$status = $attempt >= $maxAttempts ? ProcessingJob::FAILED : ProcessingJob::QUEUED;
			$db->PrepareAndExecute(
				"UPDATE `processing_jobs` SET `status` = ?, `lease_until` = NULL, `error` = ? WHERE `id` = ?",
				["string", "string", "int"],
				[$status, $error, $jobId]
			);
			$db->PrepareAndExecute(
				"UPDATE `files` SET `processing_status` = ?, `processing_error` = ? WHERE `id` = ?",
				["string", "string", "int"],
				[$status === ProcessingJob::FAILED ? "failed" : "pending", $error, $fileId]
			);
			return $status;
		});
	}

	/**
	 * Jobs that can't run again: a running one whose lease ran out on its last attempt (the run
	 * was killed: a host's process watcher, a timeout outside the processor), or a queued one
	 * left with no attempts after `processing_max_attempts` was lowered. Both become failed,
	 * and so do their files. @return int how many
	 */
	public static function FailExhausted(int $maxAttempts): int {
		$db = Database::Instance();
		$rows = $db->QueryAll(
			"SELECT `id`, `file_id`, `attempts` FROM `processing_jobs` WHERE `attempts` >= ".$maxAttempts
			." AND (`status` = 'queued' OR (`status` = 'running' AND `lease_until` < NOW()))"
		);
		$failed = 0;
		foreach($rows as $row) {
			$jobId = (int)$row["id"];
			$attempts = (int)$row["attempts"];
			$error = "processing never finished: attempt ".$attempts." of ".$maxAttempts." stopped without a result (killed?)";
			$done = $db->Transaction(function() use ($db, $jobId, $row, $error) {
				$locked = $db->QueryRow("SELECT `status`, `lease_until` < NOW() AS `expired` FROM `processing_jobs` WHERE `id` = ".$jobId." FOR UPDATE");
				if(empty($locked)) return false;
				$claimable = $locked["status"] === ProcessingJob::QUEUED || ($locked["status"] === ProcessingJob::RUNNING && (int)$locked["expired"] === 1);
				if(!$claimable) return false;
				$db->PrepareAndExecute(
					"UPDATE `processing_jobs` SET `status` = 'failed', `lease_until` = NULL, `error` = ? WHERE `id` = ?",
					["string", "int"],
					[$error, $jobId]
				);
				$db->PrepareAndExecute(
					"UPDATE `files` SET `processing_status` = 'failed', `processing_error` = ? WHERE `id` = ?",
					["string", "int"],
					[$error, (int)$row["file_id"]]
				);
				return true;
			});
			if($done) $failed++;
		}
		return $failed;
	}

	/**
	 * Files left `pending` with no queued or running job: the upload died between inserting
	 * the row and queueing its job (the only moment a pending file has none). Older than
	 * $graceMinutes, they're failed, so nothing waits on them forever. @return int how many
	 */
	public static function FailOrphanedPending(int $graceMinutes = 10): int {
		$db = Database::Instance();
		$db->PrepareAndExecute(
			"UPDATE `files` f SET f.`processing_status` = 'failed', f.`processing_error` = ?"
			." WHERE f.`processing_status` = 'pending' AND f.`updated_at` < NOW() - INTERVAL ? MINUTE"
			." AND NOT EXISTS (SELECT 1 FROM `processing_jobs` j WHERE j.`file_id` = f.`id` AND j.`status` IN ('queued', 'running'))",
			["string", "int"],
			["processing was never queued (the upload was interrupted): regenerate the file", $graceMinutes]
		);
		return $db->LastAffectedRows();
	}

	/** Every queued or running job of the file stops counting: a newer one replaces it, or the file is going away. */
	public static function SupersedeForFile(int $fileId): void {
		Database::Instance()->PrepareAndExecute(
			"UPDATE `processing_jobs` SET `status` = 'superseded', `lease_until` = NULL WHERE `file_id` = ? AND `status` IN ('queued', 'running')",
			["int"],
			[$fileId]
		);
	}

	/**
	 * Before the file row is deleted (no FK cascades here). A worker in the middle of one of
	 * these jobs finds the row gone when it finishes, and deletes what it stored.
	 */
	public static function DeleteForFile(int $fileId): void {
		Database::Instance()->PrepareAndExecute(
			"DELETE FROM `processing_jobs` WHERE `file_id` = ?",
			["int"],
			[$fileId]
		);
	}

	/** Deletes finished jobs older than KEEP_FINISHED_DAYS. @return int how many */
	public static function PurgeFinished(): int {
		$db = Database::Instance();
		$db->Query(
			"DELETE FROM `processing_jobs` WHERE `status` IN ('done', 'failed', 'superseded')"
			." AND `updated_at` < NOW() - INTERVAL ".self::KEEP_FINISHED_DAYS." DAY"
		);
		return $db->LastAffectedRows();
	}

	/** @return array<string, int> job status => count, every status present */
	public static function CountsByStatus(): array {
		$counts = array_fill_keys([ProcessingJob::QUEUED, ProcessingJob::RUNNING, ProcessingJob::DONE, ProcessingJob::FAILED, ProcessingJob::SUPERSEDED], 0);
		foreach(Database::Instance()->QueryAll("SELECT `status`, COUNT(*) AS `n` FROM `processing_jobs` GROUP BY `status`") as $row) {
			$counts[$row["status"]] = (int)$row["n"];
		}
		return $counts;
	}

	/** The last failed jobs, newest first, with their file's name and key. @return array[] */
	public static function RecentFailures(int $limit = 20): array {
		return Database::Instance()->QueryAll(
			"SELECT j.`id`, j.`file_id`, j.`attempts`, j.`error`, j.`updated_at`, f.`name` AS `file_name`, f.`key_id`, f.`processing_status`"
			." FROM `processing_jobs` j JOIN `files` f ON f.`id` = j.`file_id`"
			." WHERE j.`status` = 'failed' ORDER BY j.`updated_at` DESC, j.`id` DESC LIMIT ".(int)$limit
		);
	}

	/** Fits `error` / `processing_error` (varchar 1000). */
	public static function Truncate(string $error): string {
		return mb_strlen($error) > 1000 ? mb_substr($error, 0, 999)."…" : $error;
	}

}
