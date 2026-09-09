<?php

namespace MagratheaExplorer\Folder;

use Magrathea2\DB\Database;
use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\Key\Key;

class FolderControl extends \MagratheaExplorer\Folder\Base\FolderControlBase {

	public static function CreateRoot(Key $key): Folder {
		$folder = new Folder();
		$folder->key_id = $key->id;
		$folder->parent_id = null;
		$folder->name = "root";
		$folder->Insert();
		return $folder;
	}

	public static function GetRoot(Key $key): Folder {
		$root = self::GetRowWhere(["key_id" => $key->id, "parent_id" => null]);
		if($root === null) {
			ErrorCodes::Instance()->ThrowException(5004, null, "missing root folder for key");
		}
		return $root;
	}

	/**
	 * Scoped lookup: a folder belonging to another key 404s (not 403), so a caller can't
	 * distinguish "not yours" from "doesn't exist". Uses GetRowWhere (never-throwing) rather
	 * than `new Folder($id)` (which throws MagratheaModelException on a miss) so the 404 can
	 * carry our own error code.
	 */
	public static function GetForKey(Key $key, int $id): Folder {
		$folder = self::GetRowWhere(["id" => $id, "key_id" => $key->id]);
		if($folder === null) {
			ErrorCodes::Instance()->ThrowException(4042);
		}
		return $folder;
	}

	public static function Create(Key $key, ?int $parentId, string $name): Folder {
		$name = trim($name);
		if(empty($name)) {
			ErrorCodes::Instance()->ThrowException(4001, null, "name");
		}
		$parent = $parentId !== null ? self::GetForKey($key, $parentId) : self::GetRoot($key);
		$existing = self::GetRowWhere(["key_id" => $key->id, "parent_id" => $parent->id, "name" => $name]);
		if($existing !== null) {
			ErrorCodes::Instance()->ThrowException(4004);
		}
		$folder = new Folder();
		$folder->key_id = $key->id;
		$folder->parent_id = $parent->id;
		$folder->name = $name;
		$folder->Insert();
		return $folder;
	}

	/**
	 * Like Create(), but returns the existing folder instead of throwing 4004 when one
	 * with that (key_id, parent_id, name) already exists. Used by bulk import, which needs
	 * re-running the same import to reuse the folder tree it already created rather than
	 * erroring out on the second pass.
	 */
	public static function GetOrCreate(Key $key, ?int $parentId, string $name): Folder {
		$name = trim($name);
		if(empty($name)) {
			ErrorCodes::Instance()->ThrowException(4001, null, "name");
		}
		$parent = $parentId !== null ? self::GetForKey($key, $parentId) : self::GetRoot($key);
		$existing = self::GetRowWhere(["key_id" => $key->id, "parent_id" => $parent->id, "name" => $name]);
		if($existing !== null) {
			return $existing;
		}
		$folder = new Folder();
		$folder->key_id = $key->id;
		$folder->parent_id = $parent->id;
		$folder->name = $name;
		$folder->Insert();
		return $folder;
	}

	public static function Rename(Folder $folder, string $name): Folder {
		$name = trim($name);
		if(empty($name)) {
			ErrorCodes::Instance()->ThrowException(4001, null, "name");
		}
		$existing = self::GetRowWhere([
			"key_id" => $folder->key_id,
			"parent_id" => $folder->parent_id,
			"name" => $name,
		]);
		if($existing !== null && $existing->id != $folder->id) {
			ErrorCodes::Instance()->ThrowException(4004);
		}
		$folder->name = $name;
		$folder->Update();
		return $folder;
	}

	public static function AssertNotRoot(Folder $folder): void {
		if($folder->IsRoot()) {
			ErrorCodes::Instance()->ThrowException(4005);
		}
	}

	public static function AssertEmpty(Folder $folder): void {
		$children = self::GetSimpleWhere("`parent_id` = ".(int)$folder->id);
		if(count($children) > 0) {
			ErrorCodes::Instance()->ThrowException(4003);
		}
		$files = \MagratheaExplorer\File\Base\FileControlBase::GetSimpleWhere("`folder_id` = ".(int)$folder->id);
		if(count($files) > 0) {
			ErrorCodes::Instance()->ThrowException(4003);
		}
	}

	/**
	 * On-demand recursive-subtree SUM(size) -- always correct, no write overhead. Folder-size
	 * reporting isn't frequent enough to justify precomputing it (unlike Key::total_size,
	 * which is checked on every upload and kept as a running total instead).
	 */
	public static function ComputeSize(Folder $folder): int {
		$id = (int)$folder->id;
		$sql = "WITH RECURSIVE subtree AS (".
			"SELECT id FROM folders WHERE id = ".$id.
			" UNION ALL ".
			"SELECT f.id FROM folders f INNER JOIN subtree s ON f.parent_id = s.id".
			") SELECT COALESCE(SUM(size),0) FROM files WHERE folder_id IN (SELECT id FROM subtree)";
		return (int)Database::Instance()->QueryOne($sql);
	}

}
