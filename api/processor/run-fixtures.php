<?php
/**
 * Processor contract check: runs the configured processor on every fixture in fixtures/ through
 * ProcessorRunner, exactly as Explorer will, and checks the results against
 * fixtures/expected.json. Then it generates a PNG at max_pixels and checks that the processor
 * gets through it under processor_memory_mb. Run it in dev, and on the server after every deploy
 * or server change (a Python upgrade breaks the venv silently).
 *
 *   php api/processor/run-fixtures.php [--command='["/path/python","/path/processor.py"]']
 *       [--only=a.png,b.jpg] [--skip-big] [--big-pixels=N] [--keep] [--verbose]
 *
 * Reads magrathea.conf (processor_command, processor_timeout_s, processor_memory_mb, max_pixels,
 * processing_tmp_path); never the database. --command overrides processor_command, to try an
 * engine before configuring it. Pixel checks need GD and are skipped without it.
 *
 * Exit code: 0 every check passed, 1 a check failed, 2 couldn't run.
 */

use MagratheaExplorer\ExplorerConfig;
use MagratheaExplorer\Processing\ProcessorJobDir;
use MagratheaExplorer\Processing\ProcessorResult;
use MagratheaExplorer\Processing\ProcessorRunner;

if(PHP_SAPI !== "cli") exit(2);
require __DIR__."/../src/_autoload.php";

const USAGE = "Usage: php run-fixtures.php [--command=JSON] [--only=NAME,...] [--skip-big] [--big-pixels=N] [--keep] [--verbose]\n";

$options = getopt("", ["command:", "only:", "skip-big", "big-pixels:", "keep", "verbose", "help"]);
if(isset($options["help"])) {
	echo USAGE;
	exit(0);
}
$verbose = isset($options["verbose"]);
$keep = isset($options["keep"]);

try {
	Magrathea\MagratheaPHP::Instance()->Prod()->Load();
	if(isset($options["command"])) {
		$argv = json_decode((string)$options["command"], true);
		if(!is_array($argv)) throw new InvalidArgumentException("--command must be a JSON array of strings");
		$runner = new ProcessorRunner(
			$argv,
			ExplorerConfig::GetPositiveInt("processor_timeout_s", ProcessorRunner::DEFAULT_TIMEOUT_S),
			ExplorerConfig::GetPositiveInt("processor_memory_mb", ProcessorRunner::DEFAULT_MEMORY_MB),
			ExplorerConfig::GetPositiveInt("max_pixels", ProcessorRunner::DEFAULT_MAX_PIXELS),
		);
	} else {
		$runner = ProcessorRunner::FromConfig();
		if($runner === null) throw new RuntimeException("processor_command isn't set in magrathea.conf (or pass --command)");
	}
	$tmp = ExplorerConfig::ProcessingTmpPath();
	$expected = json_decode((string)file_get_contents(__DIR__."/fixtures/expected.json"), true, 32, JSON_THROW_ON_ERROR);
} catch(Throwable $ex) {
	fwrite(STDERR, "run-fixtures: ".$ex->getMessage()."\n".USAGE);
	exit(2);
}

$only = isset($options["only"]) ? array_filter(explode(",", (string)$options["only"])) : null;
$gd = function_exists("imagecreatefromstring");
$checks = new Checks($verbose);

echo "Processor: ".json_encode($runner->command, JSON_UNESCAPED_SLASHES)."\n";
echo "Limits:    timeout ".$runner->timeoutS." s, memory ".$runner->memoryMb." MB, max_pixels ".$runner->maxPixels."\n";
echo "Pixels:    ".($gd ? "checked with GD" : "NOT checked (no GD in this PHP)")."\n\n";

$n = 0;
$engine = null;
foreach($expected["fixtures"] as $file => $fixture) {
	if($only !== null && !in_array($file, $only, true)) continue;
	$checks->Begin($file, $fixture["about"]);
	foreach([ProcessorRunner::MODE_HEADER, ProcessorRunner::MODE_FULL] as $mode) {
		$dir = ProcessorJobDir::Create("fixtures-".getmypid()."-".(++$n), $tmp);
		try {
			$result = $runner->Run($dir, __DIR__."/fixtures/".$file, $fixture["mime_type"], "image", $mode, $expected["renditions"]);
			$engine ??= $result->engine;
			CheckFixture($checks, $mode, $fixture, $result, $expected["renditions"], $gd);
		} finally {
			if(!$keep) $dir->Remove();
		}
	}
	$checks->End();
}

if(!isset($options["skip-big"]) && $only === null) {
	$pixels = isset($options["big-pixels"]) ? (int)$options["big-pixels"] : $runner->maxPixels;
	CheckBig($checks, $runner, $tmp, $pixels, $keep);
}

