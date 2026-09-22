<?php
// Serves the explorer app's index.html (Vite's build output in dist/) with
// ConfigApp's `app_name` injected into <title> and <meta name="application-name">,
// so the name is right from the first paint and changes in the admin show up on
// the next page load -- no rebuild needed. dist/.htaccess (from app/public/.htaccess)
// and docker/caddy/site.caddy route every /app request that isn't a real asset here.
include("_inc.php");

use MagratheaExplorer\SystemApi;

// Prod: api/src/app is a symlink to dist/. Docker/Caddy: dist/ is mounted at
// /var/www/html/app, a sibling of api/src.
$candidates = [
	__DIR__."/app/index.html",
	__DIR__."/../app/index.html",
];
$indexFile = null;
foreach($candidates as $candidate) {
	if(is_file($candidate)) {
		$indexFile = $candidate;
		break;
	}
}
if(!$indexFile) {
	http_response_code(500);
	echo "Explorer app not built: dist/index.html not found.";
	exit;
}

// Never let a config/database hiccup take the whole app down -- fall back to the default name.
try {
	\Magrathea2\MagratheaPHP::Instance()->Connect();
	$appName = SystemApi::AppName();
} catch(\Throwable $ex) {
	$appName = SystemApi::DEFAULT_APP_NAME;
}
$safeName = htmlspecialchars($appName, ENT_QUOTES);

$html = file_get_contents($indexFile);
$html = preg_replace_callback('#<title>.*?</title>#s', fn() => "<title>{$safeName}</title>", $html, 1);
$html = preg_replace_callback(
	'#<meta name="application-name" content="[^"]*"\s*/?>#',
	fn() => "<meta name=\"application-name\" content=\"{$safeName}\">",
	$html,
	1
);

// The shell references hashed assets, so it must always be revalidated after a deploy.
header("Content-Type: text/html; charset=UTF-8");
header("Cache-Control: no-cache");
echo $html;
