<?php

namespace MagratheaExplorer\Share;

use Magrathea2\Config;
use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\ExplorerApiControl;
use MagratheaExplorer\File\FileControl;
use MagratheaExplorer\Folder\FolderControl;

class ShareApi extends ExplorerApiControl {

	/** POST /shares -- body: file_uuid XOR folder_uuid */
	public function Create($data = false): array {
		$key = $this->GetRequestKey();
		$data = $this->GetPost();
		$fileUuid = !empty($data["file_uuid"]) ? (string)$data["file_uuid"] : null;
		$folderUuid = !empty($data["folder_uuid"]) ? (string)$data["folder_uuid"] : null;
		if(($fileUuid === null) === ($folderUuid === null)) {
			ErrorCodes::Instance()->ThrowException(4001, null, "file_uuid or folder_uuid (exactly one)");
		}

		// Scoped lookups, so another key's target 404s (4042/4043) rather than 403s --
		// the same not-yours-is-not-found rule the rest of the API follows.
		$file = $fileUuid !== null ? FileControl::GetForKeyByUuid($key, $fileUuid) : null;
		$folder = $folderUuid !== null ? FolderControl::GetForKeyByUuid($key, $folderUuid) : null;

		$share = ShareControl::Create($key, $file, $folder);
		return $this->ShareView($share);
	}

	/**
	 * GET /shares -- this key's shares, newest first. Optional ?file_uuid= / ?folder_uuid=
	 * narrows to one target, which is what the per-item ShareDialog asks for.
	 */
	public function GetAll(): array {
		$key = $this->GetRequestKey();
		$conditions = ["`key_id` = ".(int)$key->id];

		if(!empty($_GET["file_uuid"])) {
			$file = FileControl::GetForKeyByUuid($key, (string)$_GET["file_uuid"]);
			$conditions[] = "`file_id` = ".(int)$file->id;
		}
		if(!empty($_GET["folder_uuid"])) {
			$folder = FolderControl::GetForKeyByUuid($key, (string)$_GET["folder_uuid"]);
			$conditions[] = "`folder_id` = ".(int)$folder->id;
		}

		$shares = ShareControl::GetSimpleWhere(implode(" AND ", $conditions)." ORDER BY `created_at` DESC");
		return array_map(fn($share) => $this->ShareView($share), $shares);
	}

	/**
	 * DELETE /share/:uuid -- kills the link. Already-copied direct file URLs are NOT
	 * affected: they're served straight from storage with no share in the path, so only
	 * deleting the file itself stops those.
	 */
	public function Delete($params = false): array {
		$key = $this->GetRequestKey();
		$share = ShareControl::GetForKeyByUuid($key, (string)$params["uuid"]);
		ShareControl::Delete($share);
		return ["deleted" => true];
	}

	/**
	 * Owner-facing shape. No `id` -- the uuid refactor removed int ids from every public
	 * response. `url` is assembled here rather than stored so moving the instance to a new
	 * host doesn't leave a table full of stale absolute links.
	 */
	private function ShareView(Share $share): array {
		$type = $share->TargetType();
		return [
			"uuid" => $share->uuid,
			"url" => self::ShareUrl($share->uuid),
			"target_type" => $type,
			"target_name" => $share->TargetName(),
			"file_uuid" => $type === "file" ? ($share->GetFile()?->uuid) : null,
			"folder_uuid" => $type === "folder" ? ($share->GetFolder()?->uuid) : null,
			"views" => (int)$share->views,
			"last_viewed_at" => $share->last_viewed_at,
			"created_at" => $share->created_at,
		];
	}

	/** Same `app_url` config key MagratheaExplorerApi::SetUrl() already builds the API base from. */
	public static function ShareUrl(string $uuid): string {
		return rtrim((string)Config::Instance()->Get("app_url"), "/")."/app/s/".$uuid;
	}

}
