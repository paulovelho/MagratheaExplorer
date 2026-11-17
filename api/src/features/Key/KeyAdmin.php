<?php

namespace MagratheaExplorer\Key;

use Magrathea\Admin\AdminFeature;
use Magrathea\Admin\AdminManager;
use Magrathea\Admin\AdminUrls;
use Magrathea\Admin\iAdminFeature;
use Magrathea\Exceptions\MagratheaModelException;

/**
 * The only key-creation path in the whole product (see plan.md §4 -- no public
 * POST /key/create). Plain server-rendered forms (no custom JS/AJAX), matching this
 * being a developer-only tool.
 */
class KeyAdmin extends AdminFeature implements iAdminFeature {

	use \MagratheaExplorer\AdminPostOnly;

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
		$this->RefuseIfNotPost();
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
		$this->RefuseIfNotPost();
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
