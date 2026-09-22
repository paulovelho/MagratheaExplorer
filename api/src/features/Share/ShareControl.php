<?php

namespace MagratheaExplorer\Share;

use Magrathea2\DB\Database;
use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\File\File;
use MagratheaExplorer\Folder\Folder;
use MagratheaExplorer\Key\Key;

class ShareControl extends \MagratheaExplorer\Share\Base\ShareControlBase {

	/**
	 * Mints a share for exactly one target. Both-or-neither is a 4001 -- the DB's
	 * chk_share_target constraint says the same thing, but this is the error the caller
	 * gets to read.
	 *
	 * Every column is set explicitly: the framework writes each declared dbValues field
	 * on Insert() and never falls back to the column's SQL DEFAULT (see Key::Normalize()).
	 * `uuid` is the exception -- it's declared "uuid" in ShareBase, so Insert() mints it
	 * and writes it back onto the object.
	 */
	public static function Create(Key $key, ?File $file, ?Folder $folder): Share {
		if(($file === null) === ($folder === null)) {
			ErrorCodes::Instance()->ThrowException(4001, null, "file_uuid or folder_uuid (exactly one)");
		}
		$share = new Share();
		$share->key_id = $key->id;
		$share->file_id = $file !== null ? $file->id : null;
		$share->folder_id = $folder !== null ? $folder->id : null;
		$share->views = 0;
		$share->last_viewed_at = null;
		$share->Insert();
		return $share;
	}

	/**
	 * The entire access check for every public route: holding the uuid IS the credential.
	 * Called first by every PublicShareApi action; nothing else there touches auth.
	 *
	 * A share dies with its key -- an inactive/expired key, or one with a pending
	 * scheduled deletion, collapses to the same 4045 as an unknown uuid so a visitor
	 * learns nothing about the owner's account state.
	 */
	public static function ResolveFromUuid(string $uuid): Share {
		if(empty($uuid)) {
			ErrorCodes::Instance()->ThrowException(4045);
		}
		$share = self::GetRowWhere(["uuid" => $uuid]);
		if($share === null) {
			ErrorCodes::Instance()->ThrowException(4045);
		}
		try {
			$key = new Key($share->key_id);
			$key->AssertUsable();
			$usable = !$key->HasPendingScheduledDeletion();
		} catch(\Throwable $ex) {
			$usable = false;
		}
		if(!$usable) {
			ErrorCodes::Instance()->ThrowException(4045);
		}
		return $share;
	}

	/** Owner-scoped lookup for DELETE /share/:uuid -- another key's share 404s, not 403s. */
	public static function GetForKeyByUuid(Key $key, string $uuid): Share {
		$share = self::GetRowWhere(["uuid" => $uuid, "key_id" => $key->id]);
		if($share === null) {
			ErrorCodes::Instance()->ThrowException(4045);
		}
		return $share;
	}

	/**
	 * Atomic `views = views + 1`, same reasoning as Key::IncrementUses() -- a popular link
	 * can be loaded concurrently and a read-modify-write would lose counts. Called only
	 * after a public response is successfully assembled, never on a 404.
	 */
	public static function RegisterView(Share $share): void {
		$now = \Magrathea2\now();
		Database::Instance()->PrepareAndExecute(
			"UPDATE `shares` SET `views` = `views` + 1, `last_viewed_at` = ? WHERE `id` = ?",
			["string", "int"],
			[$now, (int)$share->id]
		);
		$share->views = ((int)$share->views) + 1;
		$share->last_viewed_at = $now;
	}

	public static function Delete(Share $share): void {
		$share->Delete();
	}

	/**
	 * The three lifecycle helpers below exist because no FK in this schema is
	 * ON DELETE CASCADE (see KeyControl::DeleteKeyNow()): a shares row would otherwise
	 * block deleting its own target. Every delete path calls the matching one FIRST --
	 * see plan_share.md §5 for the full list of call sites. Unconditional single
	 * statements: no row is not an error.
	 */
	public static function DeleteForFile(int $fileId): void {
		Database::Instance()->PrepareAndExecute(
			"DELETE FROM `shares` WHERE `file_id` = ?", ["int"], [$fileId]);
	}

	public static function DeleteForFolder(int $folderId): void {
		Database::Instance()->PrepareAndExecute(
			"DELETE FROM `shares` WHERE `folder_id` = ?", ["int"], [$folderId]);
	}

	public static function DeleteForKey(int $keyId): void {
		Database::Instance()->PrepareAndExecute(
			"DELETE FROM `shares` WHERE `key_id` = ?", ["int"], [$keyId]);
	}

}
