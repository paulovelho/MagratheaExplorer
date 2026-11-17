<?php
/**
 * MagratheaExplorer: DreamHost feasibility check (plan-1.5.0-tier1.md §1.2).
 *
 * Standalone: no Explorer code, no composer, no database, nothing outside --workdir is
 * written. It answers one question before the processor is built: can this server turn a
 * ~105 MP RGBA PNG into a 1200 px webp, from cron, under the process watcher, and with
 * which engine (Imagick or pyvips)?
 *
 * On the server:
 *
 *   ls -d /usr/local/php8*            DreamHost's per-version PHP builds: use the site's version
 *   PHP=/usr/local/php84/bin/php
 *
 *   $PHP api/processor/diag/dreamhost-check.php
 *       Quick checks only (seconds, safe to run interactively).
 *
 *   $PHP /home/USER/explorer/api/processor/diag/dreamhost-check.php --heavy > /home/USER/dreamhost-check.log 2>&1
 *       The real test. Run it FROM CRON (a one-off entry a few minutes ahead, then delete it):
 *       cron is where the process watcher and the CLI limits that matter apply.
 *
 * Options:
 *   --heavy             generate a test PNG and run the resize through every available engine
 *   --input=FILE        use this PNG (e.g. a real print file) instead of generating one; implies --heavy
 *   --megapixels=N      size of the generated PNG (default 105)
 *   --bit-depth=8|16    bit depth of the generated PNG (default 8)
 *   --size=N            rendition bounding box (default 1200)
 *   --memory-mb=N       Imagick memory limit (default 128)
 *   --map-mb=N          Imagick map limit (default 256)
 *   --disk-mb=N         Imagick disk limit (default 4096)
 *   --timeout=N         wall-clock limit per engine run, in seconds (default 900)
 *   --workdir=DIR       scratch folder (default api/tmp/dreamhost-check, inside the account)
 *   --also-unlimited    also run Imagick with no resource limits (expect the watcher to kill it)
 *   --install-pyvips    run `python3 -m pip install --user "pyvips[binary]"` if pyvips is missing
 *   --python=PATH       python to use (e.g. a venv's bin/python) instead of python3 from PATH
 *   --skip-imagick      don't run the Imagick resize
 *   --skip-pyvips       don't run the pyvips resize
 *   --keep              keep the generated PNG and the outputs
 *
 * The report is printed and saved as report.json in the workdir.
 */

if (PHP_SAPI !== "cli") {
	http_response_code(404);
	exit;
}
error_reporting(E_ALL);
set_time_limit(0);

$opt = getopt("", [
	"heavy", "input:", "megapixels:", "bit-depth:", "size:", "memory-mb:", "map-mb:", "disk-mb:",
	"timeout:", "workdir:", "also-unlimited", "install-pyvips", "python:", "skip-imagick", "skip-pyvips", "keep", "help",
]);
if (isset($opt["help"])) {
	preg_match('#/\*\*(.*?)\*/#s', (string)file_get_contents(__FILE__), $m);
	echo preg_replace('/^\s*\* ?/m', "", $m[1] ?? "")."\n";
	exit(0);
}

$cfg = [
	"heavy" => isset($opt["heavy"]) || isset($opt["input"]),
	"input" => $opt["input"] ?? null,
	"megapixels" => max(1, (float)($opt["megapixels"] ?? 105)),
	"bit_depth" => (int)($opt["bit-depth"] ?? 8) === 16 ? 16 : 8,
	"size" => max(16, (int)($opt["size"] ?? 1200)),
	"memory_mb" => max(16, (int)($opt["memory-mb"] ?? 128)),
	"map_mb" => max(16, (int)($opt["map-mb"] ?? 256)),
	"disk_mb" => max(64, (int)($opt["disk-mb"] ?? 4096)),
	"timeout" => max(10, (int)($opt["timeout"] ?? 900)),
	"workdir" => rtrim($opt["workdir"] ?? (dirname(__DIR__, 2)."/tmp/dreamhost-check"), "/"),
	"also_unlimited" => isset($opt["also-unlimited"]),
	"install_pyvips" => isset($opt["install-pyvips"]),
	"python" => $opt["python"] ?? null,
	"skip_imagick" => isset($opt["skip-imagick"]),
	"skip_pyvips" => isset($opt["skip-pyvips"]),
	"keep" => isset($opt["keep"]),
];

$report = [
	"started_at" => date("c"),
	"host" => gethostname(),
	"options" => $cfg,
	"environment" => [],
	"imagick" => [],
	"policy_override" => null,
	"python" => [],
	"heavy" => null,
	"verdict" => [],
];

// ---------------------------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------------------------

function out(string $line = ""): void {
	echo $line."\n";
	@ob_flush();
	flush();
}

function section(string $title): void {
	out();
	out("== ".$title);
}

function kv(string $label, $value): void {
	if (is_bool($value)) $value = $value ? "yes" : "no";
	if ($value === null) $value = "-";
	if (is_array($value)) $value = json_encode($value, JSON_UNESCAPED_SLASHES);
	out("  ".str_pad($label." ", 34, ".")." ".$value);
}

