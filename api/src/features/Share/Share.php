<?php

namespace MagratheaExplorer\Share;

class Share extends \MagratheaExplorer\Share\Base\ShareBase {

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
