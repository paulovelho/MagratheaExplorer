<?php

use Magrathea\Admin\AdminManager;
use Magrathea\MagratheaPHP;
use MagratheaExplorer\MagratheaExplorerAdmin;

try {
	require __DIR__."/_inc.php";
	require_once __DIR__."/admin/MagratheaExplorerAdmin.php";
	MagratheaPHP::Instance()->StartSession();
	AdminManager::Instance()->Start(new MagratheaExplorerAdmin());
} catch(\Throwable $ex) {
	// Never print the exception: it carries paths, SQL and arguments.
	error_log((string) $ex);
	http_response_code(500);
	echo "Internal Server Error";
}
