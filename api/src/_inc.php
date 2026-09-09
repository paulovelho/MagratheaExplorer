<?php
require "../vendor/autoload.php";

try {
	Magrathea2\MagratheaPHP::Instance()
		->MinVersion("2.3.0")
		->AppPath(realpath(dirname(__FILE__)))
		->AddCodeFolder(
			"admin",
			"admin/Browser",
			"admin/Storage",
			"shared",
			"error-manager",
		)
		->AddFeature("Key", "Folder", "File", "Storage")
		->Load();
} catch(Exception $ex) {
	\Magrathea2\p_r($ex);
}
