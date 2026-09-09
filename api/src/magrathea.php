<?php

include("_inc.php");
include("admin/MagratheaExplorerAdmin.php");

use Magrathea2\Admin\AdminManager;
use MagratheaExplorer\MagratheaExplorerAdmin;

try {
	AdminManager::Instance()->Start(new MagratheaExplorerAdmin());
} catch(Exception $ex) {
	\Magrathea2\p_r($ex);
}
