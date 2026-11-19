<?php

namespace MagratheaExplorer\Tests\Shared;

use Magrathea\Exceptions\MagratheaApiException;
use Magrathea\MagratheaPHP;
use MagratheaExplorer\ExplorerConfig;
use MagratheaExplorer\Tests\Support\ScratchDir;
use MagratheaExplorer\Tests\Support\TestConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** magrathea.conf readers: absent → default, unreadable → 5004 naming the key (never its value). */
class ExplorerConfigTest extends TestCase {

	protected function tearDown(): void {
		TestConfig::Set();
		foreach($this->scratch as $dir) ScratchDir::Remove($dir);
		$this->scratch = [];
	}

	public function testBoolAbsentIsDefault(): void {
		$this->assertFalse(ExplorerConfig::GetBool("flag", false));
		$this->assertTrue(ExplorerConfig::GetBool("flag", true));
	}

	public static function boolValues(): array {
		return [
			"quoted true" => ["true", true], "1 (unquoted true/yes/on)" => ["1", true], "on" => ["on", true],
			"quoted false" => ["false", false], "empty (unquoted false/no/off)" => ["", false], "0" => ["0", false],
		];
	}

	#[DataProvider("boolValues")]
	public function testBool(string $raw, bool $expected): void {
		TestConfig::Set(["flag" => $raw]);
		$this->assertSame($expected, ExplorerConfig::GetBool("flag", !$expected));
	}

	public function testBoolInvalid(): void {
		TestConfig::Set(["flag" => "ture"]);
		$this->assertInvalid(fn() => ExplorerConfig::GetBool("flag", false), "flag", "ture");
	}

	public static function sizes(): array {
		return [
			"plain" => ["26214400", 26214400], "K" => ["512K", 524288], "M" => ["25M", 26214400],
			"G" => ["1G", 1073741824], "lowercase" => ["25m", 26214400], "trimmed" => [" 5M ", 5242880],
		];
	}

	#[DataProvider("sizes")]
	public function testParseSize(string $raw, int $expected): void {
		$this->assertSame($expected, ExplorerConfig::ParseSize($raw));
	}

	public function testParseSizeRefusesGarbage(): void {
		foreach(["", "25MB", "1.5M", "-1", "M", "25 M"] as $raw) {
			$this->assertNull(ExplorerConfig::ParseSize($raw), $raw);
		}
	}

	public function testSize(): void {
		$this->assertSame(7, ExplorerConfig::GetSize("size", 7));
		TestConfig::Set(["size" => ""]);
		$this->assertSame(7, ExplorerConfig::GetSize("size", 7));
		TestConfig::Set(["size" => "2K"]);
		$this->assertSame(2048, ExplorerConfig::GetSize("size", 7));
	}

	public function testSizeInvalid(): void {
		foreach(["25MB", "0"] as $raw) {
			TestConfig::Set(["size" => $raw]);
			$this->assertInvalid(fn() => ExplorerConfig::GetSize("size", 7), "size", $raw);
		}
	}

	public function testPositiveInt(): void {
		$this->assertSame(9, ExplorerConfig::GetPositiveInt("n", 9));
		TestConfig::Set(["n" => "40000000"]);
		$this->assertSame(40000000, ExplorerConfig::GetPositiveInt("n", 9));
		foreach(["0", "40M", "4e7", "-5"] as $raw) {
			TestConfig::Set(["n" => $raw]);
			$this->assertInvalid(fn() => ExplorerConfig::GetPositiveInt("n", 9), "n", $raw);
		}
	}

	public function testArgv(): void {
		$this->assertNull(ExplorerConfig::GetArgv("cmd"));
		TestConfig::Set(["cmd" => " "]);
		$this->assertNull(ExplorerConfig::GetArgv("cmd"));
		// how parse_ini_file hands over a single-quoted value: as written
		TestConfig::Set(["cmd" => '["/home/u/venv/bin/python", "/path with space/processor.py"]']);
		$this->assertSame(["/home/u/venv/bin/python", "/path with space/processor.py"], ExplorerConfig::GetArgv("cmd"));
	}

	public function testArgvInvalid(): void {
		foreach(["/usr/bin/python processor.py", "[]", '{"0":"/bin/x"}', '["/bin/x", 3]', '["/bin/x", ""]', '"/bin/x"', '["/bin/x"'] as $raw) {
			TestConfig::Set(["cmd" => $raw]);
			$this->assertInvalid(fn() => ExplorerConfig::GetArgv("cmd"), "cmd", $raw);
		}
	}

	public function testProcessingTmpPathConfiguredIsCreated(): void {
		$base = $this->scratchDir();
		TestConfig::Set(["processing_tmp_path" => $base."/a/b/"]);
		$this->assertSame($base."/a/b", ExplorerConfig::ProcessingTmpPath());
		$this->assertDirectoryExists($base."/a/b");
	}

	public function testProcessingTmpPathAbsentIsInsideTheInstance(): void {
		// magRoot is api/: point it at a scratch dir so the test never creates api/tmp itself
		$magRoot = MagratheaPHP::Instance()->magRoot;
		MagratheaPHP::Instance()->magRoot = $this->scratchDir();
		try {
			$this->assertSame(MagratheaPHP::Instance()->magRoot."/tmp", ExplorerConfig::ProcessingTmpPath());
			TestConfig::Set(["processing_tmp_path" => " "]);
			$this->assertSame(MagratheaPHP::Instance()->magRoot."/tmp", ExplorerConfig::ProcessingTmpPath());
		} finally {
			MagratheaPHP::Instance()->magRoot = $magRoot;
		}
	}

	public function testProcessingTmpPathThatCantBeADirectory(): void {
		$file = $this->scratchDir()."/a-file";
		touch($file);
		TestConfig::Set(["processing_tmp_path" => $file]);
		$this->assertInvalid(fn() => ExplorerConfig::ProcessingTmpPath(), "processing_tmp_path", $file);
	}

	private array $scratch = [];

	private function scratchDir(): string {
		return $this->scratch[] = ScratchDir::Create();
	}

	private function assertInvalid(callable $read, string $key, string $raw): void {
		try {
			$read();
			$this->fail("expected 5004 for ".$key." = ".$raw);
		} catch(MagratheaApiException $ex) {
			$this->assertSame(5004, $ex->getCode());
			$this->assertStringContainsString("`".$key."`", $ex->getMessage());
			if(trim($raw) !== "" && !is_numeric($raw)) {
				$this->assertStringNotContainsString($raw, $ex->getMessage(), "the value must not reach clients");
			}
		}
	}

}
