<?php

namespace MagratheaExplorer\File;

use Magrathea\DB\Database;
use MagratheaExplorer\ExplorerConfig;
use MagratheaExplorer\Storage\StorageAdapter;
use MagratheaExplorer\Storage\StorageException;
use MagratheaExplorer\Storage\StorageFactory;

/**
 * `php cron.php --backfill-sha256`: fills `files.sha256` on rows from before 1.5.0. Each stored
 * object is fetched back from storage into processing_tmp_path, hashed and dropped, one file at
 * a time, oldest (lowest id) first. Disk use is one object (two on S3, see
 * S3StorageAdapter::fetch()); memory stays flat.
 *
 * A file that fails (object missing, size mismatch, storage down) keeps its null and is listed
 * in `failed`. The run moves past it, and the next run tries it again: failures don't count
 * toward $maxFiles, so a stubborn one can't stall the backfill.
 */
class FileHashBackfill {

	private const BATCH = 100;

	/**
	 * @param ?int $maxFiles     stop after this many files hashed; null = no limit
	 * @param ?int $maxSeconds   no new file is started after this many seconds (the one in
	 *                           flight finishes); null = no limit
	 * @param ?callable $onFile  fn(File $file, ?string $sha256, ?string $error), after each file
	 * @return array{hashed: int, failed: array<int, string>, remaining: int, stopped: ?string}
	 *         failed: file id => reason. stopped: "max-files", "max-seconds" or null (all done).
	 *         remaining: rows still without a hash, failed ones included.
	 */
	public static function Run(?int $maxFiles = null, ?int $maxSeconds = null, ?callable $onFile = null): array {
		$started = microtime(true);
		$storage = StorageFactory::Instance()->Get();
		$tmpDir = ExplorerConfig::ProcessingTmpPath();

		$hashed = 0;
		$failed = [];
		$stopped = null;
		$lastId = 0;

		while($stopped === null) {
			$batch = FileControl::RunQuery(
				"SELECT * FROM `files` WHERE `sha256` IS NULL AND `id` > ".(int)$lastId." ORDER BY `id` LIMIT ".self::BATCH
			);
			if(empty($batch)) break;

			foreach($batch as $file) {
				if($maxFiles !== null && $hashed >= $maxFiles) { $stopped = "max-files"; break; }
				if($maxSeconds !== null && microtime(true) - $started >= $maxSeconds) { $stopped = "max-seconds"; break; }
				$lastId = (int)$file->id;
				// A fetch can outlast MySQL's wait_timeout: the UPDATE below gets a fresh connection.
				Database::Instance()->Disconnect();

				$sha256 = null;
				$error = null;
				try {
					$sha256 = self::HashStoredObject($storage, $file, $tmpDir);
					// `updated_at = updated_at` keeps ON UPDATE from firing: the file didn't change.
					// `sha256 IS NULL`: never overwrites a hash that's already there.
					Database::Instance()->PrepareAndExecute(
						"UPDATE `files` SET `sha256` = ?, `updated_at` = `updated_at` WHERE `id` = ? AND `sha256` IS NULL",
						["string", "int"],
						[$sha256, (int)$file->id]
					);
					$hashed++;
				} catch(\Throwable $ex) {
					$sha256 = null;
					$error = $ex->getMessage();
					$failed[(int)$file->id] = $error;
				}
				if($onFile !== null) $onFile($file, $sha256, $error);
			}
		}

		$remaining = (int)FileControl::QueryOne("SELECT COUNT(*) FROM `files` WHERE `sha256` IS NULL");
		return ["hashed" => $hashed, "failed" => $failed, "remaining" => $remaining, "stopped" => $stopped];
	}

	/** @throws \RuntimeException|StorageException */
	private static function HashStoredObject(StorageAdapter $storage, File $file, string $tmpDir): string {
		$tmp = tempnam($tmpDir, "sha256-");
		if($tmp === false) {
			throw new \RuntimeException("could not create a temp file in ".$tmpDir);
		}
		try {
			$storage->fetch($file->storage_path, $tmp);
			clearstatcache(true, $tmp);
			$size = filesize($tmp);
			// files.size is what put() stored: a different length means the object isn't that
			// file anymore (or never was), and its hash would be a lie.
			if($size !== (int)$file->size) {
				throw new \RuntimeException("stored object is ".$size." bytes, the row says ".(int)$file->size);
			}
			$sha256 = hash_file("sha256", $tmp);
			if($sha256 === false) {
				throw new \RuntimeException("could not hash the fetched copy");
			}
			return $sha256;
		} finally {
			if(is_file($tmp)) @unlink($tmp);
		}
	}

}
