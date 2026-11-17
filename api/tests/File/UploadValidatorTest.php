<?php

namespace MagratheaExplorer\Tests\File;

use Magrathea\Exceptions\MagratheaApiException;
use MagratheaExplorer\File\UploadValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The SVG refusal (4151) from the 1.5.0 XXE fix. Runs before any processing, on every path. */
class UploadValidatorTest extends TestCase {

	private const FIXTURES = __DIR__."/../fixtures/svg/";
	private const SVG = ["file_type" => "image", "mime_type" => "image/svg+xml", "extension" => "svg"];

	private array $tempFiles = [];

	protected function tearDown(): void {
		foreach($this->tempFiles as $f) @unlink($f);
	}

	public static function acceptedSvgs(): array {
		return [
			"no DOCTYPE" => ["plain.svg"],
			"PUBLIC DTD, no internal subset" => ["public-dtd.svg"],
			"Illustrator plain entities" => ["illustrator.svg"],
		];
	}

	public static function refusedSvgs(): array {
		return [
			"SYSTEM entity (file disclosure)" => ["xxe.svg"],
			"parameter entity" => ["xxe-parameter.svg"],
			"nested entities (billion laughs)" => ["entity-nested.svg"],
			"character references (markup)" => ["entity-markup.svg"],
			"ATTLIST" => ["attlist.svg"],
			"not well-formed" => ["malformed.svg"],
		];
	}

	#[DataProvider("acceptedSvgs")]
	public function testAccepts(string $fixture): void {
		UploadValidator::Validate(self::FIXTURES.$fixture, self::SVG);
		$this->addToAssertionCount(1);
	}

	#[DataProvider("refusedSvgs")]
	public function testRefuses(string $fixture): void {
		$this->assertRefused(self::FIXTURES.$fixture);
	}

	/** libxml decodes UTF-16, so a byte scan for "<!ENTITY" would miss this one. */
	public function testRefusesUtf16Xxe(): void {
		$this->assertRefused($this->Utf16Copy("xxe.svg"));
	}

	public function testAcceptsUtf16PlainEntities(): void {
		UploadValidator::Validate($this->Utf16Copy("illustrator.svg"), self::SVG);
		$this->addToAssertionCount(1);
	}

	/** A 2 MB comment before the DOCTYPE doesn't push it out of reach. */
	public function testRefusesXxeAfterLongComment(): void {
		$xml = file_get_contents(self::FIXTURES."xxe.svg");
		$xml = preg_replace('/\?>/', "?>\n<!--".str_repeat("x", 2 * 1024 * 1024)."-->", $xml, 1);
		$this->assertRefused($this->TempFile($xml));
	}

	public function testRefusesOversizedEntityValue(): void {
		$value = str_repeat("x", UploadValidator::SVG_MAX_ENTITY_VALUE + 1);
		$this->assertRefused($this->TempFile(
			'<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY big "'.$value.'">]><svg xmlns="http://www.w3.org/2000/svg"/>'
		));
	}

	public function testIgnoresOtherTypes(): void {
		// a malformed "SVG" that wasn't sniffed as one is none of this check's business
		UploadValidator::Validate(self::FIXTURES."malformed.svg", ["file_type" => "other", "mime_type" => "text/xml", "extension" => "xml"]);
		$this->addToAssertionCount(1);
	}

	private function assertRefused(string $path): void {
		try {
			UploadValidator::Validate($path, self::SVG);
			$this->fail("expected 4151 for ".basename($path));
		} catch(MagratheaApiException $ex) {
			$this->assertSame(4151, $ex->getCode());
			$this->assertStringContainsString("SVG", $ex->getMessage());
		}
	}

	private function Utf16Copy(string $fixture): string {
		$xml = file_get_contents(self::FIXTURES.$fixture);
		$xml = str_ireplace('encoding="utf-8"', 'encoding="UTF-16"', $xml);
		return $this->TempFile("\xFF\xFE".mb_convert_encoding($xml, "UTF-16LE", "UTF-8"));
	}

	private function TempFile(string $content): string {
		$path = tempnam(sys_get_temp_dir(), "explorer_test_");
		file_put_contents($path, $content);
		$this->tempFiles[] = $path;
		return $path;
	}

}
