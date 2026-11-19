<?php

namespace MagratheaExplorer;

use Magrathea\Admin\AdminFeature;
use Magrathea\Admin\AdminManager;
use Magrathea\Admin\AdminUrls;
use Magrathea\Admin\iAdminFeature;
use MagratheaExplorer\File\FileControl;
use MagratheaExplorer\File\FileRenditionControl;
use MagratheaExplorer\Folder\FolderControl;
use MagratheaExplorer\Key\Key;
use MagratheaExplorer\Key\KeyControl;
use MagratheaExplorer\Processing\FileProcessing;
use MagratheaExplorer\Processing\ProcessingJobControl;
use MagratheaExplorer\Share\ShareControl;
use MagratheaExplorer\Storage\StorageFactory;
use MagratheaExplorer\Storage\StorageGarbage;

/**
 * Folder/file browser spanning all keys (lives at the admin/ level like Images3's
 * MediaManager, since it's not scoped to one feature), plus orphan cleanup: file and
 * rendition rows that point at a storage object that no longer exists in the active backend.
 */
class BrowserAdmin extends AdminFeature implements iAdminFeature {

	use \MagratheaExplorer\AdminPostOnly;

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
		$renditions = FileRenditionControl::GetForFiles(array_map(fn($f) => (int)$f->id, $files));
		include(__DIR__."/views/browse.php");
	}

	/**
	 * POST handler: new renditions from the key's current profile (queued for
	 * `cron.php --process`), like POST /file/:uuid/renditions.
	 */
	public function RegenerateFile() {
		$this->RefuseIfNotPost();
		$id = @$_POST["id"];
		$keyId = @$_POST["key_id"];
		$params = ["key_id" => $keyId];
		if(!empty($id) && !empty($keyId)) {
			$file = FileControl::GetForKey(new Key($keyId), (int)$id);
			try {
				FileProcessing::Regenerate($file);
				AdminManager::Instance()->Log("file renditions regenerated via browser", $file->name);
			} catch(\Throwable $ex) {
				// no processor configured, most likely: a flag, never a message, in the URL
				$params["regenerate_failed"] = 1;
			}
		}
		header("Location: ".AdminUrls::Instance()->GetFeatureUrl($this->featureId, null, $params));
		exit;
	}

	/** POST handler: delete a file from an operator context (no owning-key size credit needed to survive review, still applied) */
	public function DeleteFile() {
		$this->RefuseIfNotPost();
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
		$this->RefuseIfNotPost();
		$id = @$_POST["id"];
		$keyId = @$_POST["key_id"];
		if(!empty($id) && !empty($keyId)) {
			$key = new Key($keyId);
			$folder = FolderControl::GetForKey($key, (int)$id);
			FolderControl::AssertNotRoot($folder);
			FolderControl::AssertEmpty($folder);
			ShareControl::DeleteForFolder((int)$folder->id);
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
		$missingRenditions = [];
		foreach(FileRenditionControl::GetAll() as $rendition) {
			if(!$storage->exists($rendition->storage_path)) {
				$missingRenditions[] = $rendition;
			}
		}
		include(__DIR__."/views/orphans.php");
	}

	/** POST handler: purge the DB row for a file whose storage object is confirmed missing */
	public function PurgeOrphan() {
		$this->RefuseIfNotPost();
		$id = @$_POST["id"];
		if(!empty($id)) {
			$file = new \MagratheaExplorer\File\File($id);
			$key = new Key($file->key_id);
			\MagratheaExplorer\File\FileTagControl::DetachAllForFile((int)$file->id);
			// Raw row delete, bypassing FileControl entirely -- so it needs its own share, job and
			// rendition cleanup rather than inheriting DeleteFileAndStorage()'s (no FK cascades:
			// any of those rows would block the delete). The renditions' objects may well exist:
			// they're deleted too, best effort (a failed one is queued for retry).
			ShareControl::DeleteForFile((int)$file->id);
			ProcessingJobControl::DeleteForFile((int)$file->id);
			$renditions = FileRenditionControl::GetForFile((int)$file->id);
			FileRenditionControl::DeleteForFile((int)$file->id);
			StorageGarbage::Delete(StorageFactory::Instance()->Get(), array_map(fn($r) => (string)$r->storage_path, $renditions));
			$file->Delete();
			$key->AdjustSize(-1 * (int)$file->size);
			AdminManager::Instance()->Log("orphan file row purged", $file->name);
		}
		header("Location: ".AdminUrls::Instance()->GetFeatureUrl($this->featureId, "OrphanCheck"));
		exit;
	}

	/**
	 * POST handler: purge a rendition row whose object is confirmed missing. The file keeps its
	 * other renditions; regenerate it to make this one again.
	 */
	public function PurgeOrphanRendition() {
		$this->RefuseIfNotPost();
		$id = @$_POST["id"];
		if(!empty($id)) {
			$rendition = FileRenditionControl::GetRowWhere(["id" => (int)$id]);
			if($rendition !== null && !StorageFactory::Instance()->Get()->exists($rendition->storage_path)) {
				$rendition->Delete();
				AdminManager::Instance()->Log("orphan rendition row purged", $rendition->storage_path);
			}
		}
		header("Location: ".AdminUrls::Instance()->GetFeatureUrl($this->featureId, "OrphanCheck"));
		exit;
	}

}
