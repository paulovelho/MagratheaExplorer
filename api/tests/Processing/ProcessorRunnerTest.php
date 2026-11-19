<?php

namespace MagratheaExplorer\Tests\Processing;

use MagratheaExplorer\Processing\ProcessorJobDir;
use MagratheaExplorer\Processing\ProcessorRunner;
use MagratheaExplorer\Tests\Support\ScratchDir;
use MagratheaExplorer\Tests\Support\TestConfig;
use PHPUnit\Framework\TestCase;

/**
 * The runner against fake processors (small PHP scripts run by this PHP binary): the contract
 * from Explorer's side, no Python needed. The real engine is checked by run-fixtures.php.
 */
class ProcessorRunnerTest extends TestCase {

	const SPECS = [
		["name" => "thumb", "width" => 300, "height" => 300, "fit" => "contain", "format" => "png"],
		["name" => "legacy", "width" => 200, "height" => 200, "fit" => "cover", "format" => "legacy"],
	];

	/** Prepended to every fake: reads the job, and helpers to write a real PNG and print a result. */
	const PRELUDE = <<<'PHP'
		<?php
		$job = json_decode(stream_get_contents(STDIN), true);
		function png(string $path, int $w, int $h): string {
			$chunk = fn($t, $d) => pack("N", strlen($d)).$t.$d.pack("N", crc32($t.$d));
			$raw = str_repeat("\0".str_repeat("\0\0\0", $w), $h);
			file_put_contents($path, "\x89PNG\r\n\x1a\n".$chunk("IHDR", pack("NNCCCCC", $w, $h, 8, 2, 0, 0, 0))
				.$chunk("IDAT", gzcompress($raw)).$chunk("IEND", ""));
			return $path;
		}
		function result(array $extra = []): void {
			echo json_encode($extra + ["handled" => true, "renders" => true, "properties" => [], "renditions" => [], "errors" => [], "engine" => "fake"]);
		}

		PHP;

	private string $dir;
	private string $input;
	private int $n = 0;

	protected function setUp(): void {
		$this->dir = ScratchDir::Create();
		$this->input = $this->dir."/upload.bin";
		file_put_contents($this->input, "not read by the fakes");
	}

	protected function tearDown(): void {
		ScratchDir::Remove($this->dir);
		TestConfig::Set();
	}

	public function testRenditionsAreMeasuredHereNotTakenFromTheResult(): void {
		$result = $this->RunFake(<<<'PHP'
			$thumb = png($job["out_dir"]."/thumb.png", 3, 2);
			$legacy = png($job["out_dir"]."/legacy.png", 2, 2);
			result(["renditions" => [
				["name" => "thumb", "path" => $thumb, "width" => 999, "height" => 999, "mime_type" => "image/gif", "size" => 1],
				["name" => "legacy", "path" => $legacy, "width" => 2, "height" => 2, "mime_type" => "image/png", "size" => 1],
			]]);
			PHP);

		$this->assertTrue($result->ok, (string)$result->error);
		$this->assertTrue($result->handled);
		$this->assertTrue($result->renders);
		$this->assertSame([], $result->errors);
		$this->assertSame(["thumb", "legacy"], array_keys($result->renditions));
		$thumb = $result->renditions["thumb"];
		$this->assertSame([3, 2, "image/png", "png"], [$thumb["width"], $thumb["height"], $thumb["mime_type"], $thumb["extension"]]);
		$this->assertSame(filesize($thumb["path"]), $thumb["size"]);
		$this->assertSame("image/png", $result->renditions["legacy"]["mime_type"], "legacy = png for a png original");
		$this->assertSame("fake", $result->engine);
		$this->assertGreaterThan(0, $result->seconds);
	}

