<?php

namespace MagratheaExplorer;

use Magrathea2\Admin\AdminFeature;
use Magrathea2\Admin\iAdminFeature;
use MagratheaExplorer\File\FileControl;
use MagratheaExplorer\Key\KeyControl;
use MagratheaExplorer\Storage\StorageConfig;
use MagratheaExplorer\Storage\StorageException;
use MagratheaExplorer\Storage\StorageFactory;

/**
 * Read-only overview of the active storage backend (local disk vs S3/R2): which driver
 * is selected, its config, whether it's actually reachable, and aggregate usage across
 * all keys. storage.conf is a deploy-time file (see StorageConfig's docblock) -- switching
 * backends is a deploy decision, not something this admin page edits live -- so this page
 * only displays state and explains how to change it.
 */
class StorageAdmin extends AdminFeature implements iAdminFeature {

	public string $featureName = "Storage";
	public string $featureId = "AdminStorage";
	public $featureIcon = "hdd-stack";

	public function __construct() {
		parent::__construct();
		$this->SetClassPath(__DIR__);
	}

	public function Index() {
		$driver = StorageConfig::GetDriver();
		$driverLabel = $this->DriverLabel($driver);

		$connectionError = null;
		try {
			StorageFactory::Instance()->Get();
		} catch(StorageException $ex) {
			$connectionError = $ex->getMessage();
		}

		$configRows = $this->ConfigRows($driver);

		$fileCount = (int) FileControl::QueryOne("SELECT COUNT(*) FROM files");
		$keyCount = (int) KeyControl::QueryOne("SELECT COUNT(*) FROM access_keys");
		$totalSize = (int) KeyControl::QueryOne("SELECT COALESCE(SUM(total_size), 0) FROM access_keys");

		include(__DIR__."/views/index.php");
	}

	private function DriverLabel(string $driver): string {
		if($driver === "s3") return "S3 / R2";
		if($driver === "local") return "Local disk";
		return $driver;
	}

	/** @return array<array{label:string,value:string}> */
	private function ConfigRows(string $driver): array {
		if($driver === "s3") {
			return [
				["label" => "Endpoint", "value" => StorageConfig::GetS3Endpoint() ?: "(not set)"],
				["label" => "Region", "value" => StorageConfig::GetS3Region()],
				["label" => "Bucket", "value" => StorageConfig::GetS3Bucket() ?: "(not set)"],
				["label" => "Path-style URLs", "value" => StorageConfig::GetS3PathStyle() ? "yes" : "no"],
				["label" => "Public URL base", "value" => StorageConfig::GetS3PublicUrl() ?: "(falls back to endpoint/bucket)"],
				["label" => "Access key", "value" => $this->Mask(StorageConfig::GetS3AccessKey())],
				["label" => "Secret key", "value" => $this->Mask(StorageConfig::GetS3SecretKey())],
			];
		}
		return [
			["label" => "Local path", "value" => StorageConfig::GetLocalPath() ?: "(not set)"],
			["label" => "Local URL", "value" => StorageConfig::GetLocalUrl() ?: "(not set)"],
		];
	}

	private function Mask(?string $value): string {
		if(empty($value)) return "(not set)";
		$len = strlen($value);
		if($len <= 4) return str_repeat("*", $len);
		return substr($value, 0, 2).str_repeat("*", $len - 4).substr($value, -2);
	}

}
