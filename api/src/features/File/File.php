<?php

namespace MagratheaExplorer\File;

class File extends \MagratheaExplorer\File\Base\FileBase {

	public function HasThumbnail(): bool {
		return !empty($this->thumbnail_token);
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
