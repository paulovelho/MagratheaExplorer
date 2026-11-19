<?php

namespace MagratheaExplorer\File;

use Magrathea\DB\Database;
use Magrathea\MagratheaModelControl;

class FileRenditionControl extends MagratheaModelControl {
	protected static $model = FileRendition::class;

	/** @return FileRendition[] by name */
	public static function GetForFile(int $fileId): array {
		return self::ByName(self::RunQuery(
			"SELECT * FROM `file_renditions` WHERE `file_id` = ".$fileId." ORDER BY `id`"
		));
	}

	/**
	 * Every rendition of these files in one query (a listing would otherwise run one per file).
	 * @param int[] $fileIds
	 * @return array<int, array<string, FileRendition>> file id => name => rendition; files with
	 *         none are absent
	 */
	public static function GetForFiles(array $fileIds): array {
		$ids = array_values(array_unique(array_map("intval", $fileIds)));
		if($ids === []) return [];
		$rows = self::RunQuery(
			"SELECT * FROM `file_renditions` WHERE `file_id` IN (".implode(",", $ids).") ORDER BY `id`"
		);
		$byFile = [];
		foreach($rows as $rendition) {
			$byFile[(int)$rendition->file_id][(string)$rendition->name] = $rendition;
		}
		return $byFile;
	}

	public static function DeleteForFile(int $fileId): void {
		Database::Instance()->PrepareAndExecute(
			"DELETE FROM `file_renditions` WHERE `file_id` = ?",
			["int"],
			[$fileId]
		);
	}

	/** @param FileRendition[] $renditions @return array<string, FileRendition> */
	private static function ByName(array $renditions): array {
		$byName = [];
		foreach($renditions as $rendition) {
			$byName[(string)$rendition->name] = $rendition;
		}
		return $byName;
	}

}
