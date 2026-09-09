<?php

namespace MagratheaExplorer;

use Magrathea2\Admin\AdminFeature;
use Magrathea2\Admin\AdminManager;
use Magrathea2\Admin\AdminUrls;
use Magrathea2\Admin\iAdminFeature;
use Magrathea2\Exceptions\MagratheaModelException;
use MagratheaExplorer\File\ImportPipeline;
use MagratheaExplorer\Folder\FolderControl;
use MagratheaExplorer\Key\Key;
use MagratheaExplorer\Key\KeyControl;

/**
 * Bulk-imports a directory already sitting on the server's local filesystem into one key
 * -- admin-only, there's no equivalent public API route (same reasoning as key creation,
 * see KeyAdmin). Runs synchronously in the request that triggers it; a key's
 * usage_limit/usage_limit_mb caps are deliberately bypassed for imports (see
 * Key::AssertCanImport()), since this is an operator-initiated bulk operation, not a
 * per-request client upload.
 */
class ImportAdmin extends AdminFeature implements iAdminFeature {

	public string $featureName = "Import";
	public string $featureId = "AdminImport";
	public $featureIcon = "folder-plus";

	public function __construct() {
		parent::__construct();
		$this->SetClassPath(__DIR__);
	}

	public function Index() {
		$keys = KeyControl::GetAll();
		$selectedKeyId = !empty($_GET["key_id"]) ? (int)$_GET["key_id"] : null;
		$folders = $selectedKeyId ? FolderControl::GetWhere(["key_id" => $selectedKeyId]) : [];
		$error = null;
		include(__DIR__."/views/index.php");
	}

	/** POST handler: validates input, runs the import synchronously, renders a result summary. */
	public function RunImport() {
		$keyId = (int)($_POST["key_id"] ?? 0);
		try {
			$key = !empty($keyId) ? new Key($keyId) : null;
		} catch(MagratheaModelException $ex) {
			$key = null;
		}
		if($key === null || empty($key->id)) {
			return $this->Reindex(null, "Select a valid key.");
		}

		$sourcePath = trim($_POST["path"] ?? "");
		$realPath = $sourcePath !== "" ? realpath($sourcePath) : false;
		if($realPath === false || !is_dir($realPath) || !is_readable($realPath)) {
			return $this->Reindex($key->id, "Path does not exist, or is not a readable directory on this server: ".$sourcePath);
		}

		try {
			$folderId = !empty($_POST["folder_id"]) ? (int)$_POST["folder_id"] : null;
			$destinationParent = $folderId !== null ? FolderControl::GetForKey($key, $folderId) : FolderControl::GetRoot($key);

			set_time_limit(0); // a directory tree can take far longer than a typical request timeout

			$stats = ImportPipeline::Run($key, $destinationParent, $realPath, false);
		} catch(\Throwable $ex) {
			return $this->Reindex($key->id, "Import failed: ".$ex->getMessage());
		}

		AdminManager::Instance()->Log("folder imported", $realPath, [
			"key" => $key->name,
			"files_imported" => $stats["files_imported"],
			"files_skipped" => count($stats["files_skipped"]),
		]);

		include(__DIR__."/views/result.php");
	}

	private function Reindex(?int $selectedKeyId, string $error) {
		$keys = KeyControl::GetAll();
		$folders = $selectedKeyId ? FolderControl::GetWhere(["key_id" => $selectedKeyId]) : [];
		include(__DIR__."/views/index.php");
	}

}
