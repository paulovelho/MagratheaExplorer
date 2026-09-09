<?php

namespace MagratheaExplorer;

include("api.php");

use Magrathea2\Admin\Admin;
use Magrathea2\Admin\AdminMenu;
use Magrathea2\Admin\Features\ApiExplorer\ApiExplorer;
use MagratheaExplorer\Key\KeyAdmin;
use MagratheaExplorer\Key\ScheduledDeletionAdmin;

class MagratheaExplorerAdmin extends Admin implements \Magrathea2\Admin\iAdmin {

	private $features = [];

	public function Initialize() {
		$this->SetTitle("Magrathea Explorer Admin");
		$this->SetPrimaryColor("#0e6b91");
		parent::Initialize();
	}

	public function Auth($user): bool {
		return !empty($user->id);
	}

	public function LoadApi() {
		$api = new MagratheaExplorerApi();
		$apiFeature = new ApiExplorer();
		$apiFeature->SetApi($api);
		$this->features["api"] = $apiFeature;
		$this->AddFeature($apiFeature);
	}

	public function SetFeatures() {
		parent::SetFeatures();
		$this->LoadApi();
		$this->features["key"] = new KeyAdmin();
		$this->features["scheduled-deletion"] = new ScheduledDeletionAdmin();
		$this->features["browser"] = new BrowserAdmin();
		$this->features["storage"] = new StorageAdmin();
		$this->features["import"] = new ImportAdmin();
		$this->AddFeaturesArray($this->features);
	}

	public function BuildMenu(): AdminMenu {
		$menu = new AdminMenu();
		$menu
			->Add($this->features["browser"]->GetMenuItem())
			->Add($this->features["import"]->GetMenuItem())
			->Add($this->features["key"]->GetMenuItem())
			->Add($this->features["scheduled-deletion"]->GetMenuItem())
			->Add($this->features["storage"]->GetMenuItem())

			->Add($menu->CreateTitle("Api"))
			->Add($this->features["api"]->GetMenuItem())

			->Add($menu->CreateSpace())
			->Add(["title" => "Magrathea", "type" => "main"]);
		$this->AddMagratheaMenu($menu);

		$menu->Add($menu->GetLogoutMenuItem());
		return $menu;
	}

}
