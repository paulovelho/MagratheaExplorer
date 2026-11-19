<?php

namespace MagratheaExplorer\Processing;

use MagratheaExplorer\ExplorerConfig;

/**
 * Runs the processor configured in magrathea.conf (`processor_command`) on one file: JSON job on
 * stdin, JSON result on stdout. The contract is api/processor/README.md; run-fixtures.php drives
 * an engine through this class, so the fixtures test exactly what Explorer runs.
 *
 * Explorer enforces the limits from outside: an argv array (no shell), a minimal environment,
 * a wall-clock timeout (SIGKILL), stdout capped. The memory limit travels in the job and the
 * processor applies it to itself. Nothing the processor outputs is trusted: a rendition counts
 * only if it's a regular file inside out_dir, of the requested type by PHP's own reading, and
 * its size and dimensions are measured here. Properties outside their type and range become
 * null. Error text has the job's paths masked: it reaches the admin and, later, API clients.
 */
final class ProcessorRunner {

	const DEFAULT_TIMEOUT_S = 120;
	/**
	 * Measured (1.5.0, pyvips): memory follows the image's width. A 14142x14142 RGBA PNG peaks at
	 * 150-210 MB RSS (fails at 128); a 28000x3500 one at ~260 MB (its 1200 px rendition fails at 256).
	 */
	const DEFAULT_MEMORY_MB = 384;
	const DEFAULT_MAX_PIXELS = 200000000;

	const MODE_HEADER = "header";
	const MODE_FULL = "full";

	/** A result is a few KB: more than this on stdout kills the run. */
	const MAX_STDOUT = 1048576;
	/** Only stderr's tail is kept: the processor's reason is its last line. */
	const KEEP_STDERR = 8192;
	const MAX_ERROR_CHARS = 500;
	/** Polling interval while the processor runs: stream_select's timeout and the RSS sample rate. */
	const POLL_US = 200000;

	/** Rendition format => [mime type, extension]. `legacy` is resolved before the job is sent. */
	const FORMATS = [
		"webp" => ["image/webp", "webp"],
		"png" => ["image/png", "png"],
		"jpg" => ["image/jpeg", "jpg"],
	];

	/** Every property a result can carry, and the check its value has to pass (else null). */
	const PROPERTY_TYPES = [
		"width" => "dimension",
		"height" => "dimension",
		"orientation" => "orientation",
		"has_alpha_channel" => "bool",
		"is_opaque" => "bool",
		"dpi_x" => "dpi",
		"dpi_y" => "dpi",
		"bit_depth" => "bit_depth",
		"color_space" => "color_space",
		"has_icc_profile" => "bool",
		"animated" => "bool",
		"frame_count" => "count",
	];

	/** The child's whole environment: nothing of PHP's own leaks into it. */
	const PATH = "/usr/local/bin:/usr/bin:/bin";

	/** @param string[] $command argv; [0] must be an absolute path to an executable */
	public function __construct(
		public readonly array $command,
		public readonly int $timeoutS = self::DEFAULT_TIMEOUT_S,
		public readonly int $memoryMb = self::DEFAULT_MEMORY_MB,
		public readonly int $maxPixels = self::DEFAULT_MAX_PIXELS,
	) {
		if($command === [] || !array_is_list($command)) {
			throw new \InvalidArgumentException("the processor command is empty");
		}
		foreach($command as $arg) {
			if(!is_string($arg) || $arg === "" || str_contains($arg, "\0")) {
				throw new \InvalidArgumentException("the processor command must be a list of non-empty strings");
			}
		}
	}

	/**
	 * The processor magrathea.conf describes, or null when `processor_command` isn't set: no
	 * handler, so processing status `none`. A value that can't be read → 5004 (ExplorerConfig).
	 */
	public static function FromConfig(): ?self {
		$command = ExplorerConfig::GetArgv("processor_command");
		if($command === null) return null;
		return new self(
			$command,
			ExplorerConfig::GetPositiveInt("processor_timeout_s", self::DEFAULT_TIMEOUT_S),
			ExplorerConfig::GetPositiveInt("processor_memory_mb", self::DEFAULT_MEMORY_MB),
			ExplorerConfig::GetPositiveInt("max_pixels", self::DEFAULT_MAX_PIXELS),
		);
	}

	public static function EmptyProperties(): array {
		return array_fill_keys(array_keys(self::PROPERTY_TYPES), null);
	}

