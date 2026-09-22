<?php

namespace MagratheaExplorer;

use Magrathea2\Config;
use Magrathea2\MagratheaApi;
use Magrathea2\MagratheaPHP;
use MagratheaExplorer\Key\KeyApi;
use MagratheaExplorer\Key\KeyAuthControl;
use MagratheaExplorer\Folder\FolderApi;
use MagratheaExplorer\File\FileApi;
use MagratheaExplorer\Share\ShareApi;
use MagratheaExplorer\Share\PublicShareApi;

class MagratheaExplorerApi extends MagratheaApi {

	const OPEN = false;
	const AUTHENTICATED = "IsAuthenticated";

	public function __construct() {
		$this->Initialize();
	}

	public function Initialize() {
		MagratheaPHP::Instance()->StartDb();
		$this->AllowAll();
		$this->AddAcceptHeaders([
			"Authorization",
			"Access-Control-Allow-Origin",
			"cache-control",
			"x-requested-with",
			"Content-type",
			"pragma", "expires",
		]);
		$this->DisableCache();
		$this->SetAuth();
		$this->SetUrl();
		$this->AddKey();
		$this->AddFolder();
		$this->AddFile();
		$this->AddShare();
		$this->GeneralApis();
	}

	private function SetAuth() {
		$authApi = new KeyAuthControl();
		$this->BaseAuthorization($authApi, self::AUTHENTICATED);
	}

	const API_PREFIX = "api/v1";

	private function SetUrl() {
		$url = Config::Instance()->Get("app_url");
		$this->SetAddress($url."/".self::API_PREFIX);
	}

	private function AddKey() {
		$api = new KeyApi();
		$this->Add("GET", "key", $api, "GetSelf", self::AUTHENTICATED);
		$this->Add("GET", "key/usage", $api, "GetUsage", self::AUTHENTICATED);
		$this->Add("DELETE", "key", $api, "Delete", self::AUTHENTICATED);
		$this->Add("POST", "key/cancel-deletion", $api, "CancelDeletion", self::AUTHENTICATED);
	}

	private function AddFolder() {
		$api = new FolderApi();
		$this->Add("GET", "folders", $api, "GetAll", self::AUTHENTICATED, "GET: parent_uuid=?");
		$this->Add("POST", "folders", $api, "Create", self::AUTHENTICATED);
		$this->Add("GET", "folder/:uuid", $api, "Get", self::AUTHENTICATED);
		$this->Add("PUT", "folder/:uuid", $api, "Update", self::AUTHENTICATED);
		$this->Add("DELETE", "folder/:uuid", $api, "Delete", self::AUTHENTICATED);
		$this->Add("GET", "folder/:uuid/size", $api, "GetSize", self::AUTHENTICATED);
	}

	private function AddFile() {
		$api = new FileApi();
		$this->Add("POST", "files", $api, "Upload", self::AUTHENTICATED);
		$this->Add("GET", "files", $api, "GetAll", self::AUTHENTICATED, "GET: folder_uuid=?, file_type=?, tag=?");
		$this->Add("GET", "file/:uuid", $api, "Get", self::AUTHENTICATED);
		$this->Add("PUT", "file/:uuid", $api, "Update", self::AUTHENTICATED);
		$this->Add("DELETE", "file/:uuid", $api, "Delete", self::AUTHENTICATED);
		$this->Add("POST", "file/:uuid/tags", $api, "AttachTag", self::AUTHENTICATED);
		$this->Add("DELETE", "file/:uuid/tags/:tag", $api, "DetachTag", self::AUTHENTICATED);
	}

	/**
	 * Two surfaces, deliberately under different literal prefixes. Route matching is
	 * positional and first-match-wins within one HTTP method (MagratheaApi::FindRoute()),
	 * so the owner's `shares`/`share/:uuid` and the public `shared/:uuid` can never
	 * shadow each other.
	 */
	private function AddShare() {
		$api = new ShareApi();
		$this->Add("POST", "shares", $api, "Create", self::AUTHENTICATED, "POST: file_uuid=? XOR folder_uuid=?");
		$this->Add("GET", "shares", $api, "GetAll", self::AUTHENTICATED, "GET: file_uuid=?, folder_uuid=?");
		$this->Add("DELETE", "share/:uuid", $api, "Delete", self::AUTHENTICATED);

		// Public: no Authorization header, the share uuid in the path IS the credential.
		$public = new PublicShareApi();
		$this->Add("GET", "shared/:uuid", $public, "Get", self::OPEN);
		$this->Add("GET", "shared/:uuid/folder/:folder_uuid", $public, "GetFolder", self::OPEN);
	}

	private function GeneralApis() {
		$this->Version();
		$this->HealthCheck(true);
		$systemApi = new SystemApi();
		$this->Add("GET", "settings", $systemApi, "GetSettings", self::OPEN);
		$this->Add("GET", "changelog", $systemApi, "GetChangelog", self::OPEN);
		$this->Add("GET", "error-codes", $systemApi, "GetErrorCodes", self::OPEN);
	}

	private function Version() {
		$this->Add("GET", "version", null, function($params) {
			return [
				"api" => "Magrathea Explorer",
				"version" => MagratheaPHP::Instance()->AppVersion(),
				"environment" => Config::Instance()->GetEnvironment(),
				"magrathea_version" => MagratheaPHP::Instance()->Version(),
			];
		}, self::OPEN);
	}

}
