<?php

namespace MagratheaExplorer\Processing;

use Magrathea\DB\Database;
use MagratheaExplorer\ExplorerConfig;

/**
 * `cron.php --process`: works through the queue, oldest job first, one at a time, within a
 * file count and a time budget (cron.php holds the `process` lock, so runs never overlap). A
 * job that fails goes back to the queue and is retried by a later run, never by the same one.
 *
 * Each run also cleans up: jobs whose last attempt died are failed (and so are files left
 * pending with no job at all, by an upload that died), finished jobs older than 30 days are
 * deleted, and job folders older than a day (left by runs killed before their `finally`) are
 * removed from processing_tmp_path.
 */
final class ProcessingQueue {

	const DEFAULT_MAX_FILES = 20;
	const DEFAULT_MAX_SECONDS = 50;
	/** A job folder this old belongs to no live run: the longest one is processor_timeout_s plus the lease margin. */
	const STALE_DIR_SECONDS = 86400;

	public static function MaxFiles(): int {
		return ExplorerConfig::GetPositiveInt("cron_process_max_files", self::DEFAULT_MAX_FILES);
	}

	public static function MaxSeconds(): int {
		return ExplorerConfig::GetPositiveInt("cron_process_max_s", self::DEFAULT_MAX_SECONDS);
	}

	/**
	 * @param ?int $maxFiles   jobs to run at most; null = cron_process_max_files
	 * @param ?int $maxSeconds no new job starts after this many seconds (the one in flight
	 *                         finishes, up to processor_timeout_s); null = cron_process_max_s
	 * @param ?callable $onJob fn(int $jobId, int $attempt, array $report) after each job (JobRunner::Run()'s report)
	 * @return array{ran: int, done: int, retry: int, failed: int, lost: int, exhausted: int, purged: int,
	 *               swept: int, stopped: ?string, configured: bool}
	 *         stopped: "max-files", "max-seconds" or null (queue empty). configured false = no
	 *         processor_command: nothing ran, the queue stays as it is.
	 */
	public static function Run(?int $maxFiles = null, ?int $maxSeconds = null, ?callable $onJob = null): array {
		$started = microtime(true);
		$maxFiles ??= self::MaxFiles();
		$maxSeconds ??= self::MaxSeconds();
		$maxAttempts = FileProcessing::MaxAttempts();
		$summary = ["ran" => 0, JobRunner::DONE => 0, JobRunner::RETRY => 0, JobRunner::FAILED => 0, JobRunner::LOST => 0,
			"exhausted" => 0, "purged" => 0, "swept" => 0, "stopped" => null, "configured" => true];

		$summary["swept"] = self::SweepJobDirs(ExplorerConfig::ProcessingTmpPath());
		$runner = ProcessorRunner::FromConfig();
		if($runner === null) {
			$summary["configured"] = false;
			return $summary;
		}
		$summary["exhausted"] = ProcessingJobControl::FailExhausted($maxAttempts) + ProcessingJobControl::FailOrphanedPending();
		$summary["purged"] = ProcessingJobControl::PurgeFinished();

		$lastId = 0;
		while(true) {
			if($summary["ran"] >= $maxFiles) { $summary["stopped"] = "max-files"; break; }
			if(microtime(true) - $started >= $maxSeconds) { $summary["stopped"] = "max-seconds"; break; }
			// a job can outlast MySQL's wait_timeout: each one starts on a fresh connection
			Database::Instance()->Disconnect();
			$jobId = ProcessingJobControl::NextClaimable($lastId, $maxAttempts);
			if($jobId === null) break;
			$lastId = $jobId;
			$attempt = ProcessingJobControl::Claim($jobId, JobRunner::LeaseSeconds($runner), $maxAttempts);
			if($attempt === null) continue; // taken in between (an inline run, a regenerate)
			$report = JobRunner::Run($jobId, $attempt, $runner, $maxAttempts);
			$summary["ran"]++;
			$summary[$report["outcome"]]++;
			if($onJob !== null) $onJob($jobId, $attempt, $report);
		}
		return $summary;
	}

	/**
	 * Removes `job-*` and `header-*` folders older than STALE_DIR_SECONDS. Only folders, only
	 * those names, never following a link. @return int how many were removed
	 */
	public static function SweepJobDirs(string $root): int {
		$removed = 0;
		$cutoff = time() - self::STALE_DIR_SECONDS;
		foreach(@scandir($root) ?: [] as $name) {
			if(preg_match('/^(job|header)-[A-Za-z0-9._-]+$/', $name) !== 1) continue;
			$path = $root."/".$name;
			if(is_link($path) || !is_dir($path)) continue;
			$mtime = @filemtime($path);
			if($mtime === false || $mtime >= $cutoff) continue;
			try {
				if(ProcessorJobDir::RemoveLeftover($name, $root)) $removed++;
			} catch(\Throwable $ex) {
				// a name ProcessorJobDir refuses: not one of ours, left alone
			}
		}
		return $removed;
	}

}