function mb(?int $bytes): string {
	if ($bytes === null) return "-";
	return number_format($bytes / 1048576, 1)." MB";
}

function kbToMb(?int $kb): string {
	return $kb === null ? "-" : number_format($kb / 1024, 1)." MB";
}

function functionUsable(string $name): bool {
	if (!function_exists($name)) return false;
	$disabled = array_map("trim", explode(",", (string)ini_get("disable_functions")));
	return !in_array($name, $disabled, true);
}

function procRssKb(int $pid): ?int {
	$status = @file_get_contents("/proc/".$pid."/status");
	if ($status !== false && preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $m)) return (int)$m[1];
	return null;
}

/** First `python3` found on PATH (plus the usual fixed locations), or null. */
function findExecutable(string $name): ?string {
	$dirs = array_merge(explode(":", (string)getenv("PATH")), ["/usr/local/bin", "/usr/bin", "/bin"]);
	foreach (array_unique($dirs) as $dir) {
		if ($dir === "") continue;
		$path = rtrim($dir, "/")."/".$name;
		if (is_file($path) && is_executable($path)) return $path;
	}
	return null;
}

/**
 * Runs $argv (an array: no shell) with $stdin, a wall-clock timeout, and RSS polling of the
 * child every 200 ms. A child killed by someone else (the process watcher) comes back with
 * signaled=true and timed_out=false.
 */
function runChild(array $argv, array $env, string $stdin, int $timeout): array {
	$descriptors = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];
	$start = microtime(true);
	$proc = @proc_open($argv, $descriptors, $pipes, null, $env);
	if (!is_resource($proc)) {
		return ["started" => false, "error" => "proc_open failed: ".(error_get_last()["message"] ?? "unknown")];
	}
	if ($stdin !== "") fwrite($pipes[0], $stdin);
	fclose($pipes[0]);
	stream_set_blocking($pipes[1], false);
	stream_set_blocking($pipes[2], false);

	$open = [1 => $pipes[1], 2 => $pipes[2]];
	$buffers = [1 => "", 2 => ""];
	$pid = (int)proc_get_status($proc)["pid"];
	$peakRss = 0;
	$timedOut = false;

	while (true) {
		if ($open) {
			$read = array_values($open);
			$write = null;
			$except = null;
			if (@stream_select($read, $write, $except, 0, 200000) > 0) {
				foreach ($read as $stream) {
					$fd = array_search($stream, $open, true);
					$chunk = fread($stream, 65536);
					if ($chunk === "" || $chunk === false) {
						if (feof($stream)) {
							fclose($stream);
							unset($open[$fd]);
						}
						continue;
					}
					$buffers[$fd] .= $chunk;
					if (strlen($buffers[$fd]) > 262144) $buffers[$fd] = substr($buffers[$fd], -131072);
				}
			}
		} else {
			usleep(200000);
		}
		$rss = procRssKb($pid);
		if ($rss !== null && $rss > $peakRss) $peakRss = $rss;
		$status = proc_get_status($proc);
		if (!$status["running"]) break;
		if (!$timedOut && (microtime(true) - $start) > $timeout) {
			proc_terminate($proc, 9);
			$timedOut = true;
		}
	}
	foreach ($open as $fd => $stream) {
		$buffers[$fd] .= (string)stream_get_contents($stream);
		fclose($stream);
	}
	proc_close($proc);

	return [
		"started" => true,
		"exit_code" => $status["exitcode"],
		"signaled" => (bool)$status["signaled"],
		"term_signal" => $status["signaled"] ? $status["termsig"] : null,
		"timed_out" => $timedOut,
		"wall_s" => round(microtime(true) - $start, 2),
		"peak_rss_kb_polled" => $peakRss ?: null,
		"stdout" => $buffers[1],
		"stderr" => substr($buffers[2], -4000),
	];
}

function writePngChunk($fh, string $type, string $data): void {
	fwrite($fh, pack("N", strlen($data)).$type.$data.pack("N", crc32($type.$data)));
}

/**
 * Streaming RGBA PNG writer: one row in memory at a time, IDAT flushed every 1 MB, so
 * generating a 105 MP file costs a few MB of PHP memory. $row($y) returns the raw row
 * bytes (no filter byte).
 */
function writePng(string $path, int $width, int $height, int $bitDepth, callable $row, ?callable $progress = null): void {
	$fh = fopen($path, "wb");
	if ($fh === false) throw new RuntimeException("cannot write ".$path);
	fwrite($fh, "\x89PNG\r\n\x1a\n");
	writePngChunk($fh, "IHDR", pack("NNCCCCC", $width, $height, $bitDepth, 6, 0, 0, 0));
	$z = deflate_init(ZLIB_ENCODING_DEFLATE, ["level" => 6]);
	$pending = "";
	for ($y = 0; $y < $height; $y++) {
		$pending .= deflate_add($z, "\x00".$row($y), ZLIB_NO_FLUSH);
		if (strlen($pending) >= 1048576) {
			writePngChunk($fh, "IDAT", $pending);
			$pending = "";
		}
		if ($progress !== null) $progress($y, $height);
	}
	$pending .= deflate_add($z, "", ZLIB_FINISH);
	if ($pending !== "") writePngChunk($fh, "IDAT", $pending);
	writePngChunk($fh, "IEND", "");
	fclose($fh);
}

