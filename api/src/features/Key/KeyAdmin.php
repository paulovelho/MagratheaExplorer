<?php

namespace MagratheaExplorer\Key;

use Magrathea2\Admin\AdminFeature;
use Magrathea2\Admin\AdminManager;
use Magrathea2\Admin\AdminUrls;
use Magrathea2\Admin\iAdminFeature;
use Magrathea2\Exceptions\MagratheaModelException;

/**
 * The only key-creation path in the whole product (see plan.md §4 -- no public
 * POST /key/create). Plain server-rendered forms (no custom JS/AJAX), matching this
 * being a developer-only tool.
 */
class KeyAdmin extends AdminFeature implements iAdminFeature {

	public string $featureName = "Keys";
	public string $featureId = "AdminKey";

	public function __construct() {
		parent::__construct();
		$this->SetClassPath(__DIR__);
	}

	public function Index() {
		include(__DIR__."/admin/index.php");
	}

	public function List() {
		$keys = KeyControl::GetAll();
		include(__DIR__."/admin/list.php");
	}

	public function Form() {
		$id = @$_GET["id"];
		try {
			$key = !empty($id) ? new Key($id) : new Key();
		} catch(MagratheaModelException $ex) {
			$key = null;
		}
		include(__DIR__."/admin/form.php");
	}

	/** POST handler: create (name only) or update (editable fields, never uuid/uses/total_size) */
	public function Save() {
		$id = @$_POST["id"];
		if(!empty($id)) {
			$key = new Key($id);
			KeyControl::UpdateFields($key, $_POST);
			AdminManager::Instance()->Log("key updated", $key->name);
		} else {
			$key = KeyControl::Create($_POST["name"] ?? "");
			KeyControl::UpdateFields($key, $_POST);
			AdminManager::Instance()->Log("key created", $key->name);
		}
		header("Location: ".AdminUrls::Instance()->GetFeatureUrl($this->featureId));
		exit;
	}

	/** POST handler: cascading delete right now, bypassing the 14-day scheduled wait. */
	public function ForceDeleteNow() {
		$id = @$_POST["id"];
		if(!empty($id)) {
			$key = new Key($id);
			AdminManager::Instance()->Log("key force-deleted", $key->name);
			KeyControl::DeleteKeyNow($key);
		}
		header("Location: ".AdminUrls::Instance()->GetFeatureUrl($this->featureId));
		exit;
	}

}
