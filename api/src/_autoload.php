<?php
// The app's code folders for Magrathea's autoloader: no config, no DB. _inc.php builds the full
// bootstrap on top of this, and tests/bootstrap.php uses it alone, so there's one folder list.
// Prints nothing, catches nothing.
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
	->AddFeature("Key", "Folder", "File", "Storage", "Share");
