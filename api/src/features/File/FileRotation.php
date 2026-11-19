<?php

namespace MagratheaExplorer\File;

use Magrathea\DB\Database;
use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\Storage\StorageAdapter;
use MagratheaExplorer\Storage\StorageFactory;
use MagratheaExplorer\Storage\StorageGarbage;

/**
 * POST /file/:uuid/rotate: new URLs for a file and its renditions, the old ones gone from the
 * origin. Same bytes, same uuid, same everything else.
 *
 *   1. read the file and its renditions; mint a new token for each;
 *   2. copy every object to the same directory under its new token (the extension stays). A copy
 *      that fails: the copies are deleted, 5001, nothing changed;
 *   3. one transaction: the file row locked (FOR UPDATE, the lock JobRunner's commit takes),
 *      then the file's token/path and its renditions read again. Anything different from step 1
 *      (a processing run committed new renditions, a rename of the object by another rotation)
 *      → roll back, delete the copies, 4093. Otherwise every token and path is switched;
 *   4. after the commit, the old objects are deleted. One that fails goes into StorageGarbage
 *      for `cron.php --process`: the database already points at the new ones.
 *
 * A queued processing job doesn't block it: its renditions are made under tokens nobody has
 * seen, and when it commits it deletes the renditions it finds then, the rotated ones.
 *
 * Behind a CDN this doesn't revoke an old URL that was fetched through it: objects are cached as
 * immutable for a year (S3StorageAdapter), and nothing here purges the cache.
 */
final class FileRotation {

	/** @return File the file as it is now (reloaded) */
	public static function Rotate(File $file, ?StorageAdapter $storage = null): File {
		$storage ??= StorageFactory::Instance()->Get();
		$fileId = (int)$file->id;
		$before = FileRenditionControl::GetForFile($fileId);

		// [old path => new path], and what the rows become
		$moves = [];
		$fileToken = TokenGenerator::GenerateUnique();
		$filePath = self::ReplaceToken((string)$file->storage_path, $fileToken);
		$moves[(string)$file->storage_path] = $filePath;
		$renditionUpdates = [];
		foreach($before as $rendition) {
			$token = TokenGenerator::GenerateUnique();
			$path = self::ReplaceToken((string)$rendition->storage_path, $token);
			$moves[(string)$rendition->storage_path] = $path;
			$renditionUpdates[] = ["id" => (int)$rendition->id, "token" => $token, "path" => $path];
		}

		$copies = [];
		try {
			foreach($moves as $from => $to) {
				$storage->copy($from, $to);
				$copies[] = $to;
			}
		} catch(\Throwable $ex) {
			StorageGarbage::Delete($storage, $copies);
			ErrorCodes::Instance()->ThrowException(5001, null, $ex->getMessage());
		}

		$expected = self::Fingerprint((string)$file->token, (string)$file->storage_path, $before);
		$db = Database::Instance();
		try {
			$db->Transaction(function() use ($db, $fileId, $expected, $fileToken, $filePath, $renditionUpdates) {
				$row = $db->QueryRow("SELECT `id`, `token`, `storage_path` FROM `files` WHERE `id` = ".$fileId." FOR UPDATE");
				if(empty($row)) ErrorCodes::Instance()->ThrowException(4043);
				$now = self::Fingerprint((string)$row["token"], (string)$row["storage_path"], FileRenditionControl::GetForFile($fileId));
				if($now !== $expected) ErrorCodes::Instance()->ThrowException(4093);

				$db->PrepareAndExecute(
					"UPDATE `files` SET `token` = ?, `storage_path` = ? WHERE `id` = ?",
					["string", "string", "int"],
					[$fileToken, $filePath, $fileId]
				);
				foreach($renditionUpdates as $update) {
					$db->PrepareAndExecute(
						"UPDATE `file_renditions` SET `token` = ?, `storage_path` = ? WHERE `id` = ?",
						["string", "string", "int"],
						[$update["token"], $update["path"], $update["id"]]
					);
				}
			});
		} catch(\Throwable $ex) {
			// rolled back: no row points at the copies
			StorageGarbage::Delete($storage, $copies);
			throw $ex;
		}

		StorageGarbage::Delete($storage, array_keys($moves));
		return $file->Reload();
	}

	/**
	 * $path with its token replaced: `{dir}/{token}.{ext}` → `{dir}/{new}.{ext}`. The directory
	 * and the extension are kept as they are, whatever they are.
	 */
	public static function ReplaceToken(string $path, string $newToken): string {
		$slash = strrpos($path, "/");
		$dir = $slash === false ? "" : substr($path, 0, $slash + 1);
		$base = $slash === false ? $path : substr($path, $slash + 1);
		// tokens are [0-9A-Za-z]: the first dot starts the extension
		$dot = strpos($base, ".");
		return $dir.$newToken.($dot === false ? "" : substr($base, $dot));
	}

	/**
	 * What step 3 compares: the file's token and path, and every rendition's id, token and path.
	 * @param array<string, FileRendition> $renditions
	 */
	public static function Fingerprint(string $token, string $path, array $renditions): string {
		$parts = [$token, $path];
		$rows = [];
		foreach($renditions as $rendition) {
			$rows[(int)$rendition->id] = $rendition->token."|".$rendition->storage_path;
		}
		ksort($rows);
		foreach($rows as $id => $value) $parts[] = $id."|".$value;
		return implode("\n", $parts);
	}

}