/**
 * A pool of rows that look like print art on a transparent background: transparent side
 * margins, flat runs of colour (some semi-transparent), and ~8% textured (noise) pixels.
 * Flat runs compress like real designs and the texture keeps it from compressing too well:
 * 105 MP lands around 15 MB at 8-bit, in the range of a real print file. Decode cost depends
 * on the pixel count, not on the file size, so the exact ratio doesn't matter.
 */
function makeArtRows(int $width, int $bitDepth, int $count): array {
	mt_srand(42);
	$bytesPerChannel = $bitDepth === 16 ? 2 : 1;
	$pixel = function (int $r, int $g, int $b, int $a) use ($bytesPerChannel): string {
		return $bytesPerChannel === 2
			? pack("nnnn", $r * 257, $g * 257, $b * 257, $a * 257)
			: chr($r).chr($g).chr($b).chr($a);
	};
	$transparent = $pixel(0, 0, 0, 0);
	$margin = intdiv($width, 7);
	$rows = [];
	for ($i = 0; $i < $count; $i++) {
		$row = str_repeat($transparent, $margin);
		$x = $margin;
		$end = $width - $margin;
		while ($x < $end) {
			$len = min(mt_rand(40, 900), $end - $x);
			if (mt_rand(0, 99) < 8) {
				$noise = random_bytes($len * 3);
				for ($p = 0; $p < $len; $p++) {
					$row .= $pixel(ord($noise[$p * 3]), ord($noise[$p * 3 + 1]), ord($noise[$p * 3 + 2]), 255);
				}
			} else {
				$alpha = mt_rand(0, 9) < 2 ? mt_rand(1, 254) : 255;
				$row .= str_repeat($pixel(mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), $alpha), $len);
			}
			$x += $len;
		}
		$row .= str_repeat($transparent, $width - $end);
		$rows[] = $row;
	}
	return $rows;
}

function emptyDir(string $dir): void {
	if (!is_dir($dir)) return;
	foreach (scandir($dir) ?: [] as $entry) {
		if ($entry === "." || $entry === "..") continue;
		$path = $dir."/".$entry;
		if (is_dir($path)) {
			emptyDir($path);
			@rmdir($path);
		} else {
			@unlink($path);
		}
	}
}

/** Child processes get the parent's environment plus $extra (proc_open's $env replaces it all). */
function childEnv(array $extra = []): array {
	$env = getenv();
	foreach (["MAGICK_CONFIGURE_PATH", "MAGICK_TEMPORARY_PATH"] as $inherited) unset($env[$inherited]);
	return array_merge($env, $extra);
}

function describeRun(string $engine, ?array $run, ?array $result): string {
	if ($run === null) return $engine.": not run";
	if (!$run["started"]) return $engine.": could not start (".$run["error"].")";
	if ($run["timed_out"]) return $engine.": TOO SLOW, killed by this script after ".$run["wall_s"]."s";
	if ($run["signaled"]) return $engine.": KILLED by signal ".$run["term_signal"]." after ".$run["wall_s"]."s, most likely the process watcher";
	if ($result === null) return $engine.": no result (exit ".$run["exit_code"]."), stderr: ".trim(substr($run["stderr"], 0, 300));
	if (empty($result["ok"])) return $engine.": FAILED, ".($result["error"] ?? "unknown error");
	$peak = max((int)($result["vm_hwm_kb"] ?? 0), (int)($run["peak_rss_kb_polled"] ?? 0));
	return $engine.": OK in ".$run["wall_s"]."s wall / ".($result["cpu_s"] ?? "?")."s CPU, peak RSS ".kbToMb($peak)
		.", output ".$result["output"]["width"]."x".$result["output"]["height"]
		.($result["output"]["alpha"] ? " with alpha" : " WITHOUT ALPHA");
}

// Child programs, written into the workdir at run time.

const CHILD_POLICY = <<<'PHP'
<?php
try {
	$im = new Imagick();
	$im->readImage("png:".$argv[1]);
	echo "READ_OK ".$im->getImageWidth()."x".$im->getImageHeight();
} catch (Throwable $e) {
	echo "READ_FAILED ".$e->getMessage();
}
PHP;