echo "\nEngine: ".($engine ?? "unknown")."\n";
echo $checks->Summary()."\n";
exit($checks->Failed() > 0 ? 1 : 0);


function CheckFixture(Checks $c, string $mode, array $fx, ProcessorResult $r, array $specs, bool $gd): void {
	$c->Mode($mode, $r);
	$expect = $fx[$mode] ?? "ok";
	if($expect === "fail") {
		$c->Check(!$r->ok, "fails", $r->ok ? "it succeeded" : null);
		$c->Check(!$r->ok && trim((string)$r->error) !== "", "says why", "no error message");
		return;
	}
	if(!$c->Check($r->ok, "succeeds", $r->error)) return;
	$handled = $fx["handled"] ?? true;
	$c->Check($r->handled === $handled, "handled = ".json_encode($handled), "handled = ".json_encode($r->handled));
	if(!$handled) {
		$c->Check($r->renditions === [] && $r->errors === [], "no renditions, no errors", "renditions or errors present");
		return;
	}
	$renders = $fx["renders"] ?? true;
	$c->Check($r->renders === $renders, "renders = ".json_encode($renders), "renders = ".json_encode($r->renders));

	$properties = $fx["properties"] ?? [];
	if($mode === ProcessorRunner::MODE_HEADER && ($properties["has_alpha_channel"] ?? null) === true) {
		$properties["is_opaque"] = null; // needs every pixel: full mode only
	}
	foreach($properties as $key => $value) {
		$got = $r->properties[$key] ?? null;
		$c->Check($got === $value, $key." = ".json_encode($value), $key." = ".json_encode($got));
	}

	if($mode === ProcessorRunner::MODE_HEADER) {
		$c->Check($r->renditions === [] && $r->errors === [], "no renditions in header mode", "renditions in header mode");
		return;
	}
	if(!$renders) {
		$c->Check($r->renditions === [] && $r->errors === [], "no renditions", "renditions or errors present");
		return;
	}
	if(($fx["rendition_errors"] ?? null) === "all") {
		$c->Check($r->renditions === [], "no rendition made", count($r->renditions)." made");
		foreach($specs as $spec) {
			$c->Check(isset($r->errors[$spec["name"]]), $spec["name"]." refused", $spec["name"]." not in errors");
		}
		if($r->errors !== []) $c->Note("reason: ".array_values($r->errors)[0]);
		return;
	}
	$c->Check($r->errors === [], "no rendition errors", json_encode($r->errors, JSON_UNESCAPED_SLASHES));
	foreach($fx["renditions"] ?? [] as $name => $want) {
		$got = $r->renditions[$name] ?? null;
		if(!$c->Check($got !== null, $name.": made", $name.": missing")) continue;
		$c->Check($got["width"] === $want["width"] && $got["height"] === $want["height"],
			$name.": ".$want["width"]."x".$want["height"], $name.": ".$got["width"]."x".$got["height"]);
		$c->Check($got["mime_type"] === $want["mime_type"], $name.": ".$want["mime_type"], $name.": ".$got["mime_type"]);
		$alpha = RenditionFile::HasAlpha($got["path"], $got["mime_type"]);
		if($want["has_alpha"] !== null) {
			$c->Check($alpha === $want["has_alpha"], $name.": alpha ".json_encode($want["has_alpha"]), $name.": alpha ".json_encode($alpha));
		}
		$metadata = RenditionFile::Metadata($got["path"], $got["mime_type"]);
		$c->Check($metadata === null, $name.": no metadata", $name.": carries ".$metadata);
		foreach($fx["pixels"][$name] ?? [] as $pixel) {
			if(!$gd) {
				$c->Skip($name.": pixel at ".json_encode($pixel["at"])." (no GD)");
				continue;
			}
			$rgb = RenditionFile::Pixel($got["path"], $pixel["at"][0], $pixel["at"][1]);
			$near = $rgb !== null && max(array_map(fn($a, $b) => abs($a - $b), $rgb, $pixel["rgb"])) <= $pixel["tolerance"];
			$c->Check($near, $name.": pixel at ".json_encode($pixel["at"])." ~".json_encode($pixel["rgb"]),
				$name.": pixel at ".json_encode($pixel["at"])." is ".json_encode($rgb).", wanted ".json_encode($pixel["rgb"])." ±".$pixel["tolerance"]);
		}
	}
}

/**
 * A square RGBA PNG of about $pixels, written row by row (flat memory), then a full run with
 * Turbopod's two renditions. It must finish, under the memory limit, and see the transparent
 * margins (is_opaque false needs a pass over every pixel).
 */
