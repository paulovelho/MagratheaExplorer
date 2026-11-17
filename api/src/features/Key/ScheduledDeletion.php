<?php

namespace MagratheaExplorer\Key;

use Magrathea\MagratheaModel;

class ScheduledDeletion extends MagratheaModel {

	protected $dbTable = "scheduled_deletions";
	protected $dbPk = "id";
	protected $dbValues = [
		"id" => "int",
		"key_id" => "int",
		"requested_at" => "datetime",
		"execute_at" => "datetime",
		"cancelled_at" => "datetime",
	];
	protected $relations = [
		"properties" => ["Key" => null],
		"methods" => ["Key" => "GetKey"],
		"lazyload" => ["Key" => true],
	];

	public $id, $key_id, $requested_at, $execute_at, $cancelled_at;

	public function GetKey(): Key {
		return $this->relations["properties"]["Key"] ??= new Key($this->key_id);
	}

	public function IsPending(): bool {
		return empty($this->cancelled_at);
	}

	public function IsDue(): bool {
		return $this->IsPending() && $this->execute_at <= \Magrathea\now();
	}

}
