<?php

namespace MagratheaExplorer\File;

use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\Folder\Folder;
use MagratheaExplorer\Folder\FolderControl;
use MagratheaExplorer\Key\Key;

/**
 * Bulk-imports a directory tree already sitting on the server's local filesystem (a
 * mounted staging area, not a client upload) into one key: the source directory itself
 * becomes a Folder under the chosen destination, mirrored recursively, and every regular
 * file goes through the same UploadPipeline as a real upload (MIME sniffing, image
 * re-encode/thumbnail/metadata strip, key uses/size counters). Symlinks -- files and
 * directories alike -- are skipped entirely, since following one could escape the source
 * tree or loop on a self-referential link. Runs synchronously in whichever admin request
 * triggers it; the caller is responsible for raising the time limit for large trees.
 */
class ImportPipeline {

	/** @return array{folders_touched:int, files_imported:int, files_skipped:array<array{path:string,reason:string}>, bytes_imported:int} */
	public static function Run(Key $key, Folder $destinationParent, string $sourcePath, bool $enforceQuota): array {
		$rootName = basename($sourcePath);
		if($rootName === "") {
			ErrorCodes::Instance()->ThrowException(4001, null, "path");
		}

		$stats = ["folders_touched" => 0, "files_imported" => 0, "files_skipped" => [], "bytes_imported" => 0];

		$rootFolder = FolderControl::GetOrCreate($key, $destinationParent->id, $rootName);
		$stats["folders_touched"]++;

		self::ImportDirectory($key, $rootFolder, $sourcePath, $enforceQuota, $stats);

		return $stats;
	}

	private static function ImportDirectory(Key $key, Folder $folder, string $path, bool $enforceQuota, array &$stats): void {
		$entries = scandir($path);
		if($entries === false) return;

		foreach($entries as $entry) {
			if($entry === "." || $entry === "..") continue;
			$fullPath = $path."/".$entry;

			if(is_link($fullPath)) continue;

			if(is_dir($fullPath)) {
				$childFolder = FolderControl::GetOrCreate($key, $folder->id, $entry);
				$stats["folders_touched"]++;
				self::ImportDirectory($key, $childFolder, $fullPath, $enforceQuota, $stats);
			} elseif(is_file($fullPath)) {
				self::ImportFile($key, $folder, $fullPath, $entry, $enforceQuota, $stats);
			}
		}
	}

	private static function ImportFile(Key $key, Folder $folder, string $fullPath, string $name, bool $enforceQuota, array &$stats): void {
		$fakeUpload = [
			"name" => $name,
			"type" => "",
			"tmp_name" => $fullPath,
			"error" => UPLOAD_ERR_OK,
			"size" => (int)filesize($fullPath),
		];
		try {
			// Not a real HTTP upload -- is_uploaded_file($fullPath) is false, so
			// UploadPipeline copies the source file instead of moving it, leaving it intact.
			$file = UploadPipeline::Handle($key, $folder, $fakeUpload, false, [], $enforceQuota);
			$stats["files_imported"]++;
			$stats["bytes_imported"] += (int)$file->size;
		} catch(\Throwable $ex) {
			$stats["files_skipped"][] = ["path" => $fullPath, "reason" => $ex->getMessage()];
		}
	}

}