const CHILD_IMAGICK = <<<'PHP'
<?php
// One job: read a PNG, resize it into a bounding box, write a webp. JSON result on stdout.
$job = json_decode(stream_get_contents(STDIN), true);
$r = ["ok" => false];
$t0 = microtime(true);
try {
	if (!extension_loaded("imagick")) throw new RuntimeException("imagick is not loaded in this PHP");
	foreach ($job["limits"] as $name => $value) {
		$const = "Imagick::RESOURCETYPE_".$name;
		if (defined($const)) {
			Imagick::setResourceLimit(constant($const), $value);
			$r["limits_set"][$name] = $value;
		} else {
			$r["limits_unsupported"][] = $name;
		}
	}
	$ping = new Imagick();
	$ping->pingImage("png:".$job["input"]);
	$r["header"] = [$ping->getImageWidth(), $ping->getImageHeight()];
	$ping->clear();

	$im = new Imagick();
	$t = microtime(true);
	$im->readImage("png:".$job["input"]);
	$r["read_s"] = round(microtime(true) - $t, 2);
	$r["source_alpha"] = (bool)$im->getImageAlphaChannel();

	$t = microtime(true);
	$im->thumbnailImage($job["size"], $job["size"], true);
	$r["resize_s"] = round(microtime(true) - $t, 2);

	$t = microtime(true);
	$im->setImageFormat("webp");
	$im->setImageCompressionQuality(85);
	$im->writeImage("webp:".$job["output"]);
	$r["write_s"] = round(microtime(true) - $t, 2);
	$im->clear();

	$out = new Imagick("webp:".$job["output"]);
	$r["output"] = [
		"width" => $out->getImageWidth(),
		"height" => $out->getImageHeight(),
		"alpha" => (bool)$out->getImageAlphaChannel(),
		"bytes" => filesize($job["output"]),
	];
	$r["ok"] = true;
} catch (Throwable $e) {
	$r["error"] = get_class($e).": ".$e->getMessage();
}
$ru = getrusage();
$r["total_s"] = round(microtime(true) - $t0, 2);
$r["cpu_s"] = round($ru["ru_utime.tv_sec"] + $ru["ru_utime.tv_usec"] / 1e6 + $ru["ru_stime.tv_sec"] + $ru["ru_stime.tv_usec"] / 1e6, 2);
$status = @file_get_contents("/proc/self/status");
$r["vm_hwm_kb"] = ($status && preg_match('/VmHWM:\s+(\d+)\s+kB/', $status, $m)) ? (int)$m[1] : null;
$r["php_peak_kb"] = intdiv(memory_get_peak_usage(true), 1024);
echo json_encode($r);
PHP;

const CHILD_PYVIPS = <<<'PY'
import json, os, resource, sys, time
job = json.load(sys.stdin)
r = {"ok": False}
t0 = time.time()
try:
    import pyvips
    pyvips.cache_set_max(0)
    r["libvips"] = "%d.%d.%d" % (pyvips.version(0), pyvips.version(1), pyvips.version(2))
    t = time.time()
    im = pyvips.Image.thumbnail(job["input"], job["size"], height=job["size"], size="down")
    im.webpsave(job["output"], Q=85)
    r["resize_write_s"] = round(time.time() - t, 2)
    out = pyvips.Image.new_from_file(job["output"])
    r["output"] = {"width": out.width, "height": out.height, "alpha": bool(out.hasalpha()),
                   "bytes": os.path.getsize(job["output"])}
    r["ok"] = True
except Exception as e:
    r["error"] = "%s: %s" % (type(e).__name__, e)
ru = resource.getrusage(resource.RUSAGE_SELF)
r["total_s"] = round(time.time() - t0, 2)
r["cpu_s"] = round(ru.ru_utime + ru.ru_stime, 2)
r["vm_hwm_kb"] = ru.ru_maxrss
print(json.dumps(r))
PY;

// ---------------------------------------------------------------------------------------------
// workdir
// ---------------------------------------------------------------------------------------------

$work = $cfg["workdir"];
$magickTmp = $work."/magick-tmp";
foreach ([$work, $magickTmp] as $dir) {
	if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
		fwrite(STDERR, "Cannot create ".$dir."\n");
		exit(1);
	}
}
$cleanup = [];
register_shutdown_function(function () use (&$cleanup, $magickTmp, $cfg) {
	// ImageMagick leaves magick-* pixel-cache files behind when its process is killed.
	emptyDir($magickTmp);
	if ($cfg["keep"]) return;
	foreach ($cleanup as $path) if (is_file($path)) @unlink($path);
});

out("MagratheaExplorer DreamHost check, ".date("Y-m-d H:i:s T")." on ".gethostname());
out("Mode: ".($cfg["heavy"] ? "HEAVY" : "quick (add --heavy, and run it from cron, for the real test)"));

// ---------------------------------------------------------------------------------------------
// 1. environment
// ---------------------------------------------------------------------------------------------

section("Environment");
$user = function_exists("posix_geteuid") && function_exists("posix_getpwuid")
	? (posix_getpwuid(posix_geteuid())["name"] ?? null)
	: (getenv("USER") ?: null);
