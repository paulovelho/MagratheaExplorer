<?php

namespace MagratheaExplorer\Folder;

use MagratheaExplorer\ExplorerApiControl;

class FolderApi extends ExplorerApiControl {

	/** GET /folders -- all folders for the key, or just the direct children of ?parent_id= */
	public function GetAll(): array {
		$key = $this->GetRequestKey();
		$parentId = $_GET["parent_id"] ?? null;
		if($parentId !== null) {
			$folders = FolderControl::GetSimpleWhere("`key_id` = ".(int)$key->id." AND `parent_id` = ".(int)$parentId);
		} else {
			$folders = FolderControl::GetWhere(["key_id" => $key->id]);
		}
		return array_map([$this, "FolderView"], $folders);
	}

	/** POST /folders -- body: name (required), parent_id (optional, defaults to the key's root) */
	public function Create($data = false): array {
		$key = $this->GetRequestKey();
		$data = $this->GetPost();
		$parentId = isset($data["parent_id"]) && $data["parent_id"] !== "" ? (int)$data["parent_id"] : null;
		$folder = FolderControl::Create($key, $parentId, $data["name"] ?? "");
		return $this->FolderView($folder);
	}

	/** GET /folder/:id */
	public function Get($params): array {
		$key = $this->GetRequestKey();
		$folder = FolderControl::GetForKey($key, (int)$params["id"]);
		return $this->FolderView($folder);
	}

	/** PUT /folder/:id -- rename */
	public function Update($params): array {
		$key = $this->GetRequestKey();
		$folder = FolderControl::GetForKey($key, (int)$params["id"]);
		$data = $this->GetPut();
		FolderControl::Rename($folder, $data["name"] ?? "");
		return $this->FolderView($folder);
	}

	/** DELETE /folder/:id -- root folders and non-empty folders can't be deleted */
	public function Delete($params = false): array {
		$key = $this->GetRequestKey();
		$folder = FolderControl::GetForKey($key, (int)$params["id"]);
		FolderControl::AssertNotRoot($folder);
		FolderControl::AssertEmpty($folder);
		$folder->Delete();
		return ["deleted" => true];
	}

	/** GET /folder/:id/size -- recursive subtree byte total */
	public function GetSize($params): array {
		$key = $this->GetRequestKey();
		$folder = FolderControl::GetForKey($key, (int)$params["id"]);
		return ["size" => FolderControl::ComputeSize($folder)];
	}

	private function FolderView(Folder $folder): array {
		return [
			"id" => (int)$folder->id,
			"parent_id" => $folder->parent_id !== null ? (int)$folder->parent_id : null,
			"name" => $folder->name,
			"is_root" => $folder->IsRoot(),
			"created_at" => $folder->created_at,
		];
	}

}
