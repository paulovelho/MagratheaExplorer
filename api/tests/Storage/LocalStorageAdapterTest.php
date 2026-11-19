<?php

namespace MagratheaExplorer\Tests\Storage;

use MagratheaExplorer\Storage\LocalStorageAdapter;
use MagratheaExplorer\Storage\StorageConfig;
use MagratheaExplorer\Storage\StorageException;
use MagratheaExplorer\Tests\Support\ScratchDir;
use PHPUnit\Framework\TestCase;

/** fetch(): the bytes put() stored, or an exception and nothing at the target. */
class LocalStorageAdapterTest extends TestCase {

	private string $dir;
	private LocalStorageAdapter $storage;

	protected function setUp(): void {
		$this->dir = ScratchDir::Create();
		mkdir($this->dir."/configs");
		mkdir($this->dir."/storage");
		file_put_contents($this->dir."/configs/storage.conf",
			"[test]\n\tdriver = \"local\"\n\tlocal_path = \"".$this->dir."/storage\"\n\tlocal_url = \"http://files.test\"\n");
		StorageConfig::SetConfigRootForTests($this->dir."/configs");
		$this->storage = new LocalStorageAdapter();
	}

	protected function tearDown(): void {
		StorageConfig::SetConfigRootForTests(null);
		ScratchDir::Remove($this->dir);
	}

	public function testFetchReturnsTheStoredBytes(): void {
		// every byte value, over several copy buffers
		$bytes = str_repeat(implode("", array_map("chr", range(0, 255))), 12 * 1024) . "\r\n\0tail";
		$source = $this->dir."/source.bin";
		file_put_contents($source, $bytes);
		$this->storage->put("key-uuid/Tok3n.bin", $source, ["content_type" => "application/octet-stream", "disposition" => "attachment"]);

		$target = tempnam($this->dir, "fetch-"); // an existing placeholder, as callers pass it
		$this->storage->fetch("key-uuid/Tok3n.bin", $target);
		$this->assertSame(hash("sha256", $bytes), hash_file("sha256", $target));
		$this->assertSame(strlen($bytes), filesize($target));
	}

	public function testFetchOverwritesTheTarget(): void {
		$source = $this->dir."/source.txt";
		file_put_contents($source, "stored");
		$this->storage->put("k/a.txt", $source, ["content_type" => "text/plain", "disposition" => "inline"]);
		$target = $this->dir."/target.txt";
		file_put_contents($target, "something much longer than the stored object");
		$this->storage->fetch("k/a.txt", $target);
		$this->assertSame("stored", file_get_contents($target));
	}

	public function testMissingObjectLeavesNothingBehind(): void {
		$target = tempnam($this->dir, "fetch-");
		try {
			$this->storage->fetch("k/missing.png", $target);
			$this->fail("fetched a missing object");
		} catch(StorageException $ex) {
			$this->assertStringContainsString("k/missing.png", $ex->getMessage());
		}
		$this->assertFileDoesNotExist($target);
	}

	public function testUnwritableTargetThrows(): void {
		$source = $this->dir."/source.txt";
		file_put_contents($source, "stored");
		$this->storage->put("k/a.txt", $source, ["content_type" => "text/plain", "disposition" => "inline"]);
		$this->expectException(StorageException::class);
		$this->storage->fetch("k/a.txt", $this->dir."/no-such-dir/target.txt");
	}


	public function testCopyKeepsTheSourceAndMakesDirectories(): void {
		$source = $this->dir."/source.txt";
		file_put_contents($source, "rotate me");
		$this->storage->put("k/old.txt", $source, ["content_type" => "text/plain", "disposition" => "inline"]);
		$this->storage->copy("k/old.txt", "other/dir/new.txt");
		$this->assertSame("rotate me", file_get_contents($this->dir."/storage/other/dir/new.txt"));
		$this->assertTrue($this->storage->exists("k/old.txt"), "the source stays until it's deleted");
	}

	public function testCopyOfAMissingObjectFails(): void {
		$this->expectException(StorageException::class);
		$this->storage->copy("k/missing.txt", "k/new.txt");
	}

}