$interactive = function_exists("stream_isatty") && @stream_isatty(STDIN);
$procFunctions = ["proc_open", "proc_get_status", "proc_terminate", "proc_close", "stream_select"];
$procOk = true;
foreach ($procFunctions as $fn) $procOk = $procOk && functionUsable($fn);

$limits = [];
$limitsRaw = @file_get_contents("/proc/self/limits");
if ($limitsRaw !== false) {
	foreach (explode("\n", $limitsRaw) as $line) {
		if (preg_match('/^(Max (cpu time|file size|address space|resident set|processes|open files))\s+(\S+)\s+(\S+)/', $line, $m)) {
			$limits[$m[1]] = $m[3]." / ".$m[4];
		}
	}
}
$cpuCount = null;
$cpuinfo = @file_get_contents("/proc/cpuinfo");
if ($cpuinfo !== false) $cpuCount = preg_match_all('/^processor\s*:/m', $cpuinfo);
$memTotal = null;
$meminfo = @file_get_contents("/proc/meminfo");
if ($meminfo !== false && preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $m)) $memTotal = (int)$m[1];

$report["environment"] = [
	"php_binary" => PHP_BINARY,
	"php_version" => PHP_VERSION,
	"php_ini" => php_ini_loaded_file() ?: null,
	"php_ini_scanned" => php_ini_scanned_files() ?: null,
	"memory_limit" => ini_get("memory_limit"),
	"open_basedir" => ini_get("open_basedir") ?: null,
	"user" => $user,
	"interactive" => $interactive,
	"proc_functions_usable" => $procOk,
	"disabled_functions" => ini_get("disable_functions") ?: null,
	"extensions" => [
		"zlib" => extension_loaded("zlib"),
		"imagick" => extension_loaded("imagick"),
		"posix" => extension_loaded("posix"),
		"gd" => extension_loaded("gd"),
	],
	"process_limits" => $limits,
	"cpus" => $cpuCount,
	"mem_total_kb" => $memTotal,
	"workdir" => $work,
	"workdir_free_bytes" => @disk_free_space($work) ?: null,
];
kv("PHP binary (use it in processor_command)", PHP_BINARY);
kv("PHP version", PHP_VERSION);
kv("php.ini", $report["environment"]["php_ini"]);
kv("memory_limit", ini_get("memory_limit"));
kv("open_basedir", $report["environment"]["open_basedir"]);
kv("user", $user);
kv("interactive (not cron)", $interactive);
kv("proc_open & co usable", $procOk);
kv("extensions", $report["environment"]["extensions"]);
foreach ($limits as $name => $value) kv($name, $value);
kv("CPUs", $cpuCount);
kv("MemTotal", $memTotal !== null ? kbToMb($memTotal) : null);
kv("workdir", $work);
kv("free disk in workdir", mb($report["environment"]["workdir_free_bytes"]));
if ($interactive && $cfg["heavy"]) {
	out("  ! Running interactively: the watcher and limits may differ under cron. Trust the cron run.");
}

// ---------------------------------------------------------------------------------------------
// 2. Imagick
// ---------------------------------------------------------------------------------------------

section("Imagick (in this CLI)");
$imagickOk = extension_loaded("imagick");
if ($imagickOk) {
	$version = Imagick::getVersion();
	$quantum = Imagick::getQuantumDepth();
	$resources = [];
	foreach (["MEMORY", "MAP", "DISK", "AREA", "THREAD"] as $name) {
		$const = "Imagick::RESOURCETYPE_".$name;
		if (defined($const)) $resources[$name] = Imagick::getResourceLimit(constant($const));
	}
	$policies = [];
	foreach (array_merge(glob("/etc/ImageMagick*/policy.xml") ?: [], glob("/usr/local/etc/ImageMagick*/policy.xml") ?: []) as $file) {
		$xml = (string)@file_get_contents($file);
		preg_match_all('/<policy\s+domain="(coder|delegate|path|module)"\s+rights="none"\s+pattern="([^"]+)"/i', $xml, $m, PREG_SET_ORDER);
		$policies[$file] = array_map(fn($p) => $p[1].":".$p[2], $m);
	}
	$report["imagick"] = [
		"loaded" => true,
		"imagick_extension" => phpversion("imagick"),
		"imagemagick" => $version["versionString"] ?? null,
		"quantum_depth" => $quantum["quantumDepthString"] ?? null,
		"webp" => count(Imagick::queryFormats("WEBP")) > 0,
		"png" => count(Imagick::queryFormats("PNG")) > 0,
		"default_resource_limits" => $resources,
		"system_policies" => $policies,
	];
	kv("imagick extension", $report["imagick"]["imagick_extension"]);
	kv("ImageMagick", $report["imagick"]["imagemagick"]);
	kv("quantum depth", $report["imagick"]["quantum_depth"]);
	kv("PNG read / WEBP", ($report["imagick"]["png"] ? "yes" : "NO")." / ".($report["imagick"]["webp"] ? "yes" : "NO"));
	kv("default resource limits", $resources);
	foreach ($policies as $file => $denied) kv("system policy", $file." denies ".(implode(", ", $denied) ?: "nothing"));
	if (!$policies) kv("system policy", "none found in /etc/ImageMagick*");
} else {
	$report["imagick"] = ["loaded" => false];
	kv("loaded", false);
	out("  The CLI has no imagick. Another PHP build might (try the other /usr/local/php8* binaries).");
}