	/** The extension and mime type a spec's format produces; `legacy` = png for a png original, jpg otherwise. */
	public static function ResolveFormat(string $format, string $sourceMime): string {
		if($format === "legacy") return $sourceMime === "image/png" ? "png" : "jpg";
		return $format;
	}

	/**
	 * Runs one job and checks what comes back. Never throws for anything the processor does:
	 * a failure is a ProcessorResult with `ok` false.
	 *
	 * @param string $input the file to process (often $dir->input; at upload, the staged file)
	 * @param string $fileType Explorer's file_type (image, video, audio, document, other)
	 * @param string $mode MODE_HEADER (properties from headers only, no pixel decoding) or MODE_FULL
	 * @param array $renditions specs: name, width, height, fit, format (webp|png|jpg|legacy), quality?, background?.
	 *                          Ignored in header mode. Validated by the caller (RenditionProfile, Phase 6); the
	 *                          processor refuses a malformed one with exit 2.
	 * @param int|null $timeoutS overrides the configured timeout (an inline run at upload wants a short one)
	 */
	public function Run(ProcessorJobDir $dir, string $input, string $mimeType, string $fileType, string $mode, array $renditions = [], ?int $timeoutS = null): ProcessorResult {
		$start = hrtime(true);
		if($mode !== self::MODE_HEADER && $mode !== self::MODE_FULL) {
			throw new \InvalidArgumentException("unknown processor mode: ".$mode);
		}
		if($this->command[0][0] !== "/" || !is_file($this->command[0]) || !is_executable($this->command[0])) {
			return ProcessorResult::Failed("the processor can't be started: processor_command[0] isn't an absolute path to an executable file");
		}

		$specs = [];
		$requested = [];
		if($mode === self::MODE_FULL) {
			foreach($renditions as $spec) {
				$spec["format"] = self::ResolveFormat((string)($spec["format"] ?? ""), $mimeType);
				$specs[] = $spec;
				$requested[(string)($spec["name"] ?? "")] = self::FORMATS[$spec["format"]] ?? null;
			}
		}
		try {
			$job = json_encode([
				"input" => $input,
				"file_type" => $fileType,
				"mime_type" => $mimeType,
				"mode" => $mode,
				"out_dir" => $dir->outDir,
				"renditions" => $specs,
				"limits" => ["memory_mb" => $this->memoryMb, "max_pixels" => $this->maxPixels],
			], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		} catch(\JsonException $ex) {
			return ProcessorResult::Failed("the job can't be encoded as JSON (a path that isn't UTF-8?)");
		}

		$run = $this->Execute($job, $dir, $timeoutS ?? $this->timeoutS);
		$seconds = (hrtime(true) - $start) / 1e9;
		$fail = fn(string $error) => ProcessorResult::Failed($this->Clean($error, $dir, $input), $seconds, $run["peak_rss_kb"]);

		if($run["error"] !== null) return $fail($run["error"]);
		$reason = self::StderrReason($run["stderr"]);
		if($run["signal"] !== null) {
			return $fail("killed by signal ".$run["signal"].($reason !== null ? " (".$reason.")" : ""));
		}
		if($run["exit"] !== 0) {
			return $fail($reason ?? "exit code ".$run["exit"]);
		}

		try {
			$data = json_decode($run["stdout"], true, 16, JSON_THROW_ON_ERROR);
		} catch(\JsonException $ex) {
			return $fail("invalid JSON on stdout");
		}
		if(!is_array($data) || !is_bool($data["handled"] ?? null) || !is_bool($data["renders"] ?? null)) {
			return $fail("invalid result: `handled` and `renders` must be booleans");
		}
		$engine = is_string($data["engine"] ?? null) ? $this->Clean($data["engine"], $dir, $input, 100) : null;
		if(!$data["handled"]) {
			return new ProcessorResult(true, null, false, false, self::EmptyProperties(), [], [], $engine, $seconds, $run["peak_rss_kb"]);
		}

		$properties = self::EmptyProperties();
		$given = is_array($data["properties"] ?? null) ? $data["properties"] : [];
		foreach(self::PROPERTY_TYPES as $key => $type) {
			$properties[$key] = self::CheckProperty($type, $given[$key] ?? null);
		}

		$kept = [];
		$errors = [];
		if($mode === self::MODE_FULL && $data["renders"]) {
			$outDir = realpath($dir->outDir);
			foreach(is_array($data["renditions"] ?? null) ? $data["renditions"] : [] as $item) {
				$name = is_array($item) && (is_string($item["name"] ?? null) || is_int($item["name"] ?? null)) ? (string)$item["name"] : null;
				if($name === null || !array_key_exists($name, $requested) || isset($kept[$name]) || isset($errors[$name])) continue;
				$why = null;
				$file = $requested[$name] !== null && $outDir !== false
					? self::CheckOutput($item["path"] ?? null, $outDir, $requested[$name], $why)
					: null;
				if($file === null) {
					$errors[$name] = "invalid output: ".($why ?? "unknown format");
				} else {
					$kept[$name] = $file;
				}
			}
			foreach(is_array($data["errors"] ?? null) ? $data["errors"] : [] as $name => $message) {
				$name = (string)$name;
				if(!array_key_exists($name, $requested) || isset($kept[$name]) || isset($errors[$name])) continue;
				$errors[$name] = $this->Clean(is_string($message) ? $message : "failed", $dir, $input);
			}
			foreach(array_keys($requested) as $name) {
				$name = (string)$name;
				if(!isset($kept[$name]) && !isset($errors[$name])) {
					$errors[$name] = "missing from the processor's result";
				}
			}
		}
		return new ProcessorResult(true, null, true, $data["renders"], $properties, $kept, $errors, $engine, $seconds, $run["peak_rss_kb"]);
	}

	/**
	 * proc_open + a non-blocking read loop over stdout and stderr together (one full pipe would
	 * otherwise deadlock the other), until both close or the deadline passes.
	 * @return array{error: ?string, exit: ?int, signal: ?int, stdout: string, stderr: string, peak_rss_kb: ?int}
	 */
	private function Execute(string $job, ProcessorJobDir $dir, int $timeoutS): array {
		$result = ["error" => null, "exit" => null, "signal" => null, "stdout" => "", "stderr" => "", "peak_rss_kb" => null];
		$env = [
			"PATH" => self::PATH,
			"TMPDIR" => $dir->tmpDir,
			"VIPS_CONCURRENCY" => "1",
			"VIPS_BLOCK_UNTRUSTED" => "1",
		];
		$descriptors = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];
		$process = @proc_open($this->command, $descriptors, $pipes, $dir->tmpDir, $env);
		if(!is_resource($process)) {
			$result["error"] = "the processor can't be started (proc_open failed)";
			return $result;
		}
		$deadline = hrtime(true) + $timeoutS * 1000000000;
		$pid = proc_get_status($process)["pid"];
		$open = [1 => $pipes[1], 2 => $pipes[2]];
		$kill = null;
		try {
			// A job is a few KB, far below a pipe's buffer: this write never waits for the child.
			@fwrite($pipes[0], $job);
			fclose($pipes[0]);
			stream_set_blocking($pipes[1], false);
			stream_set_blocking($pipes[2], false);

			while($open !== []) {
				$left = $deadline - hrtime(true);
				if($left <= 0) {
					$kill = "time limit exceeded (".$timeoutS." s)";
					break;
				}
				$read = array_values($open);
				$write = null;
				$except = null;
				$ready = @stream_select($read, $write, $except, 0, (int)min(self::POLL_US, intdiv($left, 1000) + 1));
				$result["peak_rss_kb"] = self::SampleRss($pid, $result["peak_rss_kb"]);
				if($ready === false) {
					usleep(10000); // interrupted by a signal
					continue;
				}
				foreach($read as $stream) {
					$fd = array_search($stream, $open, true);
					$chunk = fread($stream, 65536);
					if($chunk === false || $chunk === "") {
						if(feof($stream)) {
							fclose($stream);
							unset($open[$fd]);
						}
						continue;
					}
					if($fd === 1) {
						$result["stdout"] .= $chunk;
						if(strlen($result["stdout"]) > self::MAX_STDOUT) {
							$kill = "the processor wrote more than ".self::MAX_STDOUT." bytes to stdout";
							break 2;
						}
					} else {
						$result["stderr"] = substr($result["stderr"].$chunk, -self::KEEP_STDERR);
					}
				}
			}

			// Both pipes closed (or a kill is due): reap the child. One that closed its pipes but
			// keeps running still dies at the deadline.
			if($kill !== null) proc_terminate($process, 9);
			while(true) {
				$status = proc_get_status($process);
				if(!$status["running"]) {
					// Before PHP 8.3, only this first non-running call carries the real exit code.
					$result["exit"] = $status["exitcode"];
					$result["signal"] = $status["signaled"] ? $status["termsig"] : null;
					break;
				}
				if($kill === null && hrtime(true) >= $deadline) {
					$kill = "time limit exceeded (".$timeoutS." s)";
					proc_terminate($process, 9);
				}
				$result["peak_rss_kb"] = self::SampleRss($pid, $result["peak_rss_kb"]);
				usleep(10000);
			}
		} finally {
			foreach($open as $stream) fclose($stream);
			if(is_resource($process)) {
				if($result["exit"] === null && $result["signal"] === null) proc_terminate($process, 9);
				proc_close($process);
			}
		}
		if($kill !== null) $result["error"] = $kill;
		return $result;
	}

