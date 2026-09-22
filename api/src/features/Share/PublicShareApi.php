<?php

namespace MagratheaExplorer\Share;

use Magrathea2\MagratheaApiControl;
use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\File\File;
use MagratheaExplorer\File\Base\FileControlBase;
use MagratheaExplorer\Folder\Folder;
use MagratheaExplorer\Folder\FolderControl;
use MagratheaExplorer\Storage\StorageFactory;

/**
 * The unauthenticated half of sharing: every route here is self::OPEN, and the only
 * credential involved is the share uuid in the path.
 *
 * Deliberately extends MagratheaApiControl and NOT ExplorerApiControl -- that base exists
 * solely to resolve a bearer key, and there is none here. Extending it would make it easy
 * for a later edit to call GetRequestKey() on a public route and throw a confusing 401.
 *
 * Nothing this class returns may name the owner: no key_id, no key uuid (the bearer
 * credential), no key name, no view counts, and no folder ABOVE the share root -- see
 * SharePath(), which starts at the root and never walks past it.
 */
class PublicShareApi extends MagratheaApiControl {

	/** GET /shared/:uuid -- the share's own landing payload (one file, or the share root folder) */
	public function Get($params): array {
		$share = ShareControl::ResolveFromUuid((string)($params["uuid"] ?? ""));

		if($share->TargetType() === "file") {
			$file = $share->GetFile();
			if($file === null) ErrorCodes::Instance()->ThrowException(4045);
			$payload = [
				"type" => "file",
				"name" => $file->name,
				"file" => $this->SharedFileView($file),
			];
		} else {
			$root = $this->ShareRoot($share);
			$payload = $this->FolderPayload($share, $root, [$root]);
		}

		ShareControl::RegisterView($share);
		return $payload;
	}

	/**
	 * GET /shared/:uuid/folder/:folder_uuid -- browse into the shared subtree.
	 * A folder uuid is safe to hand out because this action, not the uuid, is the gate:
	 * FolderControl::PathWithin() below refuses anything that isn't a descendant of the
	 * share root.
	 */
	public function GetFolder($params): array {
		$share = ShareControl::ResolveFromUuid((string)($params["uuid"] ?? ""));
		if($share->TargetType() !== "folder") {
			ErrorCodes::Instance()->ThrowException(4042);
		}

		// GetRowWhere(), not `new Folder($id)` -- the constructor throws
		// MagratheaModelException on a miss and we want our own 4042. The key_id clamp is
		// free here and means a visitor can't even name another key's folder.
		$folder = FolderControl::GetRowWhere([
			"uuid" => (string)($params["folder_uuid"] ?? ""),
			"key_id" => (int)$share->key_id,
		]);
		if($folder === null) {
			ErrorCodes::Instance()->ThrowException(4042);
		}

		$root = $this->ShareRoot($share);
		$path = FolderControl::PathWithin($folder, $root);
		if($path === null) {
			// outside this share -- same 4042 as "no such folder", so a visitor can't
			// probe the owner's tree by watching which uuids answer differently.
			ErrorCodes::Instance()->ThrowException(4042);
		}

		$payload = $this->FolderPayload($share, $folder, $path);
		ShareControl::RegisterView($share);
		return $payload;
	}

	private function ShareRoot(Share $share): Folder {
		$root = $share->GetFolder();
		if($root === null) ErrorCodes::Instance()->ThrowException(4045);
		return $root;
	}

	/** One folder's listing, always relative to the share -- never the owner's whole tree. */
	private function FolderPayload(Share $share, Folder $folder, array $path): array {
		$folders = FolderControl::GetSimpleWhere("`parent_id` = ".(int)$folder->id);
		$files = FileControlBase::GetSimpleWhere("`folder_id` = ".(int)$folder->id);
		usort($folders, fn($a, $b) => strcasecmp($a->name, $b->name));
		usort($files, fn($a, $b) => strcasecmp($a->name, $b->name));

		return [
			"type" => "folder",
			"name" => $this->DisplayName($share, $folder),
			"path" => array_map(fn($crumb) => [
				"uuid" => $crumb->uuid,
				"name" => $this->DisplayName($share, $crumb),
			], $path),
			"folders" => array_map(fn($f) => ["uuid" => $f->uuid, "name" => $f->name], $folders),
			"files" => array_map(fn($f) => $this->SharedFileView($f), $files),
		];
	}

	/**
	 * A shared root folder is shown as "All files", never its literal row name "root" --
	 * that name is internal bookkeeping and would look like a bug on a public page.
	 * Only the share root can be a root folder, so everything deeper uses its own name.
	 */
	private function DisplayName(Share $share, Folder $folder): string {
		return $folder->IsRoot() ? $share->TargetName() : $folder->name;
	}

	/**
	 * A strict subset of FileApi::FileView() -- same field names and types, so the app can
	 * render a shared listing with the shape it already knows. Dropped on purpose:
	 * `folder_uuid` (the visitor navigates by share, not by folder), `no_convert` and
	 * `tags` (owner-side metadata).
	 *
	 * `url` is the same direct-from-storage link the owner gets. Deleting the share stops
	 * this page; a storage URL someone already copied keeps working, and only deleting the
	 * file stops that.
	 */
	private function SharedFileView(File $file): array {
		$storage = StorageFactory::Instance()->Get();
		return [
			"uuid" => $file->uuid,
			"name" => $file->name,
			"extension" => $file->extension,
			"mime_type" => $file->mime_type,
			"file_type" => $file->file_type,
			"size" => (int)$file->size,
			"width" => $file->width !== null ? (int)$file->width : null,
			"height" => $file->height !== null ? (int)$file->height : null,
			"duration" => $file->duration !== null ? (int)$file->duration : null,
			"url" => $storage->url($file->storage_path, $file->name, $file->DispositionType()),
			"thumbnail_url" => $file->HasThumbnail() ? $storage->url($file->thumbnail_path) : null,
			"created_at" => $file->created_at,
		];
	}

}