	public function testTheJobAndTheEnvironmentTheProcessorGets(): void {
		$script = <<<'PHP'
			file_put_contents($job["out_dir"]."/../seen.json", json_encode(["job" => $job, "env" => getenv(), "cwd" => getcwd()]));
			result();
			PHP;
		$dir = $this->JobDir();
		$runner = $this->Runner($script);
		$runner->Run($dir, $this->input, "image/jpeg", "image", ProcessorRunner::MODE_FULL, self::SPECS);
		$seen = json_decode(file_get_contents($dir->path."/seen.json"), true);

		$job = $seen["job"];
		$this->assertSame([$this->input, "image", "image/jpeg", "full", $dir->outDir],
			[$job["input"], $job["file_type"], $job["mime_type"], $job["mode"], $job["out_dir"]]);
		$this->assertSame(["memory_mb" => 64, "max_pixels" => 1000], $job["limits"]);
		$this->assertSame(["png", "jpg"], array_column($job["renditions"], "format"), "legacy = jpg for anything but png");

		$env = $seen["env"];
		ksort($env);
		$this->assertSame(["PATH", "TMPDIR", "VIPS_BLOCK_UNTRUSTED", "VIPS_CONCURRENCY"], array_keys($env), "nothing of PHP's environment leaks");
		$this->assertSame($dir->tmpDir, $env["TMPDIR"]);
		$this->assertSame("1", $env["VIPS_CONCURRENCY"]);
		$this->assertSame(realpath($dir->tmpDir), realpath($seen["cwd"]));
	}

	public function testHeaderModeSendsNoRenditionsAndIgnoresAnyReturned(): void {
		$dir = $this->JobDir();
		$result = $this->Runner(<<<'PHP'
			file_put_contents($job["out_dir"]."/../seen.json", json_encode($job));
			result(["properties" => ["width" => 4], "renditions" => [["name" => "thumb", "path" => png($job["out_dir"]."/thumb.png", 1, 1)]], "errors" => ["thumb" => "x"]]);
			PHP)->Run($dir, $this->input, "image/png", "image", ProcessorRunner::MODE_HEADER, self::SPECS);

		$this->assertSame([], json_decode(file_get_contents($dir->path."/seen.json"), true)["renditions"]);
		$this->assertTrue($result->ok);
		$this->assertSame(4, $result->properties["width"]);
		$this->assertSame([], $result->renditions);
		$this->assertSame([], $result->errors);
	}

	public function testPropertiesOutsideTheirTypeOrRangeBecomeNull(): void {
		$result = $this->RunFake(<<<'PHP'
			result(["properties" => [
				"width" => "640", "height" => 480, "orientation" => 9, "has_alpha_channel" => 1, "is_opaque" => false,
				"dpi_x" => 299.9994, "dpi_y" => 300, "bit_depth" => 12, "color_space" => "Bad Space", "has_icc_profile" => true,
				"animated" => null, "frame_count" => 0, "secret" => "/etc/passwd",
			]]);
			PHP, ProcessorRunner::MODE_HEADER);

		$this->assertSame([
			"width" => null, "height" => 480, "orientation" => null, "has_alpha_channel" => null, "is_opaque" => false,
			"dpi_x" => null, "dpi_y" => 300, "bit_depth" => null, "color_space" => null, "has_icc_profile" => true,
			"animated" => null, "frame_count" => null,
		], $result->properties);
	}

	public function testOutputsThatArentWhatTheyClaimAreDropped(): void {
		$specs = [];
		foreach(["outside", "symlink", "hardlink", "text", "relative", "webp", "good"] as $name) {
			$specs[] = ["name" => $name, "width" => 10, "height" => 10, "fit" => "contain", "format" => $name === "webp" ? "webp" : "png"];
		}
		$result = $this->RunFake(<<<'PHP'
			$out = $job["out_dir"];
			$elsewhere = png($out."/../elsewhere.png", 1, 1);
			symlink($elsewhere, $out."/symlink.png");
			link($elsewhere, $out."/hardlink.png");
			file_put_contents($out."/text.png", "just text");
			png($out."/relative.png", 1, 1);
			png($out."/webp.webp", 1, 1);
			$good = png($out."/good.png", 1, 1);
			$unknown = png($out."/unknown.png", 1, 1);
			result(["renditions" => [
				["name" => "outside", "path" => $elsewhere],
				["name" => "symlink", "path" => $out."/symlink.png"],
				["name" => "hardlink", "path" => $out."/hardlink.png"],
				["name" => "text", "path" => $out."/text.png"],
				["name" => "relative", "path" => "relative.png"],
				["name" => "webp", "path" => $out."/webp.webp"],
				["name" => "good", "path" => $good],
				["name" => "good", "path" => "/etc/passwd"],
				["name" => "unknown", "path" => $unknown],
			]]);
			PHP, ProcessorRunner::MODE_FULL, $specs);

		$this->assertTrue($result->ok);
		$this->assertSame(["good"], array_keys($result->renditions), "the first entry for a name wins; unrequested names are ignored");
		$this->assertSame([
			"outside" => "invalid output: not a file inside out_dir",
			"symlink" => "invalid output: a symlink",
			"hardlink" => "invalid output: a hard link",
			"text" => "invalid output: not a readable image/png",
			"relative" => "invalid output: not an absolute path",
			"webp" => "invalid output: not a readable image/webp",
		], $result->errors);
	}

