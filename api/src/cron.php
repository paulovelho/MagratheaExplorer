<?php
// CLI-only entrypoint. Bootstrap shape mirrors Images3's backup.php (chdir + require
// _inc.php + getopt), but the flags are this script's own:
//   --run / --dry-run      sweep scheduled_deletions (daily)
//   --backfill-sha256      fill files.sha256 on files from before 1.5.0 (one-off, or cron until done)
//   --process              run queued processing jobs: properties and renditions (every minute)
//   --regenerate --key=ID  queue new renditions for a key's images (or one folder's: --folder=ID)
chdir(__DIR__);
require "_inc.php";

use MagratheaExplorer\AuthRateLimit;
use MagratheaExplorer\CronLock;
use MagratheaExplorer\ExplorerConfig;
use MagratheaExplorer\File\File;
use MagratheaExplorer\File\FileHashBackfill;
use MagratheaExplorer\File\SafeFetcher;
use MagratheaExplorer\File\UploadTokenControl;
use MagratheaExplorer\Folder\FolderDeletion;
use MagratheaExplorer\Idempotency;
use MagratheaExplorer\IdempotencyDbStore;
use MagratheaExplorer\Key\ScheduledDeletionControl;
use MagratheaExplorer\Processing\FileProcessing;
use MagratheaExplorer\Processing\ProcessingQueue;
use MagratheaExplorer\Storage\StorageFactory;
use MagratheaExplorer\Storage\StorageGarbage;

const USAGE = "Usage: php cron.php --run [--verbose]  |  php cron.php --dry-run [--verbose]\n"
	."       php cron.php --backfill-sha256 [--max-files=N] [--max-seconds=T] [--verbose]\n"
	."       php cron.php --process [--max-files=N] [--max-seconds=T] [--verbose]\n"
	."       php cron.php --regenerate --key=ID [--folder=ID] [--verbose]\n";

$options = getopt("", ["run", "dry-run", "verbose", "backfill-sha256", "process", "regenerate", "key:", "folder:", "max-files:", "max-seconds:"]);
$run = isset($options["run"]);
$dryRun = isset($options["dry-run"]);
$backfill = isset($options["backfill-sha256"]);
$process = isset($options["process"]);
$regenerate = isset($options["regenerate"]);
$verbose = isset($options["verbose"]);

// exactly one mode (--run and --dry-run together count as one: --dry-run wins, as before)
$modes = (int)($run || $dryRun) + (int)$backfill + (int)$process + (int)$regenerate;
if($modes !== 1) {
	fwrite(STDERR, USAGE);
	exit(1);
}

if($backfill) {
	exit(BackfillSha256($options, $verbose));
}
if($process) {
	exit(ProcessQueue($options, $verbose));
}
if($regenerate) {
	exit(Regenerate($options, $verbose));
}

if($dryRun) {
	$due = ScheduledDeletionControl::GetDue();
	echo count($due)." key(s) due for deletion.\n";
	if($verbose) {
		foreach($due as $deletion) {
			echo "  key_id=".$deletion->key_id." execute_at=".$deletion->execute_at."\n";
		}
	}
	exit(0);
}

$result = ScheduledDeletionControl::RunDueDeletions();
echo count($result["deleted"])." key(s) deleted, ".count($result["failed"])." failure(s).\n";
if($verbose) {
	foreach($result["deleted"] as $keyId) {
		echo "  deleted key_id=".$keyId."\n";
	}
	foreach($result["failed"] as $keyId => $message) {
		echo "  FAILED key_id=".$keyId.": ".$message."\n";
	}
}
exit(count($result["failed"]) > 0 ? 1 : 0);

/** Exit code: 0 done or nothing to do (or another backfill holds the lock), 1 any file failed. */
function BackfillSha256(array $options, bool $verbose): int {
	$maxFiles = PositiveIntOption($options, "max-files");
	$maxSeconds = PositiveIntOption($options, "max-seconds");

	$onFile = function(File $file, ?string $sha256, ?string $error) use ($verbose) {
		if($error !== null) {
			echo "  FAILED file_id=".$file->id." (".$file->storage_path."): ".$error."\n";
		} elseif($verbose) {
			echo "  file_id=".$file->id." ".$sha256."\n";
		}
	};
	$lock = null;
	try {
		// Its own lock, not the processing queue's: a long backfill must not hold up uploads.
		$lock = CronLock::TryAcquire("backfill-sha256");
		if($lock === null) {
			echo "Another --backfill-sha256 run holds the lock: nothing to do.\n";
			return 0;
		}
		$result = FileHashBackfill::Run($maxFiles, $maxSeconds, $onFile);
	} catch(\Throwable $ex) {
		// config, storage driver or database: no file can be hashed, so stop here
		fwrite(STDERR, "--backfill-sha256 failed: ".$ex->getMessage()."\n");
		return 1;
	} finally {
		$lock?->Release();
	}

	$stopped = match($result["stopped"]) {
		"max-files" => " Stopped at --max-files.",
		"max-seconds" => " Stopped at --max-seconds.",
		default => "",
	};
	echo $result["hashed"]." file(s) hashed, ".count($result["failed"])." failure(s), "
		.$result["remaining"]." still without a hash.".$stopped."\n";
	return count($result["failed"]) > 0 ? 1 : 0;
}

