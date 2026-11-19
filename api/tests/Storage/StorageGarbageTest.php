<?php

namespace MagratheaExplorer\Tests\Storage;

use MagratheaExplorer\Storage\StorageGarbage;
use MagratheaExplorer\Tests\Support\FakeDatabase;
use MagratheaExplorer\Tests\Support\FakeStorage;
use PHPUnit\Framework\TestCase;

/** The storage garbage queue (Tier 3 §4): queue what fails, retry it, never delete what's in use. */
class StorageGarbageTest extends TestCase {

	private FakeDatabase $db;
	private FakeStorage $storage;

	protected function setUp(): void {
		$this->db = FakeDatabase::Install();
		$this->storage = new FakeStorage();
		$this->storage->objects = ["k/aaa.png" => "a", "k/bbb.png" => "b"];
	}

	protected function tearDown(): void {
		FakeDatabase::Uninstall();
	}

	public function testDeleteQueuesOnlyWhatFails(): void {
		$this->storage->failOn["delete"] = ["k/bbb.png"];
		$queued = StorageGarbage::Delete($this->storage, ["k/aaa.png", "k/bbb.png", "k/aaa.png", ""]);
		$this->assertSame(1, $queued);
		$this->assertArrayNotHasKey("k/aaa.png", $this->storage->objects);
		$inserts = $this->db->Statements('/^INSERT INTO `storage_garbage`/');
		$this->assertCount(1, $inserts);
		$this->assertSame("k/bbb.png", $inserts[0]["values"][0]);
		$this->assertStringContainsString("fake delete failure", $inserts[0]["values"][1]);
		$this->assertSame(["delete k/aaa.png", "delete k/bbb.png"], $this->storage->log, "duplicates and empty paths skipped");
	}

	public function testAQueueThatCantBeWrittenDoesntThrow(): void {
		$this->storage->failOn["delete"] = ["*"];
		$this->db->On('/^INSERT INTO `storage_garbage`/', fn() => throw new \RuntimeException("db down"));
		$this->assertSame(0, StorageGarbage::Delete($this->storage, ["k/aaa.png"]));
	}

	public function testRetry(): void {
		$this->db
			->On('/^SELECT `id`, `storage_path` FROM `storage_garbage` WHERE `attempts` < 20/', fn() => [
				["id" => 1, "storage_path" => "k/aaa.png"],
				["id" => 2, "storage_path" => "k/bbb.png"],
				["id" => 3, "storage_path" => "k/inuse.png"],
			])
			->On("/`token` = 'inuse'/", fn() => 1);
		$this->storage->objects["k/inuse.png"] = "live";
		$this->storage->failOn["delete"] = ["k/bbb.png"];

		$summary = StorageGarbage::Retry($this->storage);
		$this->assertSame(["deleted" => 1, "failed" => 1, "dropped" => 1], $summary);
		$this->assertArrayHasKey("k/inuse.png", $this->storage->objects, "a path a row points at again is never deleted");
		$this->assertArrayNotHasKey("k/aaa.png", $this->storage->objects);

		$forgotten = array_map(fn($s) => $s["values"][0], $this->db->Statements('/^DELETE FROM `storage_garbage`/'));
		$this->assertSame([1, 3], $forgotten, "the deleted row and the in-use one leave the queue");
		$bumped = $this->db->Statements('/^UPDATE `storage_garbage` SET `attempts` = `attempts` \+ 1/');
		$this->assertCount(1, $bumped);
		$this->assertSame(2, $bumped[0]["values"][1]);
	}

	public function testTokenOf(): void {
		$this->assertSame("Xk3pQ9vT2mLr8sWn4bYc7", StorageGarbage::TokenOf("0196b1d3-uuid/Xk3pQ9vT2mLr8sWn4bYc7.webp"));
		$this->assertSame("tok", StorageGarbage::TokenOf("tok"));
		$this->assertSame("tok", StorageGarbage::TokenOf("k/tok.tar.gz"));
	}

}
