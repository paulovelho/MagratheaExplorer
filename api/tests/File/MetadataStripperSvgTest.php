<?php

namespace MagratheaExplorer\Tests\File;

use MagratheaExplorer\File\MetadataStripper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The stripper itself must never expand an entity, even on input UploadValidator would have
 * refused: the two halves of the 1.5.0 XXE fix are tested separately. Each case points at a
 * secret file written at run time, and the stripped output must not contain it.
 */
class MetadataStripperSvgTest extends TestCase {

	private const SECRET = "TOP-SECRET-db_pass-value";

	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir()."/explorer_xxe_".bin2hex(random_bytes(6));
		mkdir($this->dir);
		file_put_contents($this->dir."/secret.txt", self::SECRET);
		file_put_contents($this->dir."/evil.dtd", '<!ENTITY leak "'.self::SECRET.'">');
	}

	protected function tearDown(): void {
		foreach(glob($this->dir."/*") as $f) unlink($f);
		rmdir($this->dir);
	}

	public static function attacks(): array {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg"><text>&leak;</text></svg>';
		return [
			"SYSTEM entity" => ['<!DOCTYPE svg [<!ENTITY leak SYSTEM "file://{dir}/secret.txt">]>'.$svg, false],
			"parameter entity" => ['<!DOCTYPE svg [<!ENTITY % p SYSTEM "file://{dir}/evil.dtd"> %p;]>'.$svg, false],
			"external DTD" => ['<!DOCTYPE svg SYSTEM "file://{dir}/evil.dtd">'.$svg, false],
			"SYSTEM entity, UTF-16" => ['<!DOCTYPE svg [<!ENTITY leak SYSTEM "file://{dir}/secret.txt">]>'.$svg, true],
		];
	}

	#[DataProvider("attacks")]
	public function testNeverExpandsEntities(string $body, bool $utf16): void {
		$xml = str_replace("{dir}", $this->dir, $body);
		if($utf16) {
			$xml = "\xFF\xFE".mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?>'.$xml, "UTF-16LE", "UTF-8");
		} else {
			$xml = '<?xml version="1.0" encoding="UTF-8"?>'.$xml;
		}
		$path = $this->dir."/upload.svg";
		file_put_contents($path, $xml);

		MetadataStripper::StripSvg($path); // true or false: either way, the file must not leak
		$stored = file_get_contents($path);
		if($utf16 && str_starts_with($stored, "\xFF\xFE")) {
			$stored = mb_convert_encoding($stored, "UTF-8", "UTF-16LE");
		}
		$this->assertStringNotContainsString(self::SECRET, $stored);
	}

	public function testStripsIllustratorSvgAndKeepsEntityReferences(): void {
		$path = $this->dir."/upload.svg";
		copy(__DIR__."/../fixtures/svg/illustrator.svg", $path);

		$this->assertTrue(MetadataStripper::StripSvg($path));
		$stored = file_get_contents($path);
		$this->assertStringNotContainsString("<metadata", $stored);
		$this->assertStringNotContainsString("Generator: Adobe Illustrator", $stored);
		$this->assertStringContainsString('xmlns="&ns_svg;"', $stored);
		$this->assertStringContainsString('style="&st0;"', $stored);
	}

}
