<?php

namespace MagratheaExplorer\File;

use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\ExplorerApiControl;
use MagratheaExplorer\Folder\FolderControl;
use MagratheaExplorer\Storage\StorageFactory;

class FileApi extends ExplorerApiControl {

	private static array $validFileTypes = ["image", "audio", "video", "document", "other"];

	/** POST /files -- multipart upload. Fields: file (required), folder_id, no_convert, tags (comma-separated) */
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
		$folderId = !empty($post["folder_id"]) ? (int)$post["folder_id"] : null;
		$folder = $folderId !== null ? FolderControl::GetForKey($key, $folderId) : FolderControl::GetRoot($key);
		$noConvert = !empty($post["no_convert"]);
		$tagNames = [];
		if(!empty($post["tags"])) {
			$tagNames = array_filter(array_map("trim", explode(",", $post["tags"])));
		}

		$file = UploadPipeline::Handle($key, $folder, $_FILES["file"], $noConvert, $tagNames);
		return $this->FileView($file);
	}

	/** GET /files -- filters: ?folder_id=, ?file_type=, ?tag= */
	public function GetAll(): array {
		$key = $this->GetRequestKey();
		$conditions = ["`key_id` = ".(int)$key->id];

		if(!empty($_GET["folder_id"])) {
			$conditions[] = "`folder_id` = ".(int)$_GET["folder_id"];
		}
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

		return array_map([$this, "FileView"], $files);
	}

	/** GET /file/:id */
	public function Get($params): array {
		$key = $this->GetRequestKey();
		$file = FileControl::GetForKey($key, (int)$params["id"]);
		return $this->FileView($file);
	}

	/** PUT /file/:id -- renames the display name only; reprocessing isn't supported */
	public function Update($params): array {
		$key = $this->GetRequestKey();
		$file = FileControl::GetForKey($key, (int)$params["id"]);
		$data = $this->GetPut();
		if(!empty($data["name"])) {
			$file->name = trim($data["name"]);
			$file->Update();
		}
		return $this->FileView($file);
	}

	/** DELETE /file/:id -- deletes from storage (file + thumbnail) and adjusts the key's usage */
	public function Delete($params = false): array {
		$key = $this->GetRequestKey();
		$file = FileControl::GetForKey($key, (int)$params["id"]);
		FileControl::Delete($file, $key);
		return ["deleted" => true];
	}

	/** POST /file/:id/tags -- body: name */
	public function AttachTag($params): array {
		$key = $this->GetRequestKey();
		$file = FileControl::GetForKey($key, (int)$params["id"]);
		$data = $this->GetPost();
		$tag = TagControl::GetOrCreate($data["name"] ?? "");
		FileTagControl::Attach((int)$file->id, (int)$tag->id);
		return $this->FileView($file);
	}

	/** DELETE /file/:id/tags/:tag -- :tag is the tag name */
	public function DetachTag($params): array {
		$key = $this->GetRequestKey();
		$file = FileControl::GetForKey($key, (int)$params["id"]);
		$tag = TagControl::GetRowWhere(["name" => $params["tag"] ?? ""]);
		if($tag === null) {
			ErrorCodes::Instance()->ThrowException(4044);
		}
		FileTagControl::Detach((int)$file->id, (int)$tag->id);
		return $this->FileView($file);
	}

	private function FileView(File $file): array {
		$storage = StorageFactory::Instance()->Get();
		return [
			"id" => (int)$file->id,
			"folder_id" => (int)$file->folder_id,
			"name" => $file->name,
			"extension" => $file->extension,
			"mime_type" => $file->mime_type,
			"file_type" => $file->file_type,
			"size" => (int)$file->size,
			"width" => $file->width !== null ? (int)$file->width : null,
			"height" => $file->height !== null ? (int)$file->height : null,
			"duration" => $file->duration !== null ? (int)$file->duration : null,
			"no_convert" => (bool)$file->no_convert,
			"url" => $storage->url($file->storage_path),
			"thumbnail_url" => $file->HasThumbnail()
				? $storage->url($file->thumbnail_token.".".(strtolower($file->extension) === "png" ? "png" : "jpg"))
				: null,
			"tags" => array_map(fn($t) => $t->name, FileTagControl::GetTagsForFile((int)$file->id)),
			"created_at" => $file->created_at,
		];
	}

}