	/**
	 * A rendition file the processor named, if it's what it claims: an absolute path to a regular
	 * file inside out_dir (no symlink, no hard link), of the expected type per getimagesize().
	 * Dimensions and size are read here, not taken from the result.
	 * @param array{0: string, 1: string} $expected [mime type, extension]
	 */
	private static function CheckOutput(mixed $path, string $outDir, array $expected, ?string &$why): ?array {
		if(!is_string($path) || $path === "" || $path[0] !== "/" || str_contains($path, "\0")) {
			$why = "not an absolute path";
			return null;
		}
		if(is_link($path)) {
			$why = "a symlink";
			return null;
		}
		$real = realpath($path);
		if($real === false || !str_starts_with($real, $outDir."/") || !is_file($real)) {
			$why = "not a file inside out_dir";
			return null;
		}
		clearstatcache(true, $real);
		$stat = @stat($real);
		if($stat === false || $stat["nlink"] !== 1) {
			$why = "a hard link";
			return null;
		}
		$info = @getimagesize($real);
		if($info === false || $info["mime"] !== $expected[0] || $info[0] < 1 || $info[1] < 1) {
			$why = "not a readable ".$expected[0];
			return null;
		}
		return [
			"path" => $real,
			"width" => $info[0],
			"height" => $info[1],
			"mime_type" => $expected[0],
			"extension" => $expected[1],
			"size" => $stat["size"],
		];
	}

