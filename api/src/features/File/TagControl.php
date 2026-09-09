<?php

namespace MagratheaExplorer\File;

use MagratheaExplorer\ErrorCodes;

class TagControl extends \MagratheaExplorer\File\Base\TagControlBase {

	public static function GetOrCreate(string $name): Tag {
		$name = trim($name);
		if(empty($name)) {
			ErrorCodes::Instance()->ThrowException(4008);
		}
		$existing = self::GetRowWhere(["name" => $name]);
		if($existing !== null) return $existing;
		$tag = new Tag();
		$tag->name = $name;
		$tag->Insert();
		return $tag;
	}

}
