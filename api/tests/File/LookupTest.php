<?php

namespace MagratheaExplorer\Tests\File;

use MagratheaExplorer\File\FileApi;
use MagratheaExplorer\Key\Key;
use MagratheaExplorer\Storage\StorageFactory;
use MagratheaExplorer\Tests\Support\FakeDatabase;
use MagratheaExplorer\Tests\Support\FakeStorage;
use PHPUnit\Framework\TestCase;

/** POST /files/lookup against a fake database: the key's scope, the order, and 4 queries in all. */
class LookupTest extends TestCase {

	private FakeDatabase $db;

	protected function setUp(): void {
		$this->db = FakeDatabase::Install();
		StorageFactory::Instance()->SetAdapterForTests(new FakeStorage());
	}

	protected function tearDown(): void {
		FakeDatabase::Uninstall();
		StorageFactory::Instance()->SetAdapterForTests(null);
	}

	private static function uuid(int $n): string {
		return sprintf("0196b1d3-5c2e-7a10-8f3b-%012x", $n);
	}

	private static function fileRow(int $id, int $folderId): array {
		return [
			"id" => $id, "uuid" => self::uuid($id), "token" => "tok".$id, "key_id" => 7, "folder_id" => $folderId,
			"name" => "f".$id.".png", "storage_path" => "store/tok".$id.".png", "extension" => "png",
			"mime_type" => "image/png", "file_type" => "image", "size" => 10 * $id, "sha256" => null,
			"width" => null, "height" => null, "duration" => null, "properties" => null,
			"processing_status" => "ready", "processing_error" => null, "no_convert" => 0, "verbatim" => 0,
			"created_at" => "2026-11-18 10:00:00", "updated_at" => "2026-11-18 10:00:00",
		];
	}

	public function testOwnFilesInRequestOrderWithFourQueries(): void {
		$rows = [];
		foreach(range(1, 100) as $n) $rows[] = self::fileRow($n, $n % 2 === 0 ? 20 : 21);
		$this->db
			->On('/^SELECT \* FROM files WHERE `key_id` = 7 AND `uuid` IN/', fn() => $rows)
			->On('/FROM `file_renditions`/', fn() => [[
				"id" => 1, "file_id" => 3, "name" => "thumb", "token" => "r3", "storage_path" => "store/r3.webp",
				"extension" => "webp", "mime_type" => "image/webp", "width" => 200, "height" => 200, "size" => 99,
			]])
			->On('/FROM `tags` t INNER JOIN `file_tags`/', fn() => [["file_id" => 2, "name" => "b"], ["file_id" => 2, "name" => "a"]])
			->On('/FROM `folders` WHERE `id` IN/', fn() => [["id" => 20, "uuid" => "folder-20"], ["id" => 21, "uuid" => "folder-21"]]);

		$key = new Key();
		$key->id = 7;
		// 100 known ones, reversed, plus another key's (not in the rows) and a malformed one
		$asked = array_merge(array_map([self::class, "uuid"], range(100, 1)), [self::uuid(999), "nope"]);
		$files = FileApi::LookupFor($key, ["uuids" => array_slice($asked, 0, 100)]);

		$this->assertCount(100, $files);
		$this->assertSame(self::uuid(100), $files[0]["uuid"], "the request's order, not the database's");
		$this->assertSame(self::uuid(1), $files[99]["uuid"]);
		$this->assertCount(4, $this->db->statements, "files, renditions, tags, folders: no query per file");

		$byUuid = array_column($files, null, "uuid");
		$this->assertSame(["b", "a"], $byUuid[self::uuid(2)]["tags"]);
		$this->assertSame([], $byUuid[self::uuid(4)]["tags"]);
		$this->assertSame("folder-20", $byUuid[self::uuid(2)]["folder_uuid"]);
		$this->assertSame("folder-21", $byUuid[self::uuid(3)]["folder_uuid"]);
		$this->assertSame("https://files.test/store/r3.webp", $byUuid[self::uuid(3)]["thumbnail_url"]);
	}

	public function testUnknownAndMalformedAreLeftOut(): void {
		$this->db->On('/^SELECT \* FROM files WHERE/', fn() => [self::fileRow(5, 20)])
			->On('/FROM `folders` WHERE `id` IN/', fn() => [["id" => 20, "uuid" => "folder-20"]]);
		$key = new Key();
		$key->id = 7;
		$files = FileApi::LookupFor($key, ["uuids" => [self::uuid(999), "nope", self::uuid(5)]]);
		$this->assertSame([self::uuid(5)], array_column($files, "uuid"));
		$sql = $this->db->statements[0]["sql"];
		$this->assertStringContainsString("`key_id` = 7", $sql, "scoped to the key: another key's uuid never matches");
		$this->assertStringNotContainsString("nope", $sql, "malformed uuids never reach the query");
	}

	public function testOnlyMalformedRunsNoQuery(): void {
		$key = new Key();
		$key->id = 7;
		$this->assertSame([], FileApi::LookupFor($key, ["uuids" => ["nope", "' OR 1=1 --"]]));
		$this->assertSame([], $this->db->statements);
	}

}
