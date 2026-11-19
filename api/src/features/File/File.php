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
		"key_id" => "int",
		"folder_id" => "int",
		"name" => "string",
		"storage_path" => "string",
		"extension" => "string",
		"mime_type" => "string",
		"file_type" => "string",
		"size" => "int",
		// lowercase hex of the stored bytes: the sender's hash only when verbatim (or not an
		// image). null on files from before 1.5.0 until `cron.php --backfill-sha256`
		"sha256" => "string",
		"width" => "int",
		"height" => "int",
		"duration" => "int",
		// JSON: what the processor read (ProcessorRunner::PROPERTY_TYPES, null = unknown).
		// null = never processed. Read it through FileResponse::Properties().
		"properties" => "text",
		// none | pending | ready | failed (see FileProcessing)
		"processing_status" => "string",
		"processing_error" => "string",
		"no_convert" => "boolean",
		// stored exactly as sent (no strip, no re-encode). Records the resolved request
		// (flag, else the key's default): non-image files are stored as sent either way.
		"verbatim" => "boolean",
		// `thumbnail_token`/`thumbnail_path` are still in the table (rollback to 1.4.x), but no
		// longer written or read: the thumbnail is the `thumb` row in file_renditions.
	];
	protected $relations = [
		"properties" => ["Key" => null, "Folder" => null, "Tags" => null],
		"methods" => ["Key" => "GetKey", "Folder" => "GetFolder", "Tags" => "GetTags"],
		"lazyload" => ["Key" => true, "Folder" => true, "Tags" => true],
	];

	public $id, $uuid, $token, $key_id, $folder_id, $name, $storage_path;
	public $extension, $mime_type, $file_type, $size, $sha256, $width, $height, $duration;
	public $properties, $processing_status, $processing_error, $no_convert, $verbatim;

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

	/** The processor's properties as stored, or null when there are none. */
	public function StoredProperties(): ?array {
		if($this->properties === null || $this->properties === "") return null;
		$decoded = json_decode((string)$this->properties, true);
		return is_array($decoded) ? $decoded : null;
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