// ---------------------------------------------------------------------------------------------
// 3. does MAGICK_CONFIGURE_PATH (set through proc_open's env) override the policy?
// ---------------------------------------------------------------------------------------------

section("ImageMagick policy override via MAGICK_CONFIGURE_PATH");
if ($imagickOk && $procOk && extension_loaded("zlib")) {
	$tinyPng = $work."/tiny.png";
	writePng($tinyPng, 4, 4, 8, fn($y) => str_repeat("\xff\x00\x00\xff", 4));
	$policyDir = $work."/policy-deny-png";
	@mkdir($policyDir, 0700);
	file_put_contents($policyDir."/policy.xml",
		"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<policymap>\n  <policy domain=\"coder\" rights=\"none\" pattern=\"PNG\" />\n</policymap>\n");
	$childPolicy = $work."/child-policy.php";
	file_put_contents($childPolicy, CHILD_POLICY);

	$control = runChild([PHP_BINARY, $childPolicy, $tinyPng], childEnv(), "", 60);
	$denied = runChild([PHP_BINARY, $childPolicy, $tinyPng], childEnv(["MAGICK_CONFIGURE_PATH" => $policyDir]), "", 60);
	$controlOut = trim($control["stdout"] ?? "");
	$deniedOut = trim($denied["stdout"] ?? "");
	if (strpos($controlOut, "READ_OK") !== 0) {
		$verdict = "inconclusive: even the control read failed";
	} elseif (strpos($deniedOut, "READ_FAILED") === 0) {
		$verdict = "HONORED: a policy.xml shipped with the processor works";
	} else {
		$verdict = "IGNORED: the policy must be enforced in code (coder allowlist + forced decoder)";
	}
	$report["policy_override"] = ["control" => $controlOut, "with_override" => $deniedOut, "verdict" => $verdict];
	kv("control read (no override)", $controlOut);
	kv("read with a deny-PNG policy", $deniedOut);
	kv("verdict", $verdict);
	foreach ([$tinyPng, $childPolicy, $policyDir."/policy.xml"] as $file) @unlink($file);
	@rmdir($policyDir);
} else {
	$report["policy_override"] = ["verdict" => "skipped (needs imagick, proc_open and zlib)"];
	kv("verdict", $report["policy_override"]["verdict"]);
}

// ---------------------------------------------------------------------------------------------
// 4. python / pyvips
// ---------------------------------------------------------------------------------------------

section("Python / pyvips");
$python = $cfg["python"] !== null
	? (is_file($cfg["python"]) && is_executable($cfg["python"]) ? $cfg["python"] : null)
	: findExecutable("python3");
$pyvipsOk = false;
$report["python"] = ["python3" => $python];
kv("python3", $python);
if ($python !== null && $procOk) {
	$checkPyvips = function () use ($python): array {
		$code = 'import json, pyvips; print(json.dumps({"pyvips": pyvips.__version__, "libvips": "%d.%d.%d" % (pyvips.version(0), pyvips.version(1), pyvips.version(2))}))';
		$run = runChild([$python, "-c", $code], childEnv(), "", 60);
		$decoded = json_decode(trim($run["stdout"] ?? ""), true);
		return is_array($decoded) ? $decoded : ["error" => trim(substr($run["stderr"] ?? "", -300))];
	};
	$pyVersion = runChild([$python, "--version"], childEnv(), "", 30);
	$pip = runChild([$python, "-m", "pip", "--version"], childEnv(), "", 60);
	$userSite = runChild([$python, "-m", "site", "--user-site"], childEnv(), "", 30);
	$report["python"]["version"] = trim(($pyVersion["stdout"] ?? "").($pyVersion["stderr"] ?? ""));
	$report["python"]["pip"] = ($pip["exit_code"] ?? 1) === 0 ? trim($pip["stdout"]) : null;
	$report["python"]["user_site"] = trim($userSite["stdout"] ?? "") ?: null;
	kv("version", $report["python"]["version"]);
	kv("pip", $report["python"]["pip"] ?? "NOT AVAILABLE");
	kv("user site-packages", $report["python"]["user_site"]);

	$pyvips = $checkPyvips();
	if (!isset($pyvips["pyvips"]) && $cfg["install_pyvips"] && $report["python"]["pip"] !== null) {
		out("  installing pyvips[binary] with pip --user (this can take a minute)...");
		$install = runChild([$python, "-m", "pip", "install", "--user", "pyvips[binary]"], childEnv(), "", 900);
		$report["python"]["install"] = [
			"exit_code" => $install["exit_code"] ?? null,
			"tail" => trim(substr(($install["stdout"] ?? "").($install["stderr"] ?? ""), -1500)),
		];
		kv("pip install exit code", $install["exit_code"] ?? null);
		if (strpos($report["python"]["install"]["tail"], "externally-managed-environment") !== false) {
			out("  ! This python refuses --user installs (PEP 668). Use a venv instead:");
			out("      ".$python." -m venv ~/explorer-venv && ~/explorer-venv/bin/pip install \"pyvips[binary]\"");
			out("    then rerun with --python=\$HOME/explorer-venv/bin/python");
		}
		$pyvips = $checkPyvips();
	}
	$pyvipsOk = isset($pyvips["pyvips"]);
	$report["python"]["pyvips"] = $pyvips;
	kv("pyvips", $pyvipsOk ? $pyvips["pyvips"]." (libvips ".$pyvips["libvips"].")" : "not importable");
	if (!$pyvipsOk && !$cfg["install_pyvips"]) {
		out("  Add --install-pyvips to try: ".$python.' -m pip install --user "pyvips[binary]"');
	}
} elseif ($python === null) {
	kv("pyvips", $cfg["python"] !== null ? "--python is not an executable file" : "no python3 on PATH");
}

