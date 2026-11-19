<?php

namespace MagratheaExplorer\Storage;

use Magrathea\DB\Database;
use Magrathea\Logger;

/**
 * Storage deletes that may fail without failing the request: the objects a rotation replaced,
 * the renditions a processing run replaced, an upload whose row didn't commit. Each one is tried
 * now; one that fails goes into `storage_garbage`, and `cron.php --process` tries it again
 * (Retry()), up to MAX_ATTEMPTS times. Past that it stays in the table, and admin → Processing
 * lists it. Before that, an object no row pointed at any more was only logged, and stayed an
 * orphan until someone ran the Browser's orphan check.
 *
 * No model: one table, written and read here only.
 */
final class StorageGarbage {

	/** Paths retried per `--process` run. */
	const RETRY_PER_RUN = 100;
	/** After this many failed retries a path is left alone (and shown in the admin). */
	const MAX_ATTEMPTS = 20;

	/**
	 * Deletes each path now; the ones that fail are queued for a retry. Never throws: a queue
	 * that can't be written (the database is down) is logged instead.
	 * @param string[] $paths
	 * @return int how many were queued
	 */
	public static function Delete(StorageAdapter $storage, array $paths): int {
		$queued = 0;
		$paths = array_values(array_unique(array_filter(array_map("strval", $paths), fn($p) => $p !== "")));
		foreach($paths as $path) {
			try {
				$storage->delete($path);
			} catch(\Throwable $ex) {
				if(self::Queue($path, $ex->getMessage())) $queued++;
			}
		}
		return $queued;
	}

	/** Queues one path. A path already queued keeps its row (and attempts). false = not queued (logged). */
	public static function Queue(string $path, string $error): bool {
		$error = self::Truncate($error);
		try {
			Database::Instance()->PrepareAndExecute(
				"INSERT INTO `storage_garbage` (`storage_path`, `last_error`) VALUES (?, ?)"
				." ON DUPLICATE KEY UPDATE `last_error` = ?",
				["string", "string", "string"],
				[$path, $error, $error]
			);
			return true;
		} catch(\Throwable $queueError) {
			Logger::Instance()->Log("could not delete storage object ".$path." (".$error.") nor queue it for a retry: ".$queueError->getMessage());
			return false;
		}
	}

	/**
	 * `cron.php --process`: tries up to $limit queued paths again, least recently tried first, so
	 * paths that keep failing don't hold up the rest. A path some file or rendition row points at
	 * again is dropped from the queue without being deleted: the check is by token, which is
	 * unique and indexed in both tables.
	 * @return array{deleted: int, failed: int, dropped: int}
	 */
	public static function Retry(StorageAdapter $storage, int $limit = self::RETRY_PER_RUN): array {
		$db = Database::Instance();
		$summary = ["deleted" => 0, "failed" => 0, "dropped" => 0];
		$rows = $db->QueryAll(
			"SELECT `id`, `storage_path` FROM `storage_garbage` WHERE `attempts` < ".self::MAX_ATTEMPTS
			." ORDER BY `updated_at`, `id` LIMIT ".max(1, $limit)
		);
		foreach($rows as $row) {
			$id = (int)$row["id"];
			$path = (string)$row["storage_path"];
			if(self::StillReferenced($path)) {
				Logger::Instance()->Log("storage garbage: ".$path." is referenced again, dropped from the queue without deleting it");
				self::Forget($id);
				$summary["dropped"]++;
				continue;
			}
			try {
				$storage->delete($path);
			} catch(\Throwable $ex) {
				$db->PrepareAndExecute(
					"UPDATE `storage_garbage` SET `attempts` = `attempts` + 1, `last_error` = ? WHERE `id` = ?",
					["string", "int"],
					[self::Truncate($ex->getMessage()), $id]
				);
				$summary["failed"]++;
				continue;
			}
			self::Forget($id);
			$summary["deleted"]++;
		}
		return $summary;
	}

	/** Whether a file or a rendition still points at $path. Paths are `{storage_uuid}/{token}.{ext}`. */
	public static function StillReferenced(string $path): bool {
		$token = self::TokenOf($path);
		if($token === "") return false;
		$db = Database::Instance();
		$t = $db->Escape($token);
		$p = $db->Escape($path);
		return (int)$db->QueryOne(
			"SELECT (SELECT COUNT(*) FROM `files` WHERE `token` = '".$t."' AND `storage_path` = '".$p."')"
			." + (SELECT COUNT(*) FROM `file_renditions` WHERE `token` = '".$t."' AND `storage_path` = '".$p."')"
		) > 0;
	}

	/** The token in a storage path: its last segment, without the extension. */
	public static function TokenOf(string $path): string {
		$base = basename($path);
		$dot = strpos($base, "."); // tokens are [0-9A-Za-z]
		return $dot === false ? $base : substr($base, 0, $dot);
	}

	/** @return array{queued: int, stuck: int} stuck = MAX_ATTEMPTS failures, no longer retried */
	public static function Counts(): array {
		$row = Database::Instance()->QueryRow(
			"SELECT COUNT(*) AS `queued`, COALESCE(SUM(`attempts` >= ".self::MAX_ATTEMPTS."), 0) AS `stuck` FROM `storage_garbage`"
		);
		return ["queued" => (int)($row["queued"] ?? 0), "stuck" => (int)($row["stuck"] ?? 0)];
	}

	/** The paths no longer retried, most recent failure first. @return array[] */
	public static function Stuck(int $limit = 20): array {
		return Database::Instance()->QueryAll(
			"SELECT `id`, `storage_path`, `attempts`, `last_error`, `created_at`, `updated_at` FROM `storage_garbage`"
			." WHERE `attempts` >= ".self::MAX_ATTEMPTS." ORDER BY `updated_at` DESC, `id` DESC LIMIT ".max(1, $limit)
		);
	}

	private static function Forget(int $id): void {
		Database::Instance()->PrepareAndExecute("DELETE FROM `storage_garbage` WHERE `id` = ?", ["int"], [$id]);
	}

	/** Fits `last_error` (varchar 1000). */
	private static function Truncate(string $error): string {
		return mb_strlen($error) > 1000 ? mb_substr($error, 0, 999)."…" : $error;
	}

}
