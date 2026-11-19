<?php

namespace MagratheaExplorer\Tests\File;

use Magrathea\Exceptions\MagratheaApiException;
use MagratheaExplorer\File\File;
use MagratheaExplorer\File\FileRotation;
use MagratheaExplorer\Tests\Support\FakeDatabase;
use MagratheaExplorer\Tests\Support\FakeStorage;
use PHPUnit\Framework\TestCase;

/** POST /file/:uuid/rotate (Tier 3 §3): copy, switch under the lock, delete the old objects. */
class FileRotationTest extends TestCase {

	private FakeDatabase $db;
	private FakeStorage $storage;
	/** what the file_renditions query answers, per call (the last one repeats) */
	private array $renditionAnswers;

	protected function setUp(): void {
		$this->db = FakeDatabase::Install();
		$this->storage = new FakeStorage();
		$this->storage->objects = [
			"sto/OLDfileTOKEN.png" => "original bytes",
			"sto/OLDthumbTOKEN.webp" => "thumb bytes",
			"sto/OLDprevTOKEN.jpg" => "preview bytes",
		];
		$renditions = [self::rendition(11, "thumb", "OLDthumbTOKEN", "webp"), self::rendition(12, "preview", "OLDprevTOKEN", "jpg")];
		$this->renditionAnswers = [$renditions];
		$calls = 0;
		$this->db
			->On('/FROM `file_renditions` WHERE `file_id` = 5/', function() use (&$calls) {
				$answer = $this->renditionAnswers[min($calls, count($this->renditionAnswers) - 1)];
				$calls++;
				return $answer;
			})
			->On('/FROM `files` WHERE `id` = 5 FOR UPDATE/', fn() => ["id" => 5, "token" => "OLDfileTOKEN", "storage_path" => "sto/OLDfileTOKEN.png"])
			->On('/FROM `files` WHERE files\.id = 5/', fn() => self::prefixed(self::fileRow()));
	}

	protected function tearDown(): void {
		FakeDatabase::Uninstall();
	}

	private static function rendition(int $id, string $name, string $token, string $ext): array {
		return ["id" => $id, "file_id" => 5, "name" => $name, "token" => $token, "storage_path" => "sto/".$token.".".$ext,
			"extension" => $ext, "mime_type" => "image/".$ext, "width" => 10, "height" => 10, "size" => 1];
	}

	private static function fileRow(): array {
		return ["id" => 5, "uuid" => "0196b1d3-5c2e-7a10-8f3b-000000000005", "token" => "OLDfileTOKEN", "key_id" => 7, "folder_id" => 1,
			"name" => "a.png", "storage_path" => "sto/OLDfileTOKEN.png", "extension" => "png", "mime_type" => "image/png",
			"file_type" => "image", "size" => 14, "processing_status" => "ready", "no_convert" => 0, "verbatim" => 0];
	}

	/** Reload()'s query reads `files/<column>` aliases. */
	private static function prefixed(array $row): array {
		$out = [];
		foreach($row as $k => $v) $out["files/".$k] = $v;
		return $out;
	}

	private function file(): File {
		$file = new File();
		$file->LoadObjectFromTableRow(self::fileRow());
		return $file;
	}

	private function updates(): array {
		return $this->db->Statements('/^UPDATE `(files|file_renditions)` SET `token`/');
	}

	public function testReplaceToken(): void {
		$this->assertSame("k/NEW.png", FileRotation::ReplaceToken("k/OLD.png", "NEW"));
		$this->assertSame("k/NEW.tar.gz", FileRotation::ReplaceToken("k/OLD.tar.gz", "NEW"), "tokens have no dot: everything after the first one stays");
		$this->assertSame("NEW.png", FileRotation::ReplaceToken("OLD.png", "NEW"));
		$this->assertSame("a/b/NEW", FileRotation::ReplaceToken("a/b/OLD", "NEW"));
	}

	public function testRotationMovesEveryObject(): void {
		FileRotation::Rotate($this->file(), $this->storage);

		$this->assertCount(3, $this->storage->objects, "three new objects, the old ones gone");
		foreach(array_keys($this->storage->objects) as $path) {
			$this->assertStringStartsWith("sto/", $path);
			$this->assertStringNotContainsString("OLD", $path);
		}
		$bytes = array_values($this->storage->objects);
		sort($bytes);
		$this->assertSame(["original bytes", "preview bytes", "thumb bytes"], $bytes, "same bytes under the new paths");

		$updates = $this->updates();
		$this->assertCount(3, $updates, "the file and both renditions");
		$this->assertSame(5, $updates[0]["values"][2]);
		$this->assertMatchesRegularExpression('#^sto/[0-9A-Za-z]{21}\.png$#', $updates[0]["values"][1]);
		$this->assertSame([11, 12], [$updates[1]["values"][2], $updates[2]["values"][2]]);
		$this->assertSame([], $this->db->Statements('/storage_garbage/'));
	}

	public function testAJobCommitInBetweenIs4093AndChangesNothing(): void {
		// step 1 reads the renditions above; under the lock a job has replaced the preview
		$this->renditionAnswers[] = [self::rendition(11, "thumb", "OLDthumbTOKEN", "webp"), self::rendition(13, "preview", "JOBprevTOKEN", "jpg")];
		$this->assertCode(4093, fn() => FileRotation::Rotate($this->file(), $this->storage));
		$this->assertSame([], $this->updates());
		$this->assertSame(["sto/OLDfileTOKEN.png", "sto/OLDthumbTOKEN.webp", "sto/OLDprevTOKEN.jpg"], array_keys($this->storage->objects),
			"the copies are deleted, the originals untouched");
	}

	public function testACopyFailureChangesNothing(): void {
		$this->storage->failOn["copy"] = ["sto/OLDprevTOKEN.jpg"];
		$this->assertCode(5001, fn() => FileRotation::Rotate($this->file(), $this->storage));
		$this->assertSame([], $this->updates());
		$this->assertSame(["sto/OLDfileTOKEN.png", "sto/OLDthumbTOKEN.webp", "sto/OLDprevTOKEN.jpg"], array_keys($this->storage->objects));
	}

	public function testAFailedOldDeleteIsQueued(): void {
		$this->storage->failOn["delete"] = ["sto/OLDthumbTOKEN.webp"];
		FileRotation::Rotate($this->file(), $this->storage);
		$queued = $this->db->Statements('/^INSERT INTO `storage_garbage`/');
		$this->assertCount(1, $queued);
		$this->assertSame("sto/OLDthumbTOKEN.webp", $queued[0]["values"][0]);
		$this->assertCount(3, $this->updates(), "the rotation still succeeded");
	}

	public function testAFileDeletedInBetweenIs4043(): void {
		$this->db = FakeDatabase::Install(); // no FOR UPDATE row this time
		$this->db->On('/FROM `file_renditions`/', fn() => []);
		$this->assertCode(4043, fn() => FileRotation::Rotate($this->file(), $this->storage));
		$this->assertSame(["sto/OLDfileTOKEN.png", "sto/OLDthumbTOKEN.webp", "sto/OLDprevTOKEN.jpg"], array_keys($this->storage->objects));
	}

	private function assertCode(int $code, callable $fn): void {
		try {
			$fn();
			$this->fail("expected ".$code);
		} catch(MagratheaApiException $ex) {
			$this->assertSame($code, $ex->getCode());
		}
	}

}
