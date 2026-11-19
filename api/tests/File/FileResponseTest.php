<?php

namespace MagratheaExplorer\Tests\File;

use MagratheaExplorer\File\File;
use MagratheaExplorer\File\FileResponse;
use PHPUnit\Framework\TestCase;

/** The parts of the File response that need no database or storage: properties and status (plan §7.7). */
class FileResponseTest extends TestCase {

	public function testAFileNeverProcessedGetsWhatIsKnown(): void {
		$file = new File();
		$file->width = 4500;
		$file->height = 5400;
		$this->assertSame(["width" => 4500, "height" => 5400], FileResponse::Properties($file));

		$audio = new File();
		$audio->duration = 183;
		$this->assertSame(["duration" => 183], FileResponse::Properties($audio));

		$this->assertSame([], FileResponse::Properties(new File()), "a document: an empty object, never null");
	}

	public function testStoredPropertiesGoOverTheTopLevelOnes(): void {
		$file = new File();
		$file->width = 100;
		$file->height = 50;
		$file->properties = json_encode([
			"width" => 101, "height" => null, "orientation" => null, "dpi_x" => 300, "is_opaque" => null,
		]);
		$this->assertSame(
			["width" => 101, "height" => 50, "orientation" => null, "dpi_x" => 300, "is_opaque" => null],
			FileResponse::Properties($file),
			"a stored value wins, a stored null doesn't hide a known value, and other nulls stay: computed, not known"
		);
	}

	public function testUnreadableStoredPropertiesAreIgnored(): void {
		$file = new File();
		$file->width = 10;
		$file->properties = "{not json";
		$this->assertNull($file->StoredProperties());
		$this->assertSame(["width" => 10], FileResponse::Properties($file));
		$file->properties = "\"a string\"";
		$this->assertNull($file->StoredProperties());
	}

	public function testStatus(): void {
		$file = new File();
		$this->assertSame("none", FileResponse::Status($file), "unset = none");
		foreach(["none", "pending", "ready", "failed"] as $status) {
			$file->processing_status = $status;
			$this->assertSame($status, FileResponse::Status($file));
		}
		$file->processing_status = "bogus";
		$this->assertSame("none", FileResponse::Status($file));
	}

	public function testEmptyPropertiesEncodeAsAnObject(): void {
		$this->assertSame("{}", json_encode((object)FileResponse::Properties(new File())));
	}

}
