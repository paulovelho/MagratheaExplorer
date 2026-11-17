<?php

namespace MagratheaExplorer\File;

use Magrathea\MagratheaModel;

class File extends MagratheaModel {

	protected $dbTable = "files";
	protected $dbPk = "id";
	protected $dbValues = [
		"id" => "int",
		// "uuid" so Insert() mints it: the file's public identity. The int id stays
		// internal-only.
		"uuid" => "uuid",
		"token" => "string",
		"thumbnail_token" => "string",
		"key_id" => "int",
		"folder_id" => "int",
		"name" => "string",
		"storage_path" => "string",
		"extension" => "string",
		"mime_type" => "string",
		"file_type" => "string",
		"size" => "int",
		"width" => "int",
		"height" => "int",
		"duration" => "int",
		"no_convert" => "boolean",
		// storage path of the thumbnail object; null when there is no thumbnail
		"thumbnail_path" => "string",
	];
	protected $relations = [
		"properties" => ["Key" => null, "Folder" => null, "Tags" => null],
		"methods" => ["Key" => "GetKey", "Folder" => "GetFolder", "Tags" => "GetTags"],
		"lazyload" => ["Key" => true, "Folder" => true, "Tags" => true],
	];

	public $id, $uuid, $token, $thumbnail_token, $key_id, $folder_id, $name, $storage_path;
	public $extension, $mime_type, $file_type, $size, $width, $height, $duration, $no_convert;
	public $thumbnail_path;

	public function GetKey(): \MagratheaExplorer\Key\Key {
		return $this->relations["properties"]["Key"] ??= new \MagratheaExplorer\Key\Key($this->key_id);
	}

	public function GetFolder(): \MagratheaExplorer\Folder\Folder {
		return $this->relations["properties"]["Folder"] ??= new \MagratheaExplorer\Folder\Folder($this->folder_id);
	}

	// many-to-many through the plain `file_tags` join table (composite PK, no Model/Control of its own)
	public function GetTags(): array {
		return $this->relations["properties"]["Tags"] ??= FileTagControl::GetTagsForFile($this->id);
	}

	public function HasThumbnail(): bool {
		return !empty($this->thumbnail_token);
	}

	/**
	 * "attachment" forces a download for file_type=other and SVG -- the real
	 * stored-XSS protection (see UploadPipeline). Exposed as a static so both
	 * UploadPipeline (deciding at upload time, before a File exists) and FileApi
	 * (deciding at read time, for local storage's per-request header) stay in sync.
	 */
	public function DispositionType(): string {
		return self::DispositionTypeFor($this->file_type, $this->extension);
	}

	public static function DispositionTypeFor(string $fileType, string $extension): string {
		return ($fileType === "other" || $extension === "svg") ? "attachment" : "inline";
	}

}