	public function testReportedAndMissingRenditionErrors(): void {
		$result = $this->RunFake(<<<'PHP'
			result(["errors" => ["thumb" => "can't\x01save ".$job["input"], "nope" => "not requested", "legacy" => ["not", "a", "string"]]]);
			PHP);

		$this->assertSame(["thumb" => "can't save {input}", "legacy" => "failed"], $result->errors);
		$this->assertSame([], $result->renditions);
	}

	public function testARenditionNobodyMentionsIsAnError(): void {
		$result = $this->RunFake('result();');
		$this->assertSame([
			"thumb" => "missing from the processor's result",
			"legacy" => "missing from the processor's result",
		], $result->errors);
	}

	public function testALongErrorIsCutTo500Characters(): void {
		$result = $this->RunFake('result(["errors" => ["thumb" => str_repeat("é", 2000)]]);');
		$this->assertSame(500, preg_match_all('/./su', $result->errors["thumb"]));
		$this->assertStringEndsWith("…", $result->errors["thumb"]);
	}

	public function testTheFailureReasonIsTheProcessorsLastLine(): void {
		$result = $this->RunFake(<<<'PHP'
			fwrite(STDERR, "VIPS-WARNING: something\nprocessor: can't decode the image: ".$job["input"]." in ".$job["out_dir"]."\ntrailing noise\n");
			exit(1);
			PHP);
		$this->assertFalse($result->ok);
		$this->assertSame("can't decode the image: {input} in {job}/out", $result->error);
		$this->assertSame([], $result->renditions);
		$this->assertSame(ProcessorRunner::EmptyProperties(), $result->properties);
	}

	public function testWithoutItsOwnReasonStderrsLastLineIsUsed(): void {
		$result = $this->RunFake('fwrite(STDERR, "Traceback (most recent call last):\n  File x\nModuleNotFoundError: No module named \'pyvips\'\n"); exit(1);');
		$this->assertSame("ModuleNotFoundError: No module named 'pyvips'", $result->error);

		$result = $this->RunFake('exit(3);');
		$this->assertSame("exit code 3", $result->error);
	}

	public function testATimeoutKillsTheProcessor(): void {
		$start = microtime(true);
		$result = $this->RunFake('sleep(30);', timeout: 1);
		$this->assertFalse($result->ok);
		$this->assertSame("time limit exceeded (1 s)", $result->error);
		$this->assertLessThan(5, microtime(true) - $start);
	}

	public function testAProcessorThatClosesItsPipesAndHangsStillTimesOut(): void {
		$start = microtime(true);
		$result = $this->RunFake('fclose(STDOUT); fclose(STDERR); sleep(30);', timeout: 1);
		$this->assertSame("time limit exceeded (1 s)", $result->error);
		$this->assertLessThan(5, microtime(true) - $start);
	}

	public function testAChattyStderrDoesntDeadlock(): void {
		// far more than a pipe's buffer, written before the result: both pipes are drained together
		$result = $this->RunFake('for($i = 0; $i < 64; $i++) fwrite(STDERR, str_repeat("x", 65536)); result(["properties" => ["width" => 1]]);', ProcessorRunner::MODE_HEADER);
		$this->assertTrue($result->ok, (string)$result->error);
		$this->assertSame(1, $result->properties["width"]);
	}

	public function testHugeOutputKillsTheProcessor(): void {
		$result = $this->RunFake('for($i = 0; $i < 48; $i++) echo str_repeat("x", 65536); sleep(30);');
		$this->assertSame("the processor wrote more than 1048576 bytes to stdout", $result->error);
	}