/**
 * Exit code: 0 the queue ran (or another --process holds the lock, or there's no processor),
 * 1 the run itself failed (config, database). Jobs that fail are the queue's business, not the
 * exit code's: they're retried and end up on the admin's Processing page.
 * After the jobs, under the same lock, the housekeeping that isn't the queue's: old
 * `auth_failures` windows, Idempotency-Key records past their retention, `source_url`
 * downloads a killed request left in `processing_tmp_path`, and storage deletes that failed
 * (StorageGarbage, 100 a run), upload tokens a week past their expiry, and folders a recursive
 * delete left for later (FolderDeletion, `folder_delete_max_s` a run). It runs with no processor
 * too.
 */
function ProcessQueue(array $options, bool $verbose): int {
	$maxFiles = PositiveIntOption($options, "max-files");
	$maxSeconds = PositiveIntOption($options, "max-seconds");
	$onJob = function(int $jobId, int $attempt, array $report) use ($verbose) {
		$line = "  job ".$jobId." attempt ".$attempt.": ".$report["outcome"]
			.($report["status"] !== null ? " (file ".$report["status"].")" : "")
			." in ".number_format($report["seconds"], 1)." s";
		if($report["error"] !== null) $line .= ": ".$report["error"];
		if($verbose || $report["outcome"] !== "done" || $report["error"] !== null) echo $line."\n";
	};
	$lock = null;
	try {
		// Every minute from cron: a run still going makes this one a no-op.
		$lock = CronLock::TryAcquire("process");
		if($lock === null) {
			if($verbose) echo "Another --process run holds the lock: nothing to do.\n";
			return 0;
		}
		$result = ProcessingQueue::Run($maxFiles, $maxSeconds, $onJob);
		$failuresPurged = AuthRateLimit::Purge();
		$idempotencyPurged = IdempotencyDbStore::Purge(Idempotency::RetentionHours());
		$downloadsSwept = SafeFetcher::SweepStale(ExplorerConfig::ProcessingTmpPath());
		$garbage = StorageGarbage::Retry(StorageFactory::Instance()->Get());
		$tokensPurged = UploadTokenControl::Purge();
		$sweep = FolderDeletion::Sweep();
	} catch(\Throwable $ex) {
		fwrite(STDERR, "--process failed: ".$ex->getMessage()."\n");
		return 1;
	} finally {
		$lock?->Release();
	}

	if(!$result["configured"]) {
		$lines = [];
		if($verbose) $lines[] = "No processor_command in magrathea.conf: nothing processed.";
		if($verbose || $failuresPurged > 0) $lines[] = $failuresPurged." old failed-authentication window(s) purged.";
		if($verbose || $idempotencyPurged > 0) $lines[] = $idempotencyPurged." expired Idempotency-Key record(s) purged.";
		if($verbose || $downloadsSwept > 0) $lines[] = $downloadsSwept." abandoned source_url download(s) removed.";
		if($verbose || $garbage["deleted"] + $garbage["failed"] + $garbage["dropped"] > 0) $lines[] = GarbageLine($garbage).".";
		if($verbose || $tokensPurged > 0) $lines[] = $tokensPurged." old upload token(s) purged.";
		if($verbose || SweepWorked($sweep)) $lines[] = SweepLine($sweep).".";
		if($lines !== []) echo implode(" ", $lines)."\n";
		return 0;
	}
	$stopped = match($result["stopped"]) {
		"max-files" => " Stopped at --max-files.",
		"max-seconds" => " Stopped at --max-seconds.",
		default => "",
	};
	$housekeeping = [];
	if($result["exhausted"] > 0) $housekeeping[] = $result["exhausted"]." interrupted file(s) failed";
	if($result["purged"] > 0) $housekeeping[] = $result["purged"]." old job(s) purged";
	if($result["swept"] > 0) $housekeeping[] = $result["swept"]." stale job folder(s) removed";
	if($failuresPurged > 0) $housekeeping[] = $failuresPurged." old failed-authentication window(s) purged";
	if($idempotencyPurged > 0) $housekeeping[] = $idempotencyPurged." expired Idempotency-Key record(s) purged";
	if($downloadsSwept > 0) $housekeeping[] = $downloadsSwept." abandoned source_url download(s) removed";
	if($garbage["deleted"] + $garbage["failed"] + $garbage["dropped"] > 0) $housekeeping[] = GarbageLine($garbage);
	if($tokensPurged > 0) $housekeeping[] = $tokensPurged." old upload token(s) purged";
	if(SweepWorked($sweep)) $housekeeping[] = SweepLine($sweep);
	// Quiet when there was nothing to do: cron mails (or logs) every line.
	if($verbose || $result["ran"] > 0 || $housekeeping !== []) {
		echo $result["ran"]." job(s) run: ".$result["done"]." done, ".$result["retry"]." to retry, "
			.$result["failed"]." failed, ".$result["lost"]." superseded or deleted.".$stopped
			.($housekeeping !== [] ? " ".implode("; ", $housekeeping)."." : "")."\n";
	}
	return 0;
}

