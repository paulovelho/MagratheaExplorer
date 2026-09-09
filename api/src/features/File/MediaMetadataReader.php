<?php

namespace MagratheaExplorer\File;

use getID3;

/**
 * Duration extraction for audio/video via james-heinrich/getid3 (pure-PHP, no external
 * binary; v1.9.x -- the 2.x line is beta-only, so composer.json pins ^1.9). Its main
 * class is unnamespaced (global `getID3`), matching Images3's own convention of no
 * audio/video handling to crib from -- this is written fresh against getID3's own API.
 */
class MediaMetadataReader {

	/** @return array{duration: int|null} */
	public static function ReadDuration(string $localTmpFile): array {
		if(!class_exists(getID3::class)) {
			return ["duration" => null];
		}
		try {
			$getID3 = new getID3();
			$info = $getID3->analyze($localTmpFile);
			$seconds = $info["playtime_seconds"] ?? null;
			return ["duration" => $seconds !== null ? (int)round($seconds) : null];
		} catch(\Throwable $ex) {
			return ["duration" => null];
		}
	}

}