	private static function CheckProperty(string $type, mixed $value): mixed {
		if($value === null) return null;
		$int = is_int($value);
		$ok = match($type) {
			"bool" => is_bool($value),
			"dimension" => $int && $value >= 1 && $value <= 10000000,
			"orientation" => $int && $value >= 1 && $value <= 8,
			"dpi" => $int && $value >= 1 && $value <= 1000000,
			"bit_depth" => in_array($value, [1, 2, 4, 8, 16, 32, 64], true),
			"color_space" => is_string($value) && preg_match('/^[a-z0-9-]{1,32}$/', $value) === 1,
			"count" => $int && $value >= 1 && $value <= 1000000,
		};
		return $ok ? $value : null;
	}

	/** The processor's own reason (`processor: …`, its last line), else stderr's last line. */
	private static function StderrReason(string $stderr): ?string {
		$lines = array_values(array_filter(array_map("trim", preg_split('/\R/', $stderr)), fn($l) => $l !== ""));
		for($i = count($lines) - 1; $i >= 0; $i--) {
			if(str_starts_with($lines[$i], "processor: ")) return substr($lines[$i], strlen("processor: "));
		}
		return $lines === [] ? null : $lines[count($lines) - 1];
	}

	/** One line, valid UTF-8, the job's paths masked, at most $max characters. */
	private function Clean(string $text, ProcessorJobDir $dir, string $input, int $max = self::MAX_ERROR_CHARS): string {
		$text = str_replace([$input, $dir->path, dirname($dir->path)], ["{input}", "{job}", "{tmp}"], $text);
		if(preg_match('//u', $text) !== 1) $text = preg_replace('/[\x80-\xFF]/', "?", $text);
		$text = trim(preg_replace('/[\x00-\x1F\x7F]+/', " ", $text));
		if(preg_match('/^.{'.($max + 1).'}/su', $text) === 1) {
			preg_match('/^.{0,'.($max - 1).'}/su', $text, $m);
			$text = $m[0]."…";
		}
		return $text;
	}

	/** VmHWM (peak RSS) from /proc, or $peak unchanged where there's no /proc. */
	private static function SampleRss(int $pid, ?int $peak): ?int {
		$status = @file_get_contents("/proc/".$pid."/status");
		if($status === false || preg_match('/^VmHWM:\s+(\d+)\s+kB/m', $status, $m) !== 1) return $peak;
		return max($peak ?? 0, (int)$m[1]);
	}

}
