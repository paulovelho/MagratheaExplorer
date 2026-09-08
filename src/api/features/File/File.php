<?php

namespace MagratheaExplorer\File;

class File extends \MagratheaExplorer\File\Base\FileBase {

	public function HasThumbnail(): bool {
		return !empty($this->thumbnail_token);
	}

}
