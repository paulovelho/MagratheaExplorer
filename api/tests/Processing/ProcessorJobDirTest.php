<?php

namespace MagratheaExplorer\Tests\Processing;

use MagratheaExplorer\Processing\ProcessorJobDir;
use MagratheaExplorer\Tests\Support\ScratchDir;
use MagratheaExplorer\Tests\Support\TestConfig;
use PHPUnit\Framework\TestCase;

class ProcessorJobDirTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		$this->root = ScratchDir::Create();
	}

	protected function tearDown(): void {
		ScratchDir::Remove($this->root);
		TestConfig::Set();
	}

	public function testCreateMakesAPrivateLayout(): void {
		$dir = ProcessorJobDir::Create("job-12-1", $this->root);
		$this->assertSame($this->root."/job-12-1", $dir->path);
		foreach([$dir->path, $dir->outDir, $dir->tmpDir] as $path) {
			$this->assertDirectoryExists($path);
			$this->assertSame(0700, fileperms($path) & 0777, $path);
		}
		$this->assertSame($dir->path."/original", $dir->input);
		$this->assertFileDoesNotExist($dir->input, "the caller puts the input there");
	}

	public function testItLivesInProcessingTmpPathByDefault(): void {
		TestConfig::Set(["processing_tmp_path" => $this->root."/tmp"]);
		$this->assertSame($this->root."/tmp/job-1-1", ProcessorJobDir::Create("job-1-1")->path);
	}

	public function testCreateWipesWhatAKilledRunLeftBehind(): void {
		$dir = ProcessorJobDir::Create("job-5-2", $this->root);
		file_put_contents($dir->outDir."/thumb.webp", "stale");
		file_put_contents($dir->input, "stale");

		$again = ProcessorJobDir::Create("job-5-2", $this->root);
		$this->assertSame([], array_diff(scandir($again->outDir), [".", ".."]));
		$this->assertFileDoesNotExist($again->input);
	}

	public function testRemoveNeverFollowsSymlinks(): void {
		$outside = ScratchDir::Create();
		try {
			file_put_contents($outside."/precious", "keep me");
			$dir = ProcessorJobDir::Create("job-9-1", $this->root);
			symlink($outside, $dir->outDir."/to-dir");
			symlink($outside."/precious", $dir->outDir."/to-file");
			mkdir($dir->outDir."/nested/deeper", 0700, true);
			file_put_contents($dir->outDir."/nested/deeper/x", "x");

			$this->assertTrue($dir->Remove());
			$this->assertDirectoryDoesNotExist($dir->path);
			$this->assertSame("keep me", file_get_contents($outside."/precious"));
			$this->assertTrue($dir->Remove(), "removing twice is fine");
		} finally {
			ScratchDir::Remove($outside);
		}
	}

	public function testNamesThatCouldEscapeAreRefused(): void {
		foreach(["", "..", "../x", ".hidden", "a/b", "a b", str_repeat("a", 65)] as $name) {
			try {
				ProcessorJobDir::Create($name, $this->root);
				$this->fail("accepted ".json_encode($name));
			} catch(\InvalidArgumentException $ex) {
				$this->addToAssertionCount(1);
			}
		}
	}

}
