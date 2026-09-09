<?php

namespace MagratheaExplorer;

use Magrathea2\Config;
use Magrathea2\MagratheaApi;
use Magrathea2\MagratheaPHP;
use MagratheaExplorer\Key\KeyApi;
use MagratheaExplorer\Key\KeyAuthControl;
use MagratheaExplorer\Folder\FolderApi;
use MagratheaExplorer\File\FileApi;

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
		$this->Add("GET", "folders", $api, "GetAll", self::AUTHENTICATED, "GET: parent_id=?");
		$this->Add("POST", "folders", $api, "Create", self::AUTHENTICATED);
		$this->Add("GET", "folder/:id", $api, "Get", self::AUTHENTICATED);
		$this->Add("PUT", "folder/:id", $api, "Update", self::AUTHENTICATED);
		$this->Add("DELETE", "folder/:id", $api, "Delete", self::AUTHENTICATED);
		$this->Add("GET", "folder/:id/size", $api, "GetSize", self::AUTHENTICATED);
	}

	private function AddFile() {
		$api = new FileApi();
		$this->Add("POST", "files", $api, "Upload", self::AUTHENTICATED);
		$this->Add("GET", "files", $api, "GetAll", self::AUTHENTICATED, "GET: folder_id=?, file_type=?, tag=?");
		$this->Add("GET", "file/:id", $api, "Get", self::AUTHENTICATED);
		$this->Add("PUT", "file/:id", $api, "Update", self::AUTHENTICATED);
		$this->Add("DELETE", "file/:id", $api, "Delete", self::AUTHENTICATED);
		$this->Add("POST", "file/:id/tags", $api, "AttachTag", self::AUTHENTICATED);
		$this->Add("DELETE", "file/:id/tags/:tag", $api, "DetachTag", self::AUTHENTICATED);
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
