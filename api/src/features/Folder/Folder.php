<?php

namespace MagratheaExplorer\Folder;

use Magrathea\MagratheaModel;

class Folder extends MagratheaModel {

	protected $dbTable = "folders";
	protected $dbPk = "id";
	protected $dbValues = [
		"id" => "int",
		// "uuid" so Insert() mints it: the folder's public identity. The int id stays
		// internal-only.
		"uuid" => "uuid",
		"key_id" => "int",
		"parent_id" => "int",
		"name" => "string",
	];
	protected $relations = [
		"properties" => ["Key" => null, "Files" => null, "Parent" => null, "Children" => null],
		"methods" => ["Key" => "GetKey", "Files" => "GetFiles", "Parent" => "GetParent", "Children" => "GetChildren"],
		"lazyload" => ["Key" => true, "Files" => true, "Parent" => true, "Children" => true],
	];

	public $id, $uuid, $key_id, $parent_id, $name;

	public function GetKey(): \MagratheaExplorer\Key\Key {
		return $this->relations["properties"]["Key"] ??= new \MagratheaExplorer\Key\Key($this->key_id);
	}

	public function GetFiles(): array {
		return $this->relations["properties"]["Files"] ??= \MagratheaExplorer\File\FileControl::GetWhere(["folder_id" => $this->id]);
	}

	// Root folders have parent_id = NULL: never load a Folder with id 0/null.
	public function GetParent(): ?Folder {
		if(empty($this->parent_id)) return null;
		return $this->relations["properties"]["Parent"] ??= new Folder($this->parent_id);
	}

	public function GetChildren(): array {
		return $this->relations["properties"]["Children"] ??= FolderControl::GetWhere(["parent_id" => $this->id]);
	}

	public function IsRoot(): bool {
		return empty($this->parent_id);
	}

}
