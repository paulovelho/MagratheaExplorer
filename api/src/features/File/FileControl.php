<?php

namespace MagratheaExplorer\File;

use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\Key\Key;
use MagratheaExplorer\Storage\StorageFactory;

class FileControl extends \MagratheaExplorer\File\Base\FileControlBase {

	/**
	 * Scoped lookup: a file belonging to another key 404s (not 403), same reasoning as
	 * FolderControl::GetForKey().
	 */
	public static function GetForKey(Key $key, int $id): File {
		$file = self::GetRowWhere(["id" => $id, "key_id" => $key->id]);
		if($file === null) {
			ErrorCodes::Instance()->ThrowException(4043);
		}
		return $file;
	}

	/**
	 * Deletes the storage object (+ thumbnail, if any), detaches tags, and deletes the
	 * row. Does NOT adjust the owning key's `total_size` -- used both by the normal
	 * single-file delete path (which does adjust it, see Delete()) and by
	 * KeyControl::DeleteKeyNow() (where the key row itself is about to be deleted, so
	 * adjusting its size would be pointless work).
	 */
	public static function DeleteFileAndStorage(File $file): void {
		$storage = StorageFactory::Instance()->Get();
		try {
			$storage->delete($file->storage_path);
			if($file->HasThumbnail()) {
				$storage->delete($file->thumbnail_token.".".self::ThumbnailExtension($file));
			}
		} catch(\Throwable $ex) {
			ErrorCodes::Instance()->ThrowException(5001, null, $ex->getMessage());
		}
		FileTagControl::DetachAllForFile((int)$file->id);
		$file->Delete();
	}

	/** Normal owner-initiated delete: also decrements the key's live storage total. */
	public static function Delete(File $file, Key $key): void {
		self::DeleteFileAndStorage($file);
		$key->AdjustSize(-1 * (int)$file->size);
	}

	/**
	 * Thumbnails are stored under their own token with an extension derived from the
	 * source file's format (png source -> png thumbnail, everything else -> jpg -- see
	 * ImageProcessor::BuildThumbnail()). Not persisted as its own column since the
	 * source extension already determines it deterministically.
	 */
	private static function ThumbnailExtension(File $file): string {
		return strtolower($file->extension) === "png" ? "png" : "jpg";
	}

}
