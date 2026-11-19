<?php

namespace MagratheaExplorer\File;

use Magrathea\MagratheaModelControl;
use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\Key\Key;
use MagratheaExplorer\Processing\ProcessingJobControl;
use MagratheaExplorer\Share\ShareControl;
use MagratheaExplorer\Storage\StorageFactory;

class FileControl extends MagratheaModelControl {
	protected static $model = File::class;

	/**
	 * Scoped lookup: a file belonging to another key 404s (not 403), same reasoning as
	 * FolderControl::GetForKey().
	 *
	 * Int-id lookup, kept for BrowserAdmin::DeleteFile() -- the admin stays on int ids
	 * (see plan.md §0). Public API controllers use GetForKeyByUuid() instead.
	 */
	public static function GetForKey(Key $key, int $id): File {
		$file = self::GetRowWhere(["id" => $id, "key_id" => $key->id]);
		if($file === null) {
			ErrorCodes::Instance()->ThrowException(4043);
		}
		return $file;
	}

	/** Uuid counterpart of GetForKey() -- the public API's lookup path. Same 404 reasoning. */
	public static function GetForKeyByUuid(Key $key, string $uuid): File {
		$file = self::GetRowWhere(["uuid" => $uuid, "key_id" => $key->id]);
		if($file === null) {
			ErrorCodes::Instance()->ThrowException(4043);
		}
		return $file;
	}

	/**
	 * Deletes the storage object and every rendition's, the processing jobs, the rendition
	 * rows, tags and shares, then the row. Does NOT adjust the owning key's `total_size` -- used
	 * both by the normal single-file delete path (which does adjust it, see Delete()) and by
	 * KeyControl::DeleteKeyNow() (where the key row itself is about to be deleted, so
	 * adjusting its size would be pointless work). Every delete path goes through here.
	 * @return bool whether this call deleted the row: false when a concurrent delete (another
	 *              request, the recursive-delete sweep) got there first
	 */
	public static function DeleteFileAndStorage(File $file): bool {
		// Jobs first: a worker still running one finds its job gone when it tries to commit, and
		// deletes what it stored. One that committed before this line has its rendition rows in
		// place, read just below.
		ProcessingJobControl::DeleteForFile((int)$file->id);
		$renditions = FileRenditionControl::GetForFile((int)$file->id);
		$storage = StorageFactory::Instance()->Get();
		try {
			$storage->delete($file->storage_path);
			foreach($renditions as $rendition) {
				$storage->delete($rendition->storage_path);
			}
		} catch(\Throwable $ex) {
			ErrorCodes::Instance()->ThrowException(5001, null, $ex->getMessage());
		}
		// no FK cascades: the rendition rows would block the row delete
		FileRenditionControl::DeleteForFile((int)$file->id);
		FileTagControl::DetachAllForFile((int)$file->id);
		// shares.file_id is a plain FK (no ON DELETE CASCADE anywhere in this schema), so a
		// live share row would make $file->Delete() below fail -- after the storage object
		// is already gone, leaving exactly the orphan state BrowserAdmin::OrphanCheck()
		// exists to hunt down. Must come before the row delete, not after.
		ShareControl::DeleteForFile((int)$file->id);
		$file->Delete();
		return \Magrathea\DB\Database::Instance()->LastAffectedRows() === 1;
	}

	/**
	 * Normal owner-initiated delete: also decrements the key's live storage total, once: of two
	 * deletes of the same file racing each other, only the one whose row delete hit decrements it
	 * (before, both did, and `total_size` drifted low).
	 */
	public static function Delete(File $file, Key $key): void {
		if(self::DeleteFileAndStorage($file)) $key->AdjustSize(-1 * (int)$file->size);
	}

}
