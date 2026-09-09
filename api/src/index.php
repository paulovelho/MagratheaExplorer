<?php

// REQUEST_URI holds the original URL before mod_rewrite transforms it,
// so we parse its query string to recover params that rewrite rules may drop.
$_uriQuery = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
if ($_uriQuery) {
	parse_str($_uriQuery, $_uriParams);
	$_GET = array_merge($_GET, $_uriParams);
}
unset($_uriQuery, $_uriParams);

include("_inc.php");
include("api.php");

$api = new MagratheaExplorer\MagratheaExplorerApi();
$api->Run();