	public function testUnreadableResults(): void {
		$this->assertSame("invalid JSON on stdout", $this->RunFake('echo "not json";')->error);
		$this->assertSame("invalid JSON on stdout", $this->RunFake('')->error);
		$this->assertSame("invalid result: `handled` and `renders` must be booleans", $this->RunFake('echo json_encode(["renders" => true]);')->error);
		$this->assertSame("invalid result: `handled` and `renders` must be booleans", $this->RunFake('echo "[]";')->error);
	}

	public function testNoHandlerIsNotAFailure(): void {
		$result = $this->RunFake('result(["handled" => false, "renders" => false, "properties" => ["width" => 5]]);');
		$this->assertTrue($result->ok);
		$this->assertFalse($result->handled);
		$this->assertSame(ProcessorRunner::EmptyProperties(), $result->properties);
		$this->assertSame([], $result->renditions);
		$this->assertSame([], $result->errors);
	}

	public function testKilledBySignal(): void {
		if(!function_exists("posix_kill")) $this->markTestSkipped("needs ext-posix");
		$result = $this->RunFake('fwrite(STDERR, "processor: about to die\n"); posix_kill(posix_getpid(), 9); sleep(5);');
		$this->assertSame("killed by signal 9 (about to die)", $result->error);
	}

	public function testTheCommandMustBeAnAbsolutePathToAnExecutable(): void {
		$text = $this->dir."/not-executable";
		file_put_contents($text, "#!/bin/sh\n");
		foreach([["php"], [$text]] as $command) {
			$result = (new ProcessorRunner($command))->Run($this->JobDir(), $this->input, "image/png", "image", ProcessorRunner::MODE_HEADER);
			$this->assertFalse($result->ok);
			$this->assertStringContainsString("processor_command[0]", (string)$result->error);
		}
	}

	public function testBadCommandsAndModesAreProgrammingErrors(): void {
		foreach([[], ["a" => "/bin/x"], ["/bin/x", ""], ["/bin/x", 3]] as $command) {
			try {
				new ProcessorRunner($command);
				$this->fail("accepted ".json_encode($command));
			} catch(\InvalidArgumentException $ex) {
				$this->addToAssertionCount(1);
			}
		}
		$this->expectException(\InvalidArgumentException::class);
		$this->Runner('result();')->Run($this->JobDir(), $this->input, "image/png", "image", "everything");
	}

	public function testFromConfig(): void {
		$this->assertNull(ProcessorRunner::FromConfig(), "no processor_command, no handler");

		TestConfig::Set(["processor_command" => '["/venv/bin/python", "/app/processor.py"]']);
		$runner = ProcessorRunner::FromConfig();
		$this->assertSame(["/venv/bin/python", "/app/processor.py"], $runner->command);
		$this->assertSame([ProcessorRunner::DEFAULT_TIMEOUT_S, ProcessorRunner::DEFAULT_MEMORY_MB, ProcessorRunner::DEFAULT_MAX_PIXELS],
			[$runner->timeoutS, $runner->memoryMb, $runner->maxPixels]);

		TestConfig::Set(["processor_command" => '["/x"]', "processor_timeout_s" => "30", "processor_memory_mb" => "100", "max_pixels" => "5000"]);
		$runner = ProcessorRunner::FromConfig();
		$this->assertSame([30, 100, 5000], [$runner->timeoutS, $runner->memoryMb, $runner->maxPixels]);
	}

	public function testResolveFormat(): void {
		$this->assertSame("png", ProcessorRunner::ResolveFormat("legacy", "image/png"));
		$this->assertSame("jpg", ProcessorRunner::ResolveFormat("legacy", "image/webp"));
		$this->assertSame("webp", ProcessorRunner::ResolveFormat("webp", "image/png"));
	}

	private function RunFake(string $script, string $mode = ProcessorRunner::MODE_FULL, array $specs = self::SPECS, int $timeout = 10) {
		return $this->Runner($script, $timeout)->Run($this->JobDir(), $this->input, "image/png", "image", $mode, $specs);
	}

	private function Runner(string $script, int $timeout = 10): ProcessorRunner {
		$path = $this->dir."/fake-".(++$this->n).".php";
		file_put_contents($path, self::PRELUDE.$script."\n");
		return new ProcessorRunner([PHP_BINARY, $path], $timeout, 64, 1000);
	}

	private function JobDir(): ProcessorJobDir {
		return ProcessorJobDir::Create("job-".(++$this->n), $this->dir);
	}

}