function CheckBig(Checks $c, ProcessorRunner $runner, string $tmp, int $pixels, bool $keep): void {
	$side = (int)floor(sqrt(max(1, $pixels)));
	$c->Begin("generated ".$side."x".$side." RGBA PNG", round($side * $side / 1e6)." MP, near max_pixels: the processor has to stay under ".$runner->memoryMb." MB");
	$dir = ProcessorJobDir::Create("fixtures-".getmypid()."-big", $tmp);
	try {
		$t = microtime(true);
		BigPng::Write($dir->input, $side);
		$c->Note("generated in ".round(microtime(true) - $t, 1)." s, ".round(filesize($dir->input) / 1048576, 1)." MB");
		$specs = [
			["name" => "thumb", "width" => 300, "height" => 300, "fit" => "contain", "format" => "webp", "quality" => 85],
			["name" => "preview", "width" => 1200, "height" => 1200, "fit" => "contain", "format" => "webp", "quality" => 85],
		];
		$r = $runner->Run($dir, $dir->input, "image/png", "image", ProcessorRunner::MODE_FULL, $specs);
		$c->Mode(ProcessorRunner::MODE_FULL, $r);
		if($c->Check($r->ok, "succeeds", $r->error)) {
			$c->Check($r->properties["width"] === $side && $r->properties["height"] === $side, "width/height = ".$side, "width/height = ".$r->properties["width"]."x".$r->properties["height"]);
			$c->Check($r->properties["is_opaque"] === false, "is_opaque = false", "is_opaque = ".json_encode($r->properties["is_opaque"]));
			$c->Check($r->errors === [], "no rendition errors", json_encode($r->errors, JSON_UNESCAPED_SLASHES));
			foreach(["thumb" => 300, "preview" => 1200] as $name => $box) {
				$got = $r->renditions[$name] ?? null;
				$c->Check($got !== null && $got["width"] === min($box, $side) && $got["height"] === min($box, $side),
					$name.": ".min($box, $side)."x".min($box, $side), $name.": ".($got === null ? "missing" : $got["width"]."x".$got["height"]));
			}
		}
	} finally {
		if(!$keep) $dir->Remove();
	}
	$c->End();
}


/** Collects pass/fail per check and prints one block per fixture (only failures unless --verbose). */
final class Checks {
	private int $passed = 0;
	private int $failed = 0;
	private int $skipped = 0;
	private array $lines = [];
	private bool $fixtureFailed = false;
	private string $title = "";
	private string $timing = "";

	public function __construct(private bool $verbose) {}

	public function Begin(string $title, string $about): void {
		$this->title = $title;
		$this->lines = $this->verbose ? ["      ".$about] : [];
		$this->fixtureFailed = false;
		$this->timing = "";
	}

	public function Mode(string $mode, ProcessorResult $r): void {
		$rss = $r->peakRssKb !== null ? ", ".round($r->peakRssKb / 1024)." MB peak" : "";
		$this->timing .= " ".$mode." ".number_format($r->seconds, 2)." s".$rss;
		if($this->verbose) $this->lines[] = "    [".$mode."]".($r->ok ? "" : " error: ".$r->error);
	}

	public function Check(bool $ok, string $pass, ?string $fail): bool {
		if($ok) {
			$this->passed++;
			if($this->verbose) $this->lines[] = "      ok    ".$pass;
		} else {
			$this->failed++;
			$this->fixtureFailed = true;
			$this->lines[] = "      FAIL  ".($fail ?? $pass);
		}
		return $ok;
	}

	public function Skip(string $what): void {
		$this->skipped++;
		if($this->verbose) $this->lines[] = "      skip  ".$what;
	}

	public function Note(string $text): void {
		if($this->verbose) $this->lines[] = "      note  ".$text;
	}

	public function End(): void {
		echo ($this->fixtureFailed ? "FAIL" : "ok  ")."  ".str_pad($this->title, 26)." ".trim($this->timing)."\n";
		foreach($this->lines as $line) echo $line."\n";
	}

	public function Failed(): int {
		return $this->failed;
	}

	public function Summary(): string {
		return $this->passed." checks passed, ".$this->failed." failed".($this->skipped > 0 ? ", ".$this->skipped." skipped" : "").".";
	}
}


/** Reads a rendition file directly, so the checks don't depend on the engine's own claims. */
final class RenditionFile {

	/** Whether the file carries an alpha channel: PNG color type 4/6 or tRNS, WebP's alpha flag. */
	public static function HasAlpha(string $path, string $mime): ?bool {
		$data = (string)file_get_contents($path);
		if($mime === "image/jpeg") return false;
		if($mime === "image/png") {
			$alpha = in_array(ord($data[25] ?? "\0"), [4, 6], true);
			foreach(self::PngChunks($data) as $type) {
				if($type === "tRNS") $alpha = true;
			}
			return $alpha;
		}
		if($mime === "image/webp") {
			$first = substr($data, 12, 4);
			if($first === "VP8X") return (ord($data[20]) & 0x10) !== 0;
			if($first === "VP8L") return ((unpack("V", substr($data, 21, 4))[1] >> 28) & 1) === 1;
			if($first === "VP8 ") return false;
		}
		return null;
	}

