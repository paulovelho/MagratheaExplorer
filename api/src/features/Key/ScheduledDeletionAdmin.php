<?php

namespace MagratheaExplorer\Key;

use Magrathea2\Admin\AdminFeature;
use Magrathea2\Admin\AdminManager;
use Magrathea2\Admin\AdminUrls;
use Magrathea2\Admin\iAdminFeature;

class ScheduledDeletionAdmin extends AdminFeature implements iAdminFeature {

	public string $featureName = "Scheduled Deletions";
	public string $featureId = "AdminScheduledDeletion";

	public function __construct() {
		parent::__construct();
		$this->SetClassPath(__DIR__);
	}

	public function Index() {
		include(__DIR__."/admin/scheduled-deletions.php");
	}

	/** POST handler: cancel a pending deletion (the caller's own /key/cancel-deletion, done by an operator) */
	public function Cancel() {
		$id = @$_POST["id"];
		if(!empty($id)) {
			$deletion = new ScheduledDeletion($id);
			$key = $deletion->GetKey();
			ScheduledDeletionControl::Cancel($key);
			AdminManager::Instance()->Log("scheduled deletion cancelled", $key->name);
		}
		header("Location: ".AdminUrls::Instance()->GetFeatureUrl($this->featureId));
		exit;
	}

	/** POST handler: run this key's deletion right now, bypassing the remaining wait */
	public function ForceExecute() {
		$id = @$_POST["id"];
		if(!empty($id)) {
			$deletion = new ScheduledDeletion($id);
			$key = $deletion->GetKey();
			AdminManager::Instance()->Log("scheduled deletion force-executed", $key->name);
			KeyControl::DeleteKeyNow($key);
		}
		header("Location: ".AdminUrls::Instance()->GetFeatureUrl($this->featureId));
		exit;
	}

}
