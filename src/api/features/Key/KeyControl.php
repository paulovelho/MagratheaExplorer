<?php

namespace MagratheaExplorer\Key;

use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\Folder\FolderControl;
use MagratheaExplorer\Folder\Base\FolderControlBase;
use MagratheaExplorer\File\FileControl;
use MagratheaExplorer\File\Base\FileControlBase;

class KeyControl extends \MagratheaExplorer\Key\Base\KeyControlBase {

	/**
	 * Only creation path for a Key -- an authenticated admin action, never a public
	 * endpoint (see Key/KeyAdmin.php). Auto-creates the key's root folder.
	 */
	public static function Create(string $name): Key {
		$key = new Key();
		$key->name = $name;
		$key->Normalize();
		$key->Insert();
		FolderControl::CreateRoot($key);
		return $key;
	}

	/**
	 * Admin-only safe setter: touches only the operator-editable fields. Never `uuid`
	 * (the credential itself), `uses`/`total_size` (counters kept correct only by
	 * IncrementUses()/AdjustSize()'s atomic updates).
	 */
	public static function UpdateFields(Key $key, array $data): Key {
		if(isset($data["name"]) && $data["name"] !== "") $key->name = trim($data["name"]);
		if(array_key_exists("usage_limit", $data)) $key->usage_limit = $data["usage_limit"] !== "" ? (int)$data["usage_limit"] : null;
		if(array_key_exists("usage_limit_mb", $data)) $key->usage_limit_mb = $data["usage_limit_mb"] !== "" ? (int)$data["usage_limit_mb"] : null;
		if(array_key_exists("expiration", $data)) $key->expiration = $data["expiration"] !== "" ? $data["expiration"] : null;
		if(isset($data["active"])) $key->active = !empty($data["active"]) ? 1 : 0;
		$key->Update();
		return $key;
	}

	/**
	 * Resolves the bearer token from `Authorization: Bearer <uuid>` into a usable Key.
	 * Called independently by both the route's boolean auth gate (Key/KeyAuthControl.php)
	 * and the controller action itself (ExplorerApiControl::GetRequestKey()) -- the
	 * framework's declarative route auth doesn't thread identity through, so this is a
	 * cheap double lookup rather than one shared resolution.
	 */
	public static function ResolveFromBearer(?string $uuid): Key {
		if(empty($uuid)) {
			ErrorCodes::Instance()->ThrowException(4011, null, "missing bearer token");
		}
		$key = self::GetRowWhere(["uuid" => $uuid]);
		if(empty($key)) {
			ErrorCodes::Instance()->ThrowException(4041);
		}
		$key->AssertUsable();
		return $key;
	}

	/**
	 * Full cascading delete: every file (storage object + thumbnail + row), every folder,
	 * every scheduled-deletion row, then the key itself. No FK is ON DELETE CASCADE (plan.md's
	 * finalized schema doesn't use it), so deletion order matters: files before folders
	 * (files.folder_id FK), folders deepest-first (folders.parent_id self-FK), scheduled
	 * deletions and folders/files before the key row (their own key_id FK).
	 */
	public static function DeleteKeyNow(Key $key): void {
		$files = FileControlBase::GetWhere(["key_id" => $key->id]);
		foreach($files as $file) {
			FileControl::DeleteFileAndStorage($file);
		}

		self::DeleteFoldersDeepestFirst((int)$key->id);

		// Array-form GetWhere() unconditionally appends "ORDER BY created_at DESC", which
		// scheduled_deletions doesn't have (plan.md's finalized schema) -- GetSimpleWhere()
		// doesn't append it, so it's the safe form for this table.
		$deletions = \MagratheaExplorer\Key\Base\ScheduledDeletionControlBase::GetSimpleWhere("`key_id` = ".(int)$key->id);
		foreach($deletions as $deletion) {
			$deletion->Delete();
		}

		$key->Delete();
	}

	private static function DeleteFoldersDeepestFirst(int $keyId): void {
		$folders = FolderControlBase::GetWhere(["key_id" => $keyId]);
		while(count($folders) > 0) {
			$parentIds = array_filter(array_map(fn($f) => $f->parent_id, $folders));
			$progressed = false;
			foreach($folders as $idx => $folder) {
				if(!in_array($folder->id, $parentIds)) {
					$folder->Delete();
					unset($folders[$idx]);
					$progressed = true;
				}
			}
			if(!$progressed) break; // safety net: shouldn't happen against a valid tree
			$folders = array_values($folders);
		}
	}

}