	/** The first metadata container found (EXIF, XMP, IPTC, text, comment), or null. */
	public static function Metadata(string $path, string $mime): ?string {
		$data = (string)file_get_contents($path);
		if($mime === "image/png") {
			foreach(self::PngChunks($data) as $type) {
				if(in_array($type, ["eXIf", "tEXt", "zTXt", "iTXt"], true)) return "a PNG ".$type." chunk";
			}
			return null;
		}
		if($mime === "image/jpeg") {
			for($i = 2; $i + 4 <= strlen($data) && $data[$i] === "\xFF";) {
				$marker = ord($data[$i + 1]);
				if($marker === 0xDA) break;
				if(in_array($marker, [0xE1, 0xED, 0xFE], true)) return sprintf("a JPEG 0x%02X segment", $marker);
				$i += 2 + unpack("n", substr($data, $i + 2, 2))[1];
			}
			return null;
		}
		if($mime === "image/webp") {
			for($i = 12; $i + 8 <= strlen($data);) {
				$type = substr($data, $i, 4);
				if($type === "EXIF" || $type === "XMP ") return "a WebP ".trim($type)." chunk";
				$size = unpack("V", substr($data, $i + 4, 4))[1];
				$i += 8 + $size + ($size % 2);
			}
			return null;
		}
		return null;
	}

	/** [r, g, b] at a relative position (0..1 of width-1 / height-1), via GD. */
	public static function Pixel(string $path, float $x, float $y): ?array {
		$image = @imagecreatefromstring((string)file_get_contents($path));
		if($image === false) return null;
		$color = imagecolorat($image, (int)($x * (imagesx($image) - 1)), (int)($y * (imagesy($image) - 1)));
		if(!imageistruecolor($image)) {
			$c = imagecolorsforindex($image, $color);
			return [$c["red"], $c["green"], $c["blue"]];
		}
		return [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF];
	}

	/** @return string[] chunk types in order */
	private static function PngChunks(string $data): array {
		$types = [];
		for($i = 8; $i + 8 <= strlen($data);) {
			$length = unpack("N", substr($data, $i, 4))[1];
			$types[] = substr($data, $i + 4, 4);
			$i += 12 + $length;
		}
		return $types;
	}
}


/**
 * Streaming RGBA PNG writer, one row in memory at a time (same idea as diag/dreamhost-check.php):
 * a 200 MP file costs a few MB of PHP memory. Rows look like print art: transparent side
 * margins and flat runs of color, some semi-transparent. Decode cost depends on the pixel
 * count, not on how well the file compresses.
 */
final class BigPng {

	public static function Write(string $path, int $side): void {
		$fh = fopen($path, "wb");
		if($fh === false) throw new RuntimeException("cannot write ".$path);
		try {
			fwrite($fh, "\x89PNG\r\n\x1a\n");
			self::Chunk($fh, "IHDR", pack("NNCCCCC", $side, $side, 8, 6, 0, 0, 0));
			$rows = self::Rows($side, 16);
			$z = deflate_init(ZLIB_ENCODING_DEFLATE, ["level" => 1]);
			$pending = "";
			for($y = 0; $y < $side; $y++) {
				$pending .= deflate_add($z, "\x00".$rows[intdiv($y, 64) % count($rows)], ZLIB_NO_FLUSH);
				if(strlen($pending) >= 1048576) {
					self::Chunk($fh, "IDAT", $pending);
					$pending = "";
				}
			}
			$pending .= deflate_add($z, "", ZLIB_FINISH);
			if($pending !== "") self::Chunk($fh, "IDAT", $pending);
			self::Chunk($fh, "IEND", "");
		} finally {
			fclose($fh);
		}
	}

	private static function Rows(int $width, int $count): array {
		mt_srand(42);
		$margin = intdiv($width, 7);
		$rows = [];
		for($i = 0; $i < $count; $i++) {
			$row = str_repeat("\0\0\0\0", $margin);
			for($x = $margin; $x < $width - $margin;) {
				$length = min(mt_rand(40, 900), $width - $margin - $x);
				$alpha = mt_rand(0, 9) < 2 ? mt_rand(1, 254) : 255;
				$row .= str_repeat(chr(mt_rand(0, 255)).chr(mt_rand(0, 255)).chr(mt_rand(0, 255)).chr($alpha), $length);
				$x += $length;
			}
			$rows[] = $row.str_repeat("\0\0\0\0", $width - strlen($row) / 4);
		}
		return $rows;
	}

	private static function Chunk($fh, string $type, string $data): void {
		fwrite($fh, pack("N", strlen($data)).$type.$data.pack("N", crc32($type.$data)));
	}
}
