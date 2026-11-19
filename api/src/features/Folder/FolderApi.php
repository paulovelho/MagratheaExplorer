<?php

namespace MagratheaExplorer\Folder;

use MagratheaExplorer\ApiTime;
use MagratheaExplorer\ExplorerApiControl;
use MagratheaExplorer\RequestBool;
use MagratheaExplorer\Share\ShareControl;

class FolderApi extends ExplorerApiControl {

	/** GET /folders -- direct children of ?parent_uuid=, or the key's root folder's children when omitted */
	public function GetAll(): array {
		$key = $this->GetRequestKey();
		$parent = !empty($_GET["parent_uuid"]) ? FolderControl::GetForKeyByUuid($key, (string)$_GET["parent_uuid"]) : FolderControl::GetRoot($key);
		$folders = FolderControl::GetSimpleWhere("`key_id` = ".(int)$key->id." AND `parent_id` = ".(int)$parent->id." AND `deleting_at` IS NULL");
		return array_map(fn($folder) => $this->FolderView($folder, $parent), $folders);
	}

	/** POST /folders -- body: name (required), parent_uuid (optional, defaults to the key's root) */
	public function Create($data = false): array {
		$key = $this->GetRequestKey();
		$data = $this->GetPost();
		$parent = !empty($data["parent_uuid"]) ? FolderControl::GetForKeyByUuid($key, (string)$data["parent_uuid"]) : null;
		$folder = FolderControl::Create($key, $parent, $data["name"] ?? "");
		// Insert() leaves created_at null on the object: read it back for the response.
		return $this->FolderView($folder->Reload(), $parent);
	}

	/** GET /folder/:uuid */
	public function Get($params): array {
		$key = $this->GetRequestKey();
		$folder = FolderControl::GetForKeyByUuid($key, (string)$params["uuid"]);
		return $this->FolderView($folder);
	}

	/** PUT /folder/:uuid -- rename */
	public function Update($params): array {
		$key = $this->GetRequestKey();
		$folder = FolderControl::GetForKeyByUuid($key, (string)$params["uuid"]);
		$data = $this->GetPut();
		FolderControl::Rename($folder, $data["name"] ?? "");
		return $this->FolderView($folder);
	}

	/**
	 * DELETE /folder/:uuid -- the root folder can't be deleted (4005). Without `?recursive=1`, a
	 * folder that isn't empty can't either (4003). With it, the folder and everything under it
	 * (FolderDeletion): {"deleted": true} when it's all gone now, {"deleted": false, "pending":
	 * true} when `cron.php --process` finishes it. Either way the folder is gone for the API.
	 */
	public function Delete($params = false): array {
		$key = $this->GetRequestKey();
		$folder = FolderControl::GetForKeyByUuid($key, (string)$params["uuid"]);
		if(RequestBool::Parse($_GET, "recursive") === true) {
			return FolderDeletion::Delete($key, $folder) ? ["deleted" => true] : ["deleted" => false, "pending" => true];
		}
		FolderControl::AssertNotRoot($folder);
		FolderControl::AssertEmpty($folder);
		// An empty folder can still be shared -- drop its shares before the FK bites.
		ShareControl::DeleteForFolder((int)$folder->id);
		$folder->Delete();
		return ["deleted" => true];
	}

	/** GET /folder/:uuid/size -- recursive subtree byte total */
	public function GetSize($params): array {
		$key = $this->GetRequestKey();
		$folder = FolderControl::GetForKeyByUuid($key, (string)$params["uuid"]);
		return ["size" => FolderControl::ComputeSize($folder)];
	}

	/**
	 * $parent, when the caller already resolved it (GetAll()/Create()), skips a second
	 * lookup for parent_uuid; otherwise it's resolved from $folder->parent_id (null for
	 * the root folder, whose parent_id is NULL).
	 */
	private function FolderView(Folder $folder, ?Folder $parent = null): array {
		return [
			"uuid" => $folder->uuid,
			"parent_uuid" => $parent !== null ? $parent->uuid : FolderControl::UuidFor($folder->parent_id),
			"name" => $folder->name,
			"is_root" => $folder->IsRoot(),
			"created_at" => ApiTime::Utc($folder->created_at),
		];
	}

}
