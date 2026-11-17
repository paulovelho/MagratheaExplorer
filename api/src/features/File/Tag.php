<?php

namespace MagratheaExplorer\File;

use Magrathea\MagratheaModel;

class Tag extends MagratheaModel {

	protected $dbTable = "tags";
	protected $dbPk = "id";
	protected $dbValues = [
		"id" => "int",
		"name" => "string",
	];

	public $id, $name;

}
