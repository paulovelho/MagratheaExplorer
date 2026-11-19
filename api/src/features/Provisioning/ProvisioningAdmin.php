<?php

namespace MagratheaExplorer\Provisioning;

use Magrathea\Admin\AdminFeature;
use Magrathea\Admin\AdminManager;
use Magrathea\Admin\AdminUrls;
use Magrathea\Admin\iAdminFeature;
use MagratheaExplorer\Key\KeyAdmin;
use MagratheaExplorer\Key\KeySettings;
use MagratheaExplorer\Key\KeySettingsException;

/**
 * admin → Provisioning: the secret POST /provision/keys takes (generate, rotate, turn off), how
 * many keys it may create, and the settings every key it creates gets. The secret is shown once,
 * on the page after it's generated: only its hash is stored. Same flash pattern as KeyAdmin.
 */
class ProvisioningAdmin extends AdminFeature implements iAdminFeature {

	use \MagratheaExplorer\AdminPostOnly;

	public string $featureName = "Provisioning";
	public string $featureId = "AdminProvisioning";

	const FORM_FLASH = "explorer_provisioning_form_error";
	const SECRET_FLASH = "explorer_provisioning_secret";

	public function __construct() {
		parent::__construct();
		$this->SetClassPath(__DIR__);
	}

	public function Index() {
		$provisioning = ProvisioningControl::Get() ?? new Provisioning();
		$keyCount = ProvisioningControl::CountKeys();
		$pendingCount = ProvisioningControl::CountPendingDeletion();
		$formError = KeyAdmin::TakeFlash(self::FORM_FLASH);
		if(!is_array($formError) || ($formError["field"] ?? null) !== @$_GET["invalid"]) $formError = null;
		$secret = KeyAdmin::TakeFlash(self::SECRET_FLASH);
		if(!is_string($secret)) $secret = null;
		include(__DIR__."/admin/index.php");
	}

	/** POST handler: `max_keys` and the settings provisioned keys get. Existing keys don't change. */
	public function Save() {
		$this->RefuseIfNotPost();
		$maxKeys = trim((string)($_POST["max_keys"] ?? ""));
		if($maxKeys !== "" && (preg_match('/^\d+$/', $maxKeys) !== 1 || strlen(ltrim($maxKeys, "0")) > 10 || (int)$maxKeys > KeySettings::MAX_COUNT)) {
			$this->BackToForm("max_keys", "Max keys takes a whole number (0 or more), or nothing for no limit.");
		}
		try {
			$settings = KeySettings::FromForm($_POST);
		} catch(KeySettingsException $ex) {
			$this->BackToForm($ex->field, $ex->getMessage(), (string)($_POST[$ex->field] ?? ""));
		}
		$provisioning = ProvisioningControl::GetOrCreate();
		$provisioning->max_keys = $maxKeys !== "" ? (int)$maxKeys : null;
		KeySettings::Apply($provisioning, $settings);
		$provisioning->Update();
		AdminManager::Instance()->Log("provisioning settings saved", "max_keys=".($provisioning->max_keys ?? "none"));
		$this->Back();
	}

	/** POST handler: a new secret, shown once. The previous one stops working right away. */
	public function GenerateSecret() {
		$this->RefuseIfNotPost();
		$secret = ProvisioningControl::NewSecret();
		AdminManager::Instance()->Log("provisioning secret generated", null);
		$_SESSION[self::SECRET_FLASH] = $secret;
		$this->Back();
	}

	/** POST handler: no secret, so POST /provision/keys refuses everything. Existing keys keep working. */
	public function TurnOff() {
		$this->RefuseIfNotPost();
		ProvisioningControl::TurnOff();
		AdminManager::Instance()->Log("provisioning turned off", null);
		$this->Back();
	}

	private function Back(): never {
		header("Location: ".AdminUrls::Instance()->GetFeatureUrl($this->featureId));
		exit;
	}

	private function BackToForm(string $field, string $message, ?string $value = null): never {
		$_SESSION[self::FORM_FLASH] = ["field" => $field, "message" => $message, "value" => $value];
		header("Location: ".AdminUrls::Instance()->GetFeatureUrl($this->featureId, null, ["invalid" => $field]));
		exit;
	}

}
