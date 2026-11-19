<?php

namespace MagratheaExplorer\Tests\Processing;

use MagratheaExplorer\Key\KeyControl;
use MagratheaExplorer\Processing\ProcessingQueue;
use MagratheaExplorer\Processing\ProcessorJobDir;
use MagratheaExplorer\Tests\Support\ScratchDir;
use PHPUnit\Framework\TestCase;

/**
 * What the queue does without a database: sweeping job folders left by killed runs, and the
 * key form's profile parsing. The queue itself (claims, retries, commits) needs MySQL: it's
 * covered by the integration run (plan §7.12).
 */
class ProcessingQueueTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = ScratchDir::Create();
	}

	protected function tearDown(): void {
		ScratchDir::Remove($this->dir);
	}

	public function testSweepRemovesOnlyOldJobFolders(): void {
		$old = time() - ProcessingQueue::STALE_DIR_SECONDS - 60;
		$oldJob = ProcessorJobDir::Create("job-12-1", $this->dir);
		file_put_contents($oldJob->outDir."/thumb.webp", "x");
		$oldHeader = ProcessorJobDir::Create("header-abc", $this->dir);
		$fresh = ProcessorJobDir::Create("job-13-1", $this->dir);
		mkdir($this->dir."/keep-me");
		file_put_contents($this->dir."/process.lock", "");
		$target = ScratchDir::Create();
		file_put_contents($target."/precious", "x");
		symlink($target, $this->dir."/job-99-1");
		foreach([$oldJob->path, $oldHeader->path, $this->dir."/keep-me"] as $path) touch($path, $old);
		touch($this->dir."/process.lock", $old);

		try {
			$this->assertSame(2, ProcessingQueue::SweepJobDirs($this->dir));
			$this->assertDirectoryDoesNotExist($oldJob->path);
			$this->assertDirectoryDoesNotExist($oldHeader->path);
			$this->assertDirectoryExists($fresh->path, "younger than a day: maybe a live run");
			$this->assertDirectoryExists($this->dir."/keep-me", "not a job folder name");
			$this->assertFileExists($this->dir."/process.lock");
			$this->assertTrue(is_link($this->dir."/job-99-1"), "a link is never followed or removed");
			$this->assertFileExists($target."/precious");
		} finally {
			ScratchDir::Remove($target);
		}
	}

	public function testSweepOfAMissingRootIsANoop(): void {
		$this->assertSame(0, ProcessingQueue::SweepJobDirs($this->dir."/nope"));
	}

	public function testTheKeyFormStoresTheProfileNormalized(): void {
		$this->assertNull(KeyControl::ParseRenditionProfile(""), "empty = the instance default");
		$this->assertNull(KeyControl::ParseRenditionProfile("  \n "));
		$this->assertSame("[]", KeyControl::ParseRenditionProfile("[ ]"));
		$this->assertSame(
			'[{"name":"thumb","width":300,"height":300,"fit":"contain","format":"webp","quality":85}]',
			KeyControl::ParseRenditionProfile('[{"name":"thumb","format":"webp","width":300,"height":300,"fit":"contain"}]')
		);
		$this->expectException(\InvalidArgumentException::class);
		KeyControl::ParseRenditionProfile('[{"name":"thumb"}]');
	}

}
