<?php

namespace MagratheaExplorer\File;

use Magrathea2\DB\Database;

/**
 * Plain static class over the `file_tags` join table -- no Model/Control pair, since
 * MagratheaModel assumes a single primary key and this table has a composite one
 * (file_id, tag_id). Talks to the DB directly via Query/PrepareAndExecute.
 */
class FileTagControl {

	public static function Attach(int $fileId, int $tagId): void {
		if(self::IsAttached($fileId, $tagId)) return;
		Database::Instance()->PrepareAndExecute(
			"INSERT INTO `file_tags` (`file_id`, `tag_id`) VALUES (?, ?)",
			["int", "int"],
			[$fileId, $tagId]
		);
	}

	public static function Detach(int $fileId, int $tagId): void {
		Database::Instance()->PrepareAndExecute(
			"DELETE FROM `file_tags` WHERE `file_id` = ? AND `tag_id` = ?",
			["int", "int"],
			[$fileId, $tagId]
		);
	}

	public static function DetachAllForFile(int $fileId): void {
		Database::Instance()->PrepareAndExecute(
			"DELETE FROM `file_tags` WHERE `file_id` = ?",
			["int"],
			[$fileId]
		);
	}

	public static function IsAttached(int $fileId, int $tagId): bool {
		$count = Database::Instance()->QueryOne(
			"SELECT COUNT(1) FROM `file_tags` WHERE `file_id` = ".$fileId." AND `tag_id` = ".$tagId
		);
		return (int)$count > 0;
	}

	/** @return Tag[] */
	public static function GetTagsForFile(int $fileId): array {
		$sql = "SELECT t.* FROM `tags` t INNER JOIN `file_tags` ft ON ft.tag_id = t.id WHERE ft.file_id = ".$fileId;
		$rows = Database::Instance()->QueryAll($sql);
		return array_map(function($row) {
			$tag = new Tag();
			$tag->LoadObjectFromTableRow($row);
			return $tag;
		}, $rows);
	}

	/** @return int[] ids of files carrying the given tag */
	public static function GetFileIdsForTag(int $tagId): array {
		$rows = Database::Instance()->QueryAll(
			"SELECT `file_id` FROM `file_tags` WHERE `tag_id` = ".$tagId
		);
		return array_map(fn($row) => (int)$row["file_id"], $rows);
	}

}
