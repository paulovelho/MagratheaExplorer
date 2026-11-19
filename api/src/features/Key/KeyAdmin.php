<?php

namespace MagratheaExplorer\Key;

use Magrathea\Admin\AdminFeature;
use Magrathea\Admin\AdminManager;
use Magrathea\Admin\AdminUrls;
use Magrathea\Admin\iAdminFeature;
use Magrathea\DB\Query;
use Magrathea\Exceptions\MagratheaModelException;

/**
 * Keys in the admin: list, create, edit, rotate a secret, force-delete. Keys are also created by
 * POST /provision/keys (see Provisioning); the list tells the two apart. Plain server-rendered
 * forms (no custom JS/AJAX), matching this being a developer-only tool.
 *
 * Secrets are stored only as hashes (1.5.0): a key's secret is shown once, on the page after it
 * was created or rotated, and never again.
 */
class KeyAdmin extends AdminFeature implements iAdminFeature {

	use \MagratheaExplorer\AdminPostOnly;

	public string $featureName = "Keys";
	public string $featureId = "AdminKey";

	const PAGE_SIZE = 50;
	/** The list's filter (`?origin=`): every key, the admin's, or provisioned ones. */
	const ORIGINS = ["all", "admin", "provisioned"];

	/** Session key of a refused form field: {field, message, value}, shown once by the form it redirects to. */
	const FORM_FLASH = "explorer_key_form_error";
	/**
	 * Session key of a secret just made: {key_id, secret}, shown once by that key's form and
	 * removed from the session as it's read. It stays there for one redirect.
	 */
	const SECRET_FLASH = "explorer_key_secret";

	public function __construct() {
		parent::__construct();
		$this->SetClassPath(__DIR__);
	}

	public function Index() {
		include(__DIR__."/admin/index.php");
	}

	public function List() {
		$origin = self::Origin();
		$page = max(0, (int)($_GET["page"] ?? 0));
		$query = Query::Select()->Obj(new Key())->Order("`id` DESC");
		if($origin === "provisioned") $query->Where(["provisioned" => 1]);
		if($origin === "admin") $query->Where(["provisioned" => 0]);
		// with the total: MagratheaPHP 3.0.0's GetPagination() without it skips a row at every
		// page boundary (it pages by limit + 1)
		$keys = KeyControl::GetPagination($query, $page, self::PAGE_SIZE, true);
		include(__DIR__."/admin/list.php");
	}

	public function Form() {
		$id = @$_GET["id"];
		try {
			$key = !empty($id) ? new Key($id) : new Key();
		} catch(MagratheaModelException $ex) {
			$key = null;
		}
		// both shown once: a reload of the form doesn't show them again
		$formError = self::TakeFlash(self::FORM_FLASH);
		if(!is_array($formError) || ($formError["field"] ?? null) !== @$_GET["invalid"]) $formError = null;
		$secretFlash = self::TakeFlash(self::SECRET_FLASH);
		$secret = is_array($secretFlash) && $key !== null && $key->id && (int)($secretFlash["key_id"] ?? 0) === (int)$key->id
			? (string)$secretFlash["secret"]
			: null;
		include(__DIR__."/admin/form.php");
	}

	/**
	 * POST handler: create or update. Every field is parsed before anything is saved: a refused one
	 * sends the form back (this action renders outside the admin layout) with a flag in its URL and
	 * the message in the session, never in the URL. A new key's form then shows its secret, once.
	 */
	public function Save() {
		$this->RefuseIfNotPost();
		$id = !empty($_POST["id"]) ? (int)$_POST["id"] : null;
		$name = KeyControl::NormalizeName($_POST["name"] ?? null);
		if($name === null) {
			$this->BackToForm($id, "name", "A key needs a name: 1 to ".KeyControl::NAME_MAX_LENGTH." characters, without control characters.");
		}
		if(KeyControl::NameTaken($name, $id)) {
			$this->BackToForm($id, "name", "Another key is already named \"".$name."\": names are unique across the instance.");
		}
		try {
			$fields = KeySettings::FromForm($_POST);
		} catch(KeySettingsException $ex) {
			$this->BackToForm($id, $ex->field, $ex->getMessage(), (string)($_POST[$ex->field] ?? ""));
		}
		$expiration = trim((string)($_POST["expiration"] ?? ""));
		if($expiration !== "" && !self::IsDate($expiration)) {
			$this->BackToForm($id, "expiration", "Expiration takes a date (YYYY-MM-DD), or nothing for none.");
		}
		$fields["expiration"] = $expiration !== "" ? $expiration : null;
		if(isset($_POST["active"])) $fields["active"] = !empty($_POST["active"]);

		if($id !== null) {
			$key = new Key($id);
			KeyControl::UpdateFields($key, $name, $fields);
			AdminManager::Instance()->Log("key updated", $key->name);
			header("Location: ".AdminUrls::Instance()->GetFeatureUrl($this->featureId));
			exit;
		}
		[$key, $secret] = KeyControl::Create($name, $fields);
		AdminManager::Instance()->Log("key created", $key->name);
		$this->ShowSecretOnce($key, $secret);
	}

	/** POST handler: a new secret for the key. The old one stops working right away. */
	public function RotateSecret() {
		$this->RefuseIfNotPost();
		$key = new Key((int)($_POST["id"] ?? 0));
		$secret = KeyControl::RotateSecret($key);
		AdminManager::Instance()->Log("key secret rotated", $key->name);
		$this->ShowSecretOnce($key, $secret);
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

	/** The list's `?origin=` filter, or "all". */
	public static function Origin(): string {
		$origin = $_GET["origin"] ?? "all";
		return in_array($origin, self::ORIGINS, true) ? $origin : "all";
	}

	private function ShowSecretOnce(Key $key, string $secret): never {
		$_SESSION[self::SECRET_FLASH] = ["key_id" => (int)$key->id, "secret" => $secret];
		header("Location: ".AdminUrls::Instance()->GetFeatureUrl($this->featureId, "Form", ["id" => (int)$key->id]));
		exit;
	}

	private function BackToForm(?int $id, string $field, string $message, ?string $value = null): never {
		$_SESSION[self::FORM_FLASH] = ["field" => $field, "message" => $message, "value" => $value];
		$params = ["invalid" => $field];
		if($id !== null) $params["id"] = $id;
		header("Location: ".AdminUrls::Instance()->GetFeatureUrl($this->featureId, "Form", $params));
		exit;
	}

	/** Reads a session flash and removes it. */
	public static function TakeFlash(string $name): mixed {
		$value = $_SESSION[$name] ?? null;
		unset($_SESSION[$name]);
		return $value;
	}

	private static function IsDate(string $value): bool {
		return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
	}

}
