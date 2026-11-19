<?php

namespace MagratheaExplorer\Folder;

use Magrathea\DB\Database;
use Magrathea\Logger;
use MagratheaExplorer\ExplorerConfig;
use MagratheaExplorer\File\FileControl;
use MagratheaExplorer\Key\Key;
use MagratheaExplorer\Share\ShareControl;

/**
 * DELETE /folder/:uuid?recursive=1: a folder and everything under it.
 *
 *   1. the subtree is found with WITH RECURSIVE (as FolderControl::ComputeSize());
 *   2. every folder in it is marked (`deleting_at`), in one statement, and the top one is
 *      renamed to a tombstone so its name is free in its parent at once. From then on the whole
 *      subtree is gone for the API: lookups (FolderControl::GetForKey*()), listings, public
 *      shares and uploads into it all see 404. Each check is one condition on the folder's own
 *      row: nothing walks up the tree;
 *   3. a subtree of at most `folder_delete_inline_files` files (200) is deleted now, files first
 *      (FileControl::Delete(): storage, renditions, shares, jobs, and the key's `total_size`),
 *      then the folders, deepest first;
 *   4. a bigger one, or one where a storage delete failed, is left to `cron.php --process`
 *      (Sweep()), which deletes what it can within `folder_delete_max_s` (15 s) per run, until
 *      no marked folder is left.
 *
 * Files keep resolving by uuid (GET /file/:uuid, the batch lookup) until the sweep deletes them:
 * checking the folder on every file lookup would cost a join each time. Key deletion
 * (KeyControl::DeleteKeyNow()) deletes everything anyway, marked or not.
 */
final class FolderDeletion {

	const DEFAULT_INLINE_FILES = 200;
	const DEFAULT_MAX_SECONDS = 15;
	/** The tombstone name's prefix. Names are unique per parent; the uuid makes it unique too. */
	const TOMBSTONE_PREFIX = ".deleting-";

	public static function InlineFiles(): int {
		return ExplorerConfig::GetPositiveInt("folder_delete_inline_files", self::DEFAULT_INLINE_FILES);
	}

	public static function MaxSeconds(): int {
		return ExplorerConfig::GetPositiveInt("folder_delete_max_s", self::DEFAULT_MAX_SECONDS);
	}

	/**
	 * Steps 1 to 4. The root folder is 4005, as without `recursive`.
	 * @return bool true = deleted now; false = pending, the sweep finishes it
	 */
	public static function Delete(Key $key, Folder $folder): bool {
		FolderControl::AssertNotRoot($folder);
		$inlineFiles = self::InlineFiles(); // read first: a bad value fails before anything is marked
		$subtree = self::Subtree((int)$folder->id);
		self::Mark($folder, $subtree["ids"]);
		if($subtree["files"] > $inlineFiles) return false;
		return self::DeleteMarked($key, $subtree["ids"]);
	}

	/**
	 * The folder and everything under it: ids deepest first (the order folders can be deleted
	 * in), and how many files they hold.
	 * @return array{ids: int[], files: int}
	 */
	public static function Subtree(int $folderId): array {
		$db = Database::Instance();
		$rows = $db->QueryAll(
			"WITH RECURSIVE subtree AS ("
			."SELECT id, 0 AS depth FROM folders WHERE id = ".$folderId
			." UNION ALL "
			."SELECT f.id, s.depth + 1 FROM folders f INNER JOIN subtree s ON f.parent_id = s.id WHERE s.depth < 255"
			.") SELECT id, depth FROM subtree ORDER BY depth DESC, id"
		);
		$ids = array_values(array_unique(array_map(fn($r) => (int)$r["id"], $rows)));
		if($ids === []) return ["ids" => [], "files" => 0];
		$files = (int)$db->QueryOne("SELECT COUNT(*) FROM files WHERE folder_id IN (".implode(",", $ids).")");
		return ["ids" => $ids, "files" => $files];
	}

	/** Step 2, in one transaction: the whole subtree marked, the top folder's name freed. */
	public static function Mark(Folder $top, array $ids): void {
		if($ids === []) return;
		$db = Database::Instance();
		$db->Transaction(function() use ($db, $top, $ids) {
			$db->Query("UPDATE `folders` SET `deleting_at` = NOW() WHERE `deleting_at` IS NULL AND `id` IN (".implode(",", array_map("intval", $ids)).")");
			$db->PrepareAndExecute(
				"UPDATE `folders` SET `name` = ? WHERE `id` = ?",
				["string", "int"],
				[self::TOMBSTONE_PREFIX.$top->uuid, (int)$top->id]
			);
		});
		$top->deleting_at = \Magrathea\now();
	}

