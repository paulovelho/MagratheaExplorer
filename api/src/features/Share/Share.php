<?php

namespace MagratheaExplorer\Share;

use Magrathea\MagratheaModel;

class Share extends MagratheaModel {

	protected $dbTable = "shares";
	protected $dbPk = "id";
	protected $dbValues = [
		"id" => "int",
		// "uuid" so Insert() mints it: this one value is both the row's public identity
		// AND the link secret; there is no second token column.
		"uuid" => "uuid",
		"key_id" => "int",
		"file_id" => "int",
		"folder_id" => "int",
		"views" => "int",
		"last_viewed_at" => "datetime",
	];
	protected $relations = [
		"properties" => ["Key" => null, "File" => null, "Folder" => null],
		"methods" => ["Key" => "GetKey", "File" => "GetFile", "Folder" => "GetFolder"],
		"lazyload" => ["Key" => true, "File" => true, "Folder" => true],
	];

	public $id, $uuid, $key_id, $file_id, $folder_id, $views, $last_viewed_at;

	public function GetKey(): \MagratheaExplorer\Key\Key {
		return $this->relations["properties"]["Key"] ??= new \MagratheaExplorer\Key\Key($this->key_id);
	}

	// Exactly one of file_id/folder_id is ever set (chk_share_target), so both getters
	// return null for the unused side instead of loading a File/Folder with id 0/null.
	public function GetFile(): ?\MagratheaExplorer\File\File {
		if(empty($this->file_id)) return null;
		return $this->relations["properties"]["File"] ??= new \MagratheaExplorer\File\File($this->file_id);
	}

	public function GetFolder(): ?\MagratheaExplorer\Folder\Folder {
		if(empty($this->folder_id)) return null;
		return $this->relations["properties"]["Folder"] ??= new \MagratheaExplorer\Folder\Folder($this->folder_id);
	}

	/** "file" or "folder" -- exactly one of file_id/folder_id is set (chk_share_target). */
	public function TargetType(): string {
		return !empty($this->file_id) ? "file" : "folder";
	}

	/**
	 * Display name of whatever this share points at. A shared root folder reports as
	 * "All files" rather than the literal row name "root" -- "root" is an internal
	 * bookkeeping name, and the public share page renders this string verbatim.
	 */
	public function TargetName(): string {
		if($this->TargetType() === "file") {
			$file = $this->GetFile();
			return $file !== null ? $file->name : "";
		}
		$folder = $this->GetFolder();
		if($folder === null) return "";
		return $folder->IsRoot() ? "All files" : $folder->name;
	}

}
