<?php

namespace MagratheaExplorer\Folder;

class Folder extends \MagratheaExplorer\Folder\Base\FolderBase {

	public function IsRoot(): bool {
		return empty($this->parent_id);
	}

}
