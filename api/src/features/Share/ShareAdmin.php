<?php

namespace MagratheaExplorer\Share;

use Magrathea2\Admin\AdminFeature;
use Magrathea2\Admin\AdminManager;
use Magrathea2\Admin\AdminUrls;
use Magrathea2\Admin\iAdminFeature;

/**
 * Operator view of every share link in the system, across all keys, plus a kill switch.
 * Server-rendered forms, no AJAX -- the KeyAdmin pattern.
 *
 * Stays on int ids (the delete form posts share->id, not the uuid): plan_refactor.md §0
 * deliberately left /admin.php on int ids, and posting the uuid would put the link secret
 * in an operator's browser history for no gain.
 */
class ShareAdmin extends AdminFeature implements iAdminFeature {

	public string $featureName = "Shares";
	public string $featureId = "AdminShare";
	public $featureIcon = "link";

	public function __construct() {
		parent::__construct();
		$this->SetClassPath(__DIR__);
	}

	public function Index() {
		$shares = ShareControl::GetSimpleWhere("1 = 1 ORDER BY `created_at` DESC");
		include(__DIR__."/admin/list.php");
	}

	/** POST handler: delete one share link. The target file/folder is untouched. */
	public function Delete() {
		$id = @$_POST["id"];
		if(!empty($id)) {
			$share = new Share($id);
			AdminManager::Instance()->Log("share link deleted", $share->TargetName());
			ShareControl::Delete($share);
		}
		header("Location: ".AdminUrls::Instance()->GetFeatureUrl($this->featureId));
		exit;
	}

}
