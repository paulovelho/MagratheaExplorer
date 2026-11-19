<?php

namespace MagratheaExplorer\Tests\Key;

use Magrathea\ConfigApp;
use MagratheaExplorer\Key\Key;
use MagratheaExplorer\Key\KeyControl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * effective = min(key.max_upload_size ?? ConfigApp max_upload_size ?? 5 MB, post_max_size, upload_max_filesize).
 * PHP's limits are PERDIR (no ini_set()): the tests read whatever this PHP runs with.
 */
class UploadLimitTest extends TestCase {

	const MB = 1048576;

	protected function setUp(): void {
		ConfigApp::Instance()->Mock([]);
	}

	protected function tearDown(): void {
		ConfigApp::Instance()->UnMock();
	}

	public static function iniSizes(): array {
		return [
			"M" => ["64M", 64 * self::MB], "lowercase g" => ["1g", 1024 * self::MB], "K" => ["512K", 512 * 1024],
			"bytes" => ["1000", 1000], "padded" => [" 8M ", 8 * self::MB],
			"0 = no limit" => ["0", PHP_INT_MAX], "negative = no limit" => ["-1", PHP_INT_MAX],
			"empty" => ["", PHP_INT_MAX], "not set" => [false, PHP_INT_MAX],
		];
	}

	#[DataProvider("iniSizes")]
	public function testIniSizeToBytes(string|false $ini, int $expected): void {
		$this->assertSame($expected, Key::IniSizeToBytes($ini));
	}

	public function testPhpLimitIsTheSmallerOfTheTwo(): void {
		$expected = min(Key::IniSizeToBytes(ini_get("post_max_size")), Key::IniSizeToBytes(ini_get("upload_max_filesize")));
		$this->assertSame($expected, Key::PhpMaxUploadSize());
	}

	public static function instanceValues(): array {
		return [
			"absent" => [null, 5 * self::MB],
			"bytes" => ["10485760", 10 * self::MB],
			"M" => ["150M", 150 * self::MB],
			"0 (as before 1.5.0)" => ["0", 5 * self::MB],
			"unreadable" => ["lots", 5 * self::MB],
		];
	}

	#[DataProvider("instanceValues")]
	public function testInstanceDefault(?string $configured, int $expected): void {
		ConfigApp::Instance()->Mock($configured === null ? [] : ["max_upload_size" => $configured]);
		$this->assertSame($expected, Key::InstanceMaxUploadSize());
	}

	public function testKeyLimitBeatsInstance(): void {
		ConfigApp::Instance()->Mock(["max_upload_size" => "5M"]);
		$this->assertSame(150 * self::MB, $this->Key(150 * self::MB)->ConfiguredMaxUploadSize());
		$this->assertSame(1 * self::MB, $this->Key(1 * self::MB)->ConfiguredMaxUploadSize());
		$this->assertSame(5 * self::MB, $this->Key(null)->ConfiguredMaxUploadSize());
	}

	public function testEffectiveIsCappedByPhp(): void {
		$php = Key::PhpMaxUploadSize();
		$this->assertSame(1, $this->Key(1)->EffectiveMaxUploadSize());
		$this->assertSame($php, $this->Key(PHP_INT_MAX)->EffectiveMaxUploadSize());
		ConfigApp::Instance()->Mock(["max_upload_size" => "1G"]);
		$this->assertSame(min(1024 * self::MB, $php), $this->Key(null)->EffectiveMaxUploadSize());
	}

	public static function validMb(): array {
		return [
			"empty = instance default" => ["", null], "spaces only" => ["  ", null],
			"whole" => ["150", 150 * self::MB], "padded" => [" 5 ", 5 * self::MB],
			"decimal" => ["0.5", self::MB / 2], "ceiling" => [(string)KeyControl::MAX_UPLOAD_MB_CEILING, KeyControl::MAX_UPLOAD_MB_CEILING * self::MB],
		];
	}

	#[DataProvider("validMb")]
	public function testParseMaxUploadMb(string $raw, ?int $expected): void {
		$this->assertSame($expected, KeyControl::ParseMaxUploadMb($raw));
	}

	public static function invalidMb(): array {
		return [
			"zero" => ["0"], "rounds to zero" => ["0.0000001"], "negative" => ["-1"], "text" => ["lots"],
			"exponent" => ["1e3"], "comma" => ["1,5"], "unit" => ["150M"],
			"above the ceiling" => [(string)(KeyControl::MAX_UPLOAD_MB_CEILING + 1)],
		];
	}

	#[DataProvider("invalidMb")]
	public function testParseMaxUploadMbRefuses(string $raw): void {
		$this->expectException(\InvalidArgumentException::class);
		KeyControl::ParseMaxUploadMb($raw);
	}

	public static function storedBytes(): array {
		return [
			"whole MB" => [150 * self::MB], "one byte" => [1], "decimal SI" => [5000000],
			"just over a MB" => [self::MB + 1], "just under the ceiling" => [KeyControl::MAX_UPLOAD_MB_CEILING * self::MB - 1],
		];
	}

	/** Whatever the column holds (an SQL UPDATE included), saving the form unchanged keeps it. */
	#[DataProvider("storedBytes")]
	public function testFormValueRoundTrips(int $bytes): void {
		$this->assertSame($bytes, KeyControl::ParseMaxUploadMb(KeyControl::MaxUploadMbForForm($bytes)));
	}

	public function testFormValues(): void {
		$this->assertSame("", KeyControl::MaxUploadMbForForm(null));
		$this->assertSame("150", KeyControl::MaxUploadMbForForm(150 * self::MB));
		$this->assertSame("0.5", KeyControl::MaxUploadMbForForm(self::MB / 2));
	}

	private function Key(?int $maxUploadSize): Key {
		$key = new Key();
		$key->max_upload_size = $maxUploadSize;
		return $key;
	}

}