// ---------------------------------------------------------------------------------------------
// 5. heavy: the actual resize
// ---------------------------------------------------------------------------------------------

$runs = [];
if ($cfg["heavy"]) {
	section("Test image");
	$input = $cfg["input"];
	if ($input !== null) {
		$head = (string)@file_get_contents($input, false, null, 0, 8);
		if ($head !== "\x89PNG\r\n\x1a\n") {
			fwrite(STDERR, "--input must be a PNG file: ".$input."\n");
			exit(1);
		}
		kv("input (given)", $input);
	} else {
		if (!extension_loaded("zlib")) {
			fwrite(STDERR, "zlib is not loaded: can't generate the test PNG. Use --input=FILE.\n");
			exit(1);
		}
		$side = (int)round(sqrt($cfg["megapixels"] * 1e6));
		$width = (int)round($side * 1.05);
		$height = (int)round($cfg["megapixels"] * 1e6 / $width);
		$input = $work."/test-".$width."x".$height."-".$cfg["bit_depth"]."bit.png";
		$cleanup[] = $input;
		$rows = makeArtRows($width, $cfg["bit_depth"], 48);
		out("  generating ".$width."x".$height." RGBA ".$cfg["bit_depth"]."-bit PNG (streaming)...");
		$t = microtime(true);
		$lastPct = -1;
		writePng($input, $width, $height, $cfg["bit_depth"],
			fn($y) => $rows[intdiv($y, 37) % count($rows)],
			function ($y, $h) use (&$lastPct) {
				$pct = intdiv(($y + 1) * 100, $h);
				if ($pct % 20 === 0 && $pct !== $lastPct) {
					$lastPct = $pct;
					out("    ".$pct."%");
				}
			});
		$report["heavy"]["generate_s"] = round(microtime(true) - $t, 2);
		$report["heavy"]["generator_php_peak"] = memory_get_peak_usage(true);
		kv("generated in", $report["heavy"]["generate_s"]."s (PHP peak ".mb(memory_get_peak_usage(true)).")");
	}
	clearstatcache();
	$dims = @getimagesize($input);
	$report["heavy"]["input"] = $input;
	$report["heavy"]["input_bytes"] = filesize($input);
	$report["heavy"]["input_dimensions"] = $dims ? [$dims[0], $dims[1]] : null;
	kv("file", $input);
	kv("size on disk", mb(filesize($input)));
	kv("dimensions (header)", $dims ? $dims[0]."x".$dims[1]." = ".round($dims[0] * $dims[1] / 1e6, 1)." MP" : "unreadable");
	if (($report["environment"]["workdir_free_bytes"] ?? PHP_INT_MAX) < 2 * 1024 * 1024 * 1024) {
		out("  ! Less than 2 GB free: ImageMagick's disk pixel cache may fail on quota, not on memory.");
	}

	$job = ["input" => $input, "size" => $cfg["size"]];

	if ($imagickOk && $procOk && !$cfg["skip_imagick"]) {
		$childImagick = $work."/child-imagick.php";
		file_put_contents($childImagick, CHILD_IMAGICK);
		$cleanup[] = $childImagick;
		$variants = ["imagick_limited" => [
			"MEMORY" => $cfg["memory_mb"] * 1048576,
			"MAP" => $cfg["map_mb"] * 1048576,
			"DISK" => $cfg["disk_mb"] * 1048576,
			"THREAD" => 1,
		]];
		if ($cfg["also_unlimited"]) $variants["imagick_unlimited"] = [];
		foreach ($variants as $name => $limits) {
			section("Resize: ".$name.($limits ? " (memory ".$cfg["memory_mb"]." MB, map ".$cfg["map_mb"]." MB, disk ".$cfg["disk_mb"]." MB, 1 thread)" : " (no limits)"));
			$output = $work."/out-".$name.".webp";
			$cleanup[] = $output;
			$env = childEnv(["MAGICK_TEMPORARY_PATH" => $magickTmp, "MAGICK_THREAD_LIMIT" => "1", "OMP_NUM_THREADS" => "1"]);
			$run = runChild([PHP_BINARY, "-d", "memory_limit=256M", $childImagick], $env,
				json_encode($job + ["output" => $output, "limits" => $limits]), $cfg["timeout"]);
			$result = json_decode(trim($run["stdout"] ?? ""), true);
			$runs[$name] = ["run" => $run, "result" => is_array($result) ? $result : null];
			emptyDir($magickTmp);
			out("  ".describeRun($name, $run, $runs[$name]["result"]));
			if (is_array($result)) {
				foreach (["read_s", "resize_s", "write_s", "vm_hwm_kb", "php_peak_kb", "limits_unsupported"] as $field) {
					if (isset($result[$field])) kv($field, $result[$field]);
				}
			}
		}
	} else {
		section("Resize: imagick");
		out("  skipped".($cfg["skip_imagick"] ? " (--skip-imagick)" : " (needs imagick and proc_open)"));
	}

	if ($pyvipsOk && $procOk && !$cfg["skip_pyvips"]) {
		section("Resize: pyvips (VIPS_CONCURRENCY=1)");
		$childPy = $work."/child-pyvips.py";
		file_put_contents($childPy, CHILD_PYVIPS);
		$cleanup[] = $childPy;
		$output = $work."/out-pyvips.webp";
		$cleanup[] = $output;
		$run = runChild([$python, $childPy], childEnv(["VIPS_CONCURRENCY" => "1"]),
			json_encode($job + ["output" => $output]), $cfg["timeout"]);
		$result = json_decode(trim($run["stdout"] ?? ""), true);
		$runs["pyvips"] = ["run" => $run, "result" => is_array($result) ? $result : null];
		out("  ".describeRun("pyvips", $run, $runs["pyvips"]["result"]));
		if (is_array($result) && isset($result["vm_hwm_kb"])) kv("vm_hwm_kb", $result["vm_hwm_kb"]);
	} else {
		section("Resize: pyvips");
		out("  skipped".($cfg["skip_pyvips"] ? " (--skip-pyvips)" : " (pyvips not importable)"));
	}

	foreach ($runs as $name => $data) {
		$data["run"]["stdout"] = substr($data["run"]["stdout"] ?? "", 0, 4000);
		$report["heavy"]["runs"][$name] = $data;
	}
}