	/**
	 * Step 3 on a marked subtree: its files, then its folders, deepest first. A file whose storage
	 * delete fails stays (the sweep retries it), and then its folders stay too.
	 * @param int[] $ids deepest first
	 * @return bool whether everything is gone
	 */
	public static function DeleteMarked(Key $key, array $ids): bool {
		if($ids === []) return true;
		$complete = true;
		$files = FileControl::GetSimpleWhere("`folder_id` IN (".implode(",", array_map("intval", $ids)).")");
		foreach($files as $file) {
			try {
				FileControl::Delete($file, $key);
			} catch(\Throwable $ex) {
				Logger::Instance()->Log("recursive delete: file ".$file->id." stays for the sweep: ".$ex->getMessage());
				$complete = false;
			}
		}
		if(!$complete) return false;
		foreach($ids as $id) {
			if(!self::DeleteEmptyFolder($id)) return false;
		}
		return true;
	}

	/**
	 * `cron.php --process`: works on every marked folder, within $maxSeconds.
	 *   - a folder created under a marked one in the moment before it was marked is marked too;
	 *   - files in marked folders are deleted, oldest id first (a file that fails is skipped for
	 *     the rest of this run and retried by the next one);
	 *   - marked folders left empty, with no children, are deleted, leaves first, until none is.
	 * @return array{files: int, failed: int, folders: int, remaining: int} remaining: marked folders left
	 */
	public static function Sweep(?int $maxSeconds = null): array {
		$started = microtime(true);
		$maxSeconds ??= self::MaxSeconds();
		$db = Database::Instance();
		$summary = ["files" => 0, "failed" => 0, "folders" => 0, "remaining" => 0];
		$outOfTime = fn() => microtime(true) - $started >= $maxSeconds;

		if((int)$db->QueryOne("SELECT COUNT(*) FROM `folders` WHERE `deleting_at` IS NOT NULL") === 0) return $summary;

		// a child created just before its parent was marked: one level per statement
		for($level = 0; $level < 64; $level++) {
			$db->Query("UPDATE `folders` c INNER JOIN `folders` p ON c.`parent_id` = p.`id`"
				." SET c.`deleting_at` = NOW() WHERE p.`deleting_at` IS NOT NULL AND c.`deleting_at` IS NULL");
			if($db->LastAffectedRows() === 0) break;
		}

		$keys = [];
		$lastId = 0;
		while(!$outOfTime()) {
			$batch = FileControl::RunQuery(
				"SELECT * FROM `files` WHERE `id` > ".$lastId
				." AND `folder_id` IN (SELECT `id` FROM `folders` WHERE `deleting_at` IS NOT NULL) ORDER BY `id` LIMIT 100"
			);
			if($batch === []) break;
			foreach($batch as $file) {
				$lastId = (int)$file->id;
				if($outOfTime()) break 2;
				try {
					$key = $keys[(int)$file->key_id] ??= new Key($file->key_id);
					FileControl::Delete($file, $key);
					$summary["files"]++;
				} catch(\Throwable $ex) {
					Logger::Instance()->Log("recursive delete sweep: file ".$file->id." stays: ".$ex->getMessage());
					$summary["failed"]++;
				}
			}
		}

		while(!$outOfTime()) {
			$leaves = $db->QueryAll(
				"SELECT f.`id` FROM `folders` f WHERE f.`deleting_at` IS NOT NULL"
				." AND NOT EXISTS (SELECT 1 FROM `folders` c WHERE c.`parent_id` = f.`id`)"
				." AND NOT EXISTS (SELECT 1 FROM `files` x WHERE x.`folder_id` = f.`id`) LIMIT 500"
			);
			if($leaves === []) break;
			$progressed = false;
			foreach($leaves as $row) {
				if(self::DeleteEmptyFolder((int)$row["id"])) {
					$summary["folders"]++;
					$progressed = true;
				}
			}
			if(!$progressed) break;
		}
		$summary["remaining"] = (int)$db->QueryOne("SELECT COUNT(*) FROM `folders` WHERE `deleting_at` IS NOT NULL");
		return $summary;
	}

	/**
	 * A marked folder with no files and no children: its shares, then the row. false = it isn't
	 * empty (a file that failed, an upload that slipped in, a child): it waits for the sweep.
	 */
	private static function DeleteEmptyFolder(int $id): bool {
		$db = Database::Instance();
		$busy = (int)$db->QueryOne(
			"SELECT (SELECT COUNT(*) FROM `files` WHERE `folder_id` = ".$id.")"
			." + (SELECT COUNT(*) FROM `folders` WHERE `parent_id` = ".$id.")"
		);
		if($busy > 0) return false;
		try {
			ShareControl::DeleteForFolder($id);
			$db->PrepareAndExecute("DELETE FROM `folders` WHERE `id` = ? AND `deleting_at` IS NOT NULL", ["int"], [$id]);
		} catch(\Magrathea\Exceptions\MagratheaDBException $ex) {
			// a file or a child arrived after the count: the FK refused the delete. Next pass.
			Logger::Instance()->Log("recursive delete: folder ".$id." isn't empty yet: ".$ex->getMessage());
			return false;
		}
		return true;
	}

}
