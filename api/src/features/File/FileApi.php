<?php

namespace MagratheaExplorer\File;

use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\ExplorerApiControl;
use MagratheaExplorer\Folder\Folder;
use MagratheaExplorer\Folder\FolderControl;
use MagratheaExplorer\Storage\StorageFactory;

class FileApi extends ExplorerApiControl {

	private static array $validFileTypes = ["image", "audio", "video", "document", "other"];

	/** POST /files -- multipart upload. Fields: file (required), folder_uuid, no_convert, tags (comma-separated) */
	public function Upload(): array {
		$key = $this->GetRequestKey();

		// If the whole request body exceeded post_max_size, PHP drops $_FILES and $_POST
		// entirely with no error code attached -- CONTENT_LENGTH is the only signal left,
		// so this is checked before anything else assumes a normal (just-empty) request.
		if(empty($_FILES) && empty($_POST) && !empty($_SERVER["CONTENT_LENGTH"])) {
			ErrorCodes::Instance()->ThrowException(4002);
		}
		if(empty($_FILES["file"])) {
			ErrorCodes::Instance()->ThrowException(4001, null, "file");
		}

		$post = $this->GetPost() ?? $_POST;
		$folder = !empty($post["folder_uuid"]) ? FolderControl::GetForKeyByUuid($key, (string)$post["folder_uuid"]) : FolderControl::GetRoot($key);
		$noConvert = !empty($post["no_convert"]);
		$tagNames = [];
		if(!empty($post["tags"])) {
			$tagNames = array_filter(array_map("trim", explode(",", $post["tags"])));
		}

		$file = UploadPipeline::Handle($key, $folder, $_FILES["file"], $noConvert, $tagNames);
		return $this->FileView($file, $folder);
	}

	/** GET /files -- filters: ?folder_uuid= (defaults to the key's root folder), ?file_type=, ?tag= */
	public function GetAll(): array {
		$key = $this->GetRequestKey();
		$folder = !empty($_GET["folder_uuid"]) ? FolderControl::GetForKeyByUuid($key, (string)$_GET["folder_uuid"]) : FolderControl::GetRoot($key);
		$conditions = ["`key_id` = ".(int)$key->id, "`folder_id` = ".(int)$folder->id];

		if(!empty($_GET["file_type"])) {
			if(!in_array($_GET["file_type"], self::$validFileTypes, true)) {
				ErrorCodes::Instance()->ThrowException(4001, null, "file_type");
			}
			$conditions[] = "`file_type` = '".$_GET["file_type"]."'";
		}

		$files = FileControl::GetSimpleWhere(implode(" AND ", $conditions));

		if(!empty($_GET["tag"])) {
			$tag = \MagratheaExplorer\File\TagControl::GetRowWhere(["name" => $_GET["tag"]]);
			$fileIds = $tag !== null ? FileTagControl::GetFileIdsForTag((int)$tag->id) : [];
			$files = array_values(array_filter($files, fn($f) => in_array((int)$f->id, $fileIds, true)));
		}

		return array_map(fn($file) => $this->FileView($file, $folder), $files);
	}

	/** GET /file/:uuid */
	public function Get($params): array {
		$key = $this->GetRequestKey();
		$file = FileControl::GetForKeyByUuid($key, (string)$params["uuid"]);
		return $this->FileView($file);
	}

	/** PUT /file/:uuid -- renames the display name only; reprocessing isn't supported */
	public function Update($params): array {
		$key = $this->GetRequestKey();
		$file = FileControl::GetForKeyByUuid($key, (string)$params["uuid"]);
		$data = $this->GetPut();
		if(!empty($data["name"])) {
			$file->name = trim($data["name"]);
			$file->Update();
		}
		return $this->FileView($file);
	}

	/** DELETE /file/:uuid -- deletes from storage (file + thumbnail) and adjusts the key's usage */
	public function Delete($params = false): array {
		$key = $this->GetRequestKey();
		$file = FileControl::GetForKeyByUuid($key, (string)$params["uuid"]);
		FileControl::Delete($file, $key);
		return ["deleted" => true];
	}

	/** POST /file/:uuid/tags -- body: name */
	public function AttachTag($params): array {
		$key = $this->GetRequestKey();
		$file = FileControl::GetForKeyByUuid($key, (string)$params["uuid"]);
		$data = $this->GetPost();
		$tag = TagControl::GetOrCreate($data["name"] ?? "");
		FileTagControl::Attach((int)$file->id, (int)$tag->id);
		return $this->FileView($file);
	}

	/** DELETE /file/:uuid/tags/:tag -- :tag is the tag name */
	public function DetachTag($params): array {
		$key = $this->GetRequestKey();
		$file = FileControl::GetForKeyByUuid($key, (string)$params["uuid"]);
		$tag = TagControl::GetRowWhere(["name" => $params["tag"] ?? ""]);
		if($tag === null) {
			ErrorCodes::Instance()->ThrowException(4044);
		}
		FileTagControl::Detach((int)$file->id, (int)$tag->id);
		return $this->FileView($file);
	}

	/**
	 * $folder, when the caller already resolved it (Upload()/GetAll()), skips a second
	 * lookup for folder_uuid; otherwise it's resolved from $file->folder_id.
	 */
	private function FileView(File $file, ?Folder $folder = null): array {
		$storage = StorageFactory::Instance()->Get();
		return [
			"uuid" => $file->uuid,
			"folder_uuid" => $folder !== null ? $folder->uuid : FolderControl::UuidFor($file->folder_id),
			"name" => $file->name,
			"extension" => $file->extension,
			"mime_type" => $file->mime_type,
			"file_type" => $file->file_type,
			"size" => (int)$file->size,
			"width" => $file->width !== null ? (int)$file->width : null,
			"height" => $file->height !== null ? (int)$file->height : null,
			"duration" => $file->duration !== null ? (int)$file->duration : null,
			"no_convert" => (bool)$file->no_convert,
			"url" => $storage->url($file->storage_path, $file->name, $file->DispositionType()),
			"thumbnail_url" => $file->HasThumbnail() ? $storage->url($file->thumbnail_path) : null,
			"tags" => array_map(fn($t) => $t->name, FileTagControl::GetTagsForFile((int)$file->id)),
			"created_at" => $file->created_at,
		];
	}

}