// ---------------------------------------------------------------------------------------------
// 6. verdict
// ---------------------------------------------------------------------------------------------

section("Verdict");
$verdict = [];
if (!$procOk) $verdict[] = "BLOCKER: proc_open (or a sibling) is disabled. The processor design (blueprint §3.4.1) can't run here.";
if (!$imagickOk) $verdict[] = "Imagick is not available in this CLI.";
if ($report["policy_override"] !== null) $verdict[] = "Policy override: ".$report["policy_override"]["verdict"];

$passed = function (string $name) use ($runs): ?array {
	$r = $runs[$name] ?? null;
	if ($r === null || !$r["run"]["started"] || $r["run"]["signaled"] || $r["run"]["timed_out"]) return null;
	if (empty($r["result"]["ok"]) || empty($r["result"]["output"]["alpha"])) return null;
	return ["wall" => $r["run"]["wall_s"], "rss" => max((int)($r["result"]["vm_hwm_kb"] ?? 0), (int)($r["run"]["peak_rss_kb_polled"] ?? 0))];
};

if ($cfg["heavy"]) {
	foreach ($runs as $name => $data) $verdict[] = describeRun($name, $data["run"], $data["result"]);
	$im = $passed("imagick_limited");
	$vips = $passed("pyvips");
	if ($vips !== null && ($im === null || $vips["rss"] < $im["rss"] / 2 || $vips["wall"] < $im["wall"] / 2)) {
		$verdict[] = "ENGINE #1: pyvips (".kbToMb($vips["rss"]).", ".$vips["wall"]."s)";
	} elseif ($im !== null) {
		$verdict[] = "ENGINE #1: Imagick (".kbToMb($im["rss"]).", ".$im["wall"]."s)".($vips === null ? "; pyvips didn't pass or wasn't tested" : "");
	} else {
		$verdict[] = "NO ENGINE PASSED: don't build Phase 5 until this is solved (look at the runs above).";
	}
} else {
	$verdict[] = "Quick checks only. Run again with --heavy, from cron, for the engine answer.";
}
$verdict[] = "Not testable from here, check by hand: (1) can a cron job run every minute on this plan? "
	."(2) does a 150 MB upload survive DreamHost's front proxy (body size and timeouts)?";

$report["verdict"] = $verdict;
$report["finished_at"] = date("c");
foreach ($verdict as $line) out("  - ".$line);

$reportPath = $work."/report.json";
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
out();
out("Report saved to ".$reportPath);
exit(0);
