<?php
// Shared bootstrap: setup only. It prints nothing and catches nothing -- the entry
// points log a failure and answer a generic error. Also loaded by vendor/bin/magrathea
// (composer.json's extra.magrathea.bootstrap), so every path here is absolute.
require __DIR__."/../vendor/autoload.php";

Magrathea\MagratheaPHP::Instance()
	->MinVersion("3.0.0")
	->AppPath(realpath(__DIR__))
	->AddRootCodeFolder(
		"admin",
		"admin/Browser",
		"admin/Storage",
		"admin/Import",
		"admin/Duplicates",
		"shared",
		"error-manager",
	)
	->AddFeature("Key", "Folder", "File", "Storage", "Share")
	->Prod()
	->Load()
	->Connect();
