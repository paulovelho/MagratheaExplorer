<?php

namespace MagratheaExplorer\Tests\File;

use MagratheaExplorer\File\UploadOptions;
use MagratheaExplorer\Key\Key;
use MagratheaExplorer\Tests\Support\TestConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * verbatim = request ?? key.verbatim_default ?? magrathea.conf verbatim_default ?? false;
 * noConvert = verbatim || (request no_convert ?? false).
 */
class UploadOptionsTest extends TestCase {

	protected function tearDown(): void {
		TestConfig::Set();
	}

	public static function resolutions(): array {
		// [conf verbatim_default (null = absent), key verbatim_default, request verbatim, expected verbatim]
		return [
			"nothing set: compressed" => [null, null, null, false],
			"conf true, key null" => ["true", null, null, true],
			"conf false, key null" => ["false", null, null, false],
			"key 1 beats conf" => ["false", true, null, true],
			"key 0 beats conf" => ["true", false, null, false],
			"request 1 beats key 0" => [null, false, true, true],
			"request 0 beats key 1" => [null, true, false, false],
			"request 0 beats conf true" => ["true", null, false, false],
		];
	}

	#[DataProvider("resolutions")]
	public function testVerbatim(?string $conf, ?bool $keyDefault, ?bool $request, bool $expected): void {
		TestConfig::Set($conf === null ? [] : ["verbatim_default" => $conf]);
		$options = UploadOptions::Resolve($this->Key($keyDefault), $request, null);
		$this->assertSame($expected, $options->verbatim);
	}

	public function testVerbatimImpliesNoConvert(): void {
		$this->assertTrue(UploadOptions::Resolve($this->Key(null), true, false)->noConvert);
		$this->assertTrue((new UploadOptions(verbatim: true, noConvert: false))->noConvert);
	}

	public function testNoConvertAlone(): void {
		$options = UploadOptions::Resolve($this->Key(null), null, true);
		$this->assertFalse($options->verbatim);
		$this->assertTrue($options->noConvert);
		$this->assertFalse(UploadOptions::Resolve($this->Key(null), null, null)->noConvert);
	}

	public function testCarriesTagsAndQuota(): void {
		$options = UploadOptions::Resolve($this->Key(null), null, null, ["a", "b"], false);
		$this->assertSame(["a", "b"], $options->tagNames);
		$this->assertFalse($options->enforceQuota);
		$this->assertTrue(UploadOptions::Resolve($this->Key(null), null, null)->enforceQuota);
	}

	public function testCarriesTheUploadHashAndTheInsertCallback(): void {
		$options = UploadOptions::Resolve($this->Key(null), null, null);
		$this->assertNull($options->uploadSha256);
		$this->assertNull($options->onStored);
		$onStored = function($file) {};
		$options = UploadOptions::Resolve($this->Key(null), null, null, ["a"], uploadSha256: str_repeat("a", 64), onStored: $onStored);
		$this->assertSame(str_repeat("a", 64), $options->uploadSha256);
		$this->assertSame($onStored, $options->onStored);
		$this->assertSame(["a"], $options->tagNames);
		$this->assertTrue($options->enforceQuota); // the named arguments skip it: its default
	}

	/** Only a source_url fetch hands the pipeline a file it may take over; uploads and imports are copied first. */
	public function testConsumeSourceIsOptIn(): void {
		$this->assertFalse(UploadOptions::Resolve($this->Key(null), null, null)->consumeSource);
		$this->assertFalse((new UploadOptions())->consumeSource);
		$this->assertTrue(UploadOptions::Resolve($this->Key(null), null, null, consumeSource: true)->consumeSource);
	}

	private function Key(?bool $verbatimDefault): Key {
		$key = new Key();
		$key->verbatim_default = $verbatimDefault;
		return $key;
	}

}