function SweepWorked(array $sweep): bool {
	return $sweep["files"] + $sweep["failed"] + $sweep["folders"] > 0;
}

function SweepLine(array $sweep): string {
	return "recursive folder deletes: ".$sweep["files"]." file(s) and ".$sweep["folders"]." folder(s) deleted"
		.($sweep["failed"] > 0 ? ", ".$sweep["failed"]." file(s) failed" : "")
		.($sweep["remaining"] > 0 ? ", ".$sweep["remaining"]." folder(s) left" : "");
}

function GarbageLine(array $garbage): string {
	return "storage deletes retried: ".$garbage["deleted"]." done, ".$garbage["failed"]." failed again"
		.($garbage["dropped"] > 0 ? ", ".$garbage["dropped"]." in use again (kept)" : "");
}

/** Exit code: 0 every image queued, 1 a bad argument, no processor, or any file failed. */
function Regenerate(array $options, bool $verbose): int {
	$keyId = PositiveIntOption($options, "key");
	if($keyId === null) {
		fwrite(STDERR, "--regenerate needs --key=ID (the key's id in the admin)\n".USAGE);
		return 1;
	}
	$folderId = PositiveIntOption($options, "folder");
	try {
		$key = \MagratheaExplorer\Key\KeyControl::GetRowWhere(["id" => $keyId]);
		if($key === null) {
			fwrite(STDERR, "No key with id ".$keyId."\n");
			return 1;
		}
		if($folderId !== null && \MagratheaExplorer\Folder\FolderControl::GetRowWhere(["id" => $folderId, "key_id" => $keyId]) === null) {
			fwrite(STDERR, "Key ".$keyId." has no folder with id ".$folderId."\n");
			return 1;
		}
		$onFile = function(File $file, ?string $error) use ($verbose) {
			if($error !== null) {
				echo "  FAILED file_id=".$file->id." (".$file->name."): ".$error."\n";
			} elseif($verbose) {
				echo "  file_id=".$file->id." queued\n";
			}
		};
		$result = FileProcessing::RegenerateMany($keyId, $folderId, $onFile);
	} catch(\Throwable $ex) {
		fwrite(STDERR, "--regenerate failed: ".$ex->getMessage()."\n");
		return 1;
	}
	echo $result["queued"]." image(s) queued for new renditions, ".count($result["failed"])." failure(s). `cron.php --process` makes them.\n";
	return count($result["failed"]) > 0 ? 1 : 0;
}

function PositiveIntOption(array $options, string $name): ?int {
	if(!isset($options[$name])) {
		// getopt() drops a value option given without its value: `--max-files` alone would
		// silently mean "no limit"
		if(preg_grep('/^--'.preg_quote($name, '/').'(=|$)/', $GLOBALS["argv"])) {
			fwrite(STDERR, "--".$name." takes a positive integer\n".USAGE);
			exit(1);
		}
		return null;
	}
	$value = $options[$name];
	if(!is_string($value) || preg_match('/^\d+$/', $value) !== 1 || (int)$value < 1) {
		fwrite(STDERR, "--".$name." takes a positive integer\n".USAGE);
		exit(1);
	}
	return (int)$value;
}
