<?php

namespace MagratheaExplorer\File;

use Magrathea\MagratheaModel;

/**
 * One derivative of a file (a thumbnail, a preview), made by the processor from a rendition
 * profile spec of the same name. Its object lives next to the original, at
 * `{storage_uuid}/{token}.{extension}`, under a token of its own: a rendition URL says nothing
 * about the original's. Renditions don't count toward the key's usage (plan §7.9).
 */
class FileRendition extends MagratheaModel {

	protected $dbTable = "file_renditions";
	protected $dbPk = "id";
	protected $dbValues = [
		"id" => "int",
		"file_id" => "int",
		// the spec's name: `thumb` is the one `thumbnail_url` points at
		"name" => "string",
		"token" => "string",
		"storage_path" => "string",
		"extension" => "string",
		"mime_type" => "string",
		// measured by ProcessorRunner; null on thumbnails migrated from 1.4.x until regenerated
		"width" => "int",
		"height" => "int",
		"size" => "int",
	];

	public $id, $file_id, $name, $token, $storage_path, $extension, $mime_type, $width, $height, $size;

}
