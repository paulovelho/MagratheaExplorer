<?php

namespace MagratheaExplorer;

use Magrathea2\Admin\AdminFeature;
use Magrathea2\Admin\AdminManager;
use Magrathea2\Admin\AdminUrls;
use Magrathea2\Admin\iAdminFeature;
use MagratheaExplorer\File\FileControl;
use MagratheaExplorer\Folder\FolderControl;
use MagratheaExplorer\Key\Key;
use MagratheaExplorer\Key\KeyControl;
use MagratheaExplorer\Storage\StorageFactory;

/**
 * Folder/file browser spanning all keys (lives at the admin/ level like Images3's
 * MediaManager, since it's not scoped to one feature), plus orphan cleanup: files whose
 * DB row points at a storage object that no longer exists in the active backend.
 */
class BrowserAdmin extends AdminFeature implements iAdminFeature {

	public string $featureName = "Browser";
	public string $featureId = "AdminBrowser";

	public function __construct() {
		parent::__construct();
		$this->SetClassPath(__DIR__);
	}

	public function Index() {
		$keys = KeyControl::GetAll();
		$selectedKeyId = !empty($_GET["key_id"]) ? (int)$_GET["key_id"] : null;
		$folders = $selectedKeyId ? FolderControl::GetWhere(["key_id" => $selectedKeyId]) : [];
		$files = $selectedKeyId ? FileControl::GetWhere(["key_id" => $selectedKeyId]) : [];
		include(__DIR__."/views/browse.php");
	}

	/** POST handler: delete a file from an operator context (no owning-key size credit needed to survive review, still applied) */
	public function DeleteFile() {
		$id = @$_POST["id"];
		$keyId = @$_POST["key_id"];
		if(!empty($id) && !empty($keyId)) {
			$key = new Key($keyId);
			$file = FileControl::GetForKey($key, (int)$id);
			FileControl::Delete($file, $key);
			AdminManager::Instance()->Log("file deleted via browser", $file->name);
		}
		header("Location: ".AdminUrls::Instance()->GetFeatureUrl($this->featureId, null, ["key_id" => $keyId]));
		exit;
	}

	/** POST handler: delete an empty, non-root folder */
	public function DeleteFolder() {
		$id = @$_POST["id"];
		$keyId = @$_POST["key_id"];
		if(!empty($id) && !empty($keyId)) {
			$key = new Key($keyId);
			$folder = FolderControl::GetForKey($key, (int)$id);
			FolderControl::AssertNotRoot($folder);
			FolderControl::AssertEmpty($folder);
			$folder->Delete();
			AdminManager::Instance()->Log("folder deleted via browser", $folder->name);
		}
		header("Location: ".AdminUrls::Instance()->GetFeatureUrl($this->featureId, null, ["key_id" => $keyId]));
		exit;
	}

	public function OrphanCheck() {
		$storage = StorageFactory::Instance()->Get();
		$allFiles = FileControl::GetAll();
		$missing = [];
		foreach($allFiles as $file) {
			if(!$storage->exists($file->storage_path)) {
				$missing[] = $file;
			}
		}
		include(__DIR__."/views/orphans.php");
	}

	/** POST handler: purge the DB row for a file whose storage object is confirmed missing */
	public function PurgeOrphan() {
		$id = @$_POST["id"];
		if(!empty($id)) {
			$file = new \MagratheaExplorer\File\File($id);
			$key = new Key($file->key_id);
			\MagratheaExplorer\File\FileTagControl::DetachAllForFile((int)$file->id);
			$file->Delete();
			$key->AdjustSize(-1 * (int)$file->size);
			AdminManager::Instance()->Log("orphan file row purged", $file->name);
		}
		header("Location: ".AdminUrls::Instance()->GetFeatureUrl($this->featureId, "OrphanCheck"));
		exit;
	}

}
