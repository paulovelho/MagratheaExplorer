<?php

namespace MagratheaExplorer\Tests\Folder;

use Magrathea\Exceptions\MagratheaApiException;
use MagratheaExplorer\File\File;
use MagratheaExplorer\File\FileControl;
use MagratheaExplorer\Folder\Folder;
use MagratheaExplorer\Folder\FolderDeletion;
use MagratheaExplorer\Key\Key;
use MagratheaExplorer\Storage\StorageFactory;
use MagratheaExplorer\Tests\Support\FakeDatabase;
use MagratheaExplorer\Tests\Support\FakeStorage;
use MagratheaExplorer\Tests\Support\TestConfig;
use PHPUnit\Framework\TestCase;

/** DELETE /folder/:uuid?recursive=1 (Tier 3 §6): mark, delete small subtrees now, sweep the rest. */
class FolderDeletionTest extends TestCase {

	private FakeDatabase $db;
	private FakeStorage $storage;

	protected function setUp(): void {
		$this->db = FakeDatabase::Install();
		$this->storage = new FakeStorage();
		StorageFactory::Instance()->SetAdapterForTests($this->storage);
	}

	protected function tearDown(): void {
		FakeDatabase::Uninstall();
		StorageFactory::Instance()->SetAdapterForTests(null);
		TestConfig::Set();
	}

	private static function fileRow(int $id, int $folderId, int $size = 100): array {
		return ["id" => $id, "uuid" => "u".$id, "token" => "t".$id, "key_id" => 7, "folder_id" => $folderId, "name" => "f".$id,
			"storage_path" => "sto/t".$id.".bin", "extension" => "bin", "mime_type" => "application/octet-stream",
			"file_type" => "other", "size" => $size, "processing_status" => "none", "no_convert" => 0, "verbatim" => 0];
	}

	private function key(): Key {
		$key = new Key();
		$key->id = 7;
		$key->total_size = 1000;
		return $key;
	}

	private function folder(int $id = 10, ?int $parentId = 1): Folder {
		$folder = new Folder();
		$folder->LoadObjectFromTableRow(["id" => $id, "uuid" => "folder-uuid-".$id, "key_id" => 7, "parent_id" => $parentId, "name" => "invoices"]);
		return $folder;
	}

	/** A tree 10 > 11 > 12 with two files in 12 and one in 10. */
	private function tree(): void {
		$this->db
			->On('/^WITH RECURSIVE subtree/', fn() => [["id" => 12, "depth" => 2], ["id" => 11, "depth" => 1], ["id" => 10, "depth" => 0]])
			->On('/^SELECT COUNT\(\*\) FROM files WHERE folder_id IN \(12,11,10\)/', fn() => 3)
			->On('/^SELECT \* FROM files WHERE `folder_id` IN \(12,11,10\)/', fn() => [self::fileRow(1, 12), self::fileRow(2, 12), self::fileRow(3, 10)]);
		foreach([1, 2, 3] as $n) $this->storage->objects["sto/t".$n.".bin"] = "x";
	}

	private function deletedFolders(): array {
		return array_map(fn($s) => $s["values"][0], $this->db->Statements('/^DELETE FROM `folders`/'));
	}

	public function testTheRootIsRefused(): void {
		try {
			FolderDeletion::Delete($this->key(), $this->folder(1, null));
			$this->fail("expected 4005");
		} catch(MagratheaApiException $ex) {
			$this->assertSame(4005, $ex->getCode());
		}
		$this->assertSame([], $this->db->Statements('/^UPDATE `folders`/'), "nothing marked");
	}

	public function testSubtree(): void {
		$this->tree();
		$this->assertSame(["ids" => [12, 11, 10], "files" => 3], FolderDeletion::Subtree(10));
	}

