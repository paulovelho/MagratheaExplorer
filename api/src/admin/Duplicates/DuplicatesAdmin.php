<?php

namespace MagratheaExplorer;

use Magrathea2\Admin\AdminFeature;
use Magrathea2\Admin\AdminManager;
use Magrathea2\Admin\AdminUrls;
use Magrathea2\Admin\iAdminFeature;
use MagratheaExplorer\File\File;
use MagratheaExplorer\File\FileControl;
use MagratheaExplorer\Folder\Folder;
use MagratheaExplorer\Key\Key;

/**
 * Finds files that collide on (key_id, folder_id, name) -- the shape the import pipeline
 * produced when the same source tree got imported more than once -- lists each colliding
 * group side by side, and on confirm deletes every file in a selected group except the
 * newest one (by created_at, falling back to id on an exact-timestamp tie).
 */
class DuplicatesAdmin extends AdminFeature implements iAdminFeature {

	public string $featureName = "Duplicates";
	public string $featureId = "AdminDuplicates";
	public $featureIcon = "copy";

	public function __construct() {
		parent::__construct();
		$this->SetClassPath(__DIR__);
	}

	public function Index() {
		$groups = self::FindDuplicateGroups();
		include(__DIR__."/views/index.php");
	}

	/**
	 * Groups every file in the system by (key_id, folder_id, name) in PHP (mirrors
	 * BrowserAdmin::OrphanCheck's approach of loading everything and filtering in memory
	 * rather than a raw GROUP BY, since FileControl has no query-builder helper for it).
	 * Each returned group's `files` are sorted newest-first.
	 * @return array<array{key: Key, folder: Folder, name: string, files: File[], token: string}>
	 */
	public static function FindDuplicateGroups(): array {
		$byGroup = [];
		foreach(FileControl::GetAll() as $file) {
			$byGroup[$file->key_id."|".$file->folder_id."|".$file->name][] = $file;
		}

		$groups = [];
		foreach($byGroup as $groupKey => $files) {
			if(count($files) < 2) continue;
			usort($files, function(File $a, File $b) {
				return strcmp($b->created_at, $a->created_at) ?: ((int)$b->id <=> (int)$a->id);
			});
			[$keyId, $folderId, $name] = explode("|", $groupKey, 3);
			$groups[] = [
				"key" => new Key((int)$keyId),
				"folder" => new Folder((int)$folderId),
				"name" => $name,
				"files" => $files,
				"token" => self::EncodeGroupToken((int)$keyId, (int)$folderId, $name),
			];
		}
		return $groups;
	}

	private static function EncodeGroupToken(int $keyId, int $folderId, string $name): string {
		return base64_encode(json_encode([$keyId, $folderId, $name]));
	}

	private static function DecodeGroupToken(string $token): ?array {
		$decoded = json_decode(base64_decode($token), true);
		if(!is_array($decoded) || count($decoded) !== 3) return null;
		return [(int)$decoded[0], (int)$decoded[1], (string)$decoded[2]];
	}

	/** POST handler: for each selected group, deletes every file but the newest. */
	public function DeleteSelected() {
		$tokens = $_POST["groups"] ?? [];
		$deletedFiles = 0;
		$deletedGroups = 0;

		foreach($tokens as $token) {
			$decoded = self::DecodeGroupToken((string)$token);
			if($decoded === null) continue;
			[$keyId, $folderId, $name] = $decoded;

			$files = FileControl::GetWhere(["key_id" => $keyId, "folder_id" => $folderId, "name" => $name]);
			if(count($files) < 2) continue;

			usort($files, function(File $a, File $b) {
				return strcmp($b->created_at, $a->created_at) ?: ((int)$b->id <=> (int)$a->id);
			});
			array_shift($files); // keep the newest, delete the rest

			$key = new Key($keyId);
			foreach($files as $file) {
				FileControl::Delete($file, $key);
				$deletedFiles++;
			}
			$deletedGroups++;
			AdminManager::Instance()->Log("duplicate files purged", $name, [
				"key" => $key->name,
				"folder_id" => $folderId,
				"copies_deleted" => count($files),
			]);
		}

		$remainingGroups = self::FindDuplicateGroups();
		include(__DIR__."/views/result.php");
	}

}