	public function testASmallSubtreeIsDeletedNow(): void {
		$this->tree();
		$this->assertTrue(FolderDeletion::Delete($this->key(), $this->folder()));

		$mark = $this->db->Statements('/^UPDATE `folders` SET `deleting_at` = NOW\(\)/');
		$this->assertCount(1, $mark);
		$this->assertStringContainsString("IN (12,11,10)", $mark[0]["sql"], "the whole subtree, in one statement");
		$rename = $this->db->Statements('/^UPDATE `folders` SET `name` = \?/');
		$this->assertSame([".deleting-folder-uuid-10", 10], $rename[0]["values"], "the name is free in its parent at once");

		$this->assertSame([], $this->storage->objects, "every file's object deleted");
		$this->assertCount(3, $this->db->Statements('/^UPDATE `access_keys` SET `total_size`/'), "total_size lowered per file");
		$this->assertSame([12, 11, 10], $this->deletedFolders(), "deepest first");
		$this->assertCount(3, $this->db->Statements('/^DELETE FROM `shares` WHERE `folder_id`/'), "each folder's shares first");
	}

	public function testABigSubtreeIsLeftToTheSweep(): void {
		TestConfig::Set(["folder_delete_inline_files" => "2"]);
		$this->tree();
		$this->assertFalse(FolderDeletion::Delete($this->key(), $this->folder()));
		$this->assertCount(1, $this->db->Statements('/SET `deleting_at` = NOW\(\)/'), "marked: gone for the API");
		$this->assertCount(3, $this->storage->objects, "nothing deleted in the request");
		$this->assertSame([], $this->deletedFolders());
	}

	public function testAStorageFailureLeavesItToTheSweep(): void {
		$this->tree();
		$this->storage->failOn["delete"] = ["sto/t2.bin"];
		$this->assertFalse(FolderDeletion::Delete($this->key(), $this->folder()));
		$this->assertSame(["sto/t2.bin"], array_keys($this->storage->objects), "the others are gone");
		$this->assertSame([], $this->deletedFolders(), "its folders stay until the file is gone");
	}

	public function testSweep(): void {
		$propagations = 0;
		$fileBatches = 0;
		$leafRounds = 0;
		$this->db
			->On('/^SELECT COUNT\(\*\) FROM `folders` WHERE `deleting_at` IS NOT NULL/', function() use (&$leafRounds) { return $leafRounds >= 2 ? 0 : 3; })
			->On('/^UPDATE `folders` c INNER JOIN `folders` p/', function() use (&$propagations) { return ++$propagations === 1 ? 1 : 0; })
			->On('/^SELECT \* FROM `files` WHERE `id` > 0 AND `folder_id` IN \(SELECT/', function() use (&$fileBatches) {
				$fileBatches++;
				return [self::fileRow(5, 20), self::fileRow(6, 21)];
			})
			->On('/^SELECT \* FROM `files` WHERE `id` > 6/', fn() => [])
			->On('/^SELECT f\.`id` FROM `folders` f WHERE f\.`deleting_at` IS NOT NULL/', function() use (&$leafRounds) {
				$leafRounds++;
				return match($leafRounds) { 1 => [["id" => 21], ["id" => 22]], 2 => [["id" => 20]], default => [] };
			});
		$this->db->On('/FROM `access_keys` WHERE access_keys\.id = 7/', fn() => ["access_keys/id" => 7, "access_keys/total_size" => 1000]);
		$this->storage->objects = ["sto/t5.bin" => "x", "sto/t6.bin" => "x"];
		$this->storage->failOn["delete"] = ["sto/t6.bin"];

		$summary = FolderDeletion::Sweep(30);
		$this->assertSame(2, $propagations, "a child created just before the mark is marked, until nothing changes");
		$this->assertSame(["files" => 1, "failed" => 1, "folders" => 3, "remaining" => 0], $summary);
		$this->assertSame([21, 22, 20], $this->deletedFolders(), "leaves first, then their parents");
	}

	public function testANothingToDoSweepRunsOneQuery(): void {
		$this->assertSame(["files" => 0, "failed" => 0, "folders" => 0, "remaining" => 0], FolderDeletion::Sweep(30));
		$this->assertCount(1, $this->db->statements);
	}

	public function testARacingDeleteLowersTotalSizeOnce(): void {
		$this->db->On('/^DELETE FROM files WHERE id= \?/', fn() => 0); // another delete got the row first
		$file = new File();
		$file->LoadObjectFromTableRow(self::fileRow(9, 10, 500));
		FileControl::Delete($file, $this->key());
		$this->assertSame([], $this->db->Statements('/^UPDATE `access_keys` SET `total_size`/'));
	}

}
