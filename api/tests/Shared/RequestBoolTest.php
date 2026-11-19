<?php

namespace MagratheaExplorer\Tests\Shared;

use Magrathea\Exceptions\MagratheaApiException;
use MagratheaExplorer\RequestBool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** §2.1 of the 1.5.0 plan: every API flag goes through RequestBool. */
class RequestBoolTest extends TestCase {

	public static function trueValues(): array {
		return [
			"1" => ["1"], "true" => ["true"], "yes" => ["yes"], "on" => ["on"],
			"TRUE (case)" => ["TRUE"], "Yes (case)" => ["Yes"], " on  (trimmed)" => [" on "],
			"bool true (JSON)" => [true], "int 1 (JSON)" => [1],
		];
	}

	public static function falseValues(): array {
		return [
			"0" => ["0"], "false" => ["false"], "no" => ["no"], "off" => ["off"], "empty" => [""],
			"FALSE (case)" => ["FALSE"], "whitespace only" => ["  "], " 0 (trimmed)" => [" 0"],
			"bool false (JSON)" => [false], "int 0 (JSON)" => [0],
		];
	}

	public static function invalidValues(): array {
		return [
			"maybe" => ["maybe"], "2" => ["2"], "int 2" => [2], "-1" => ["-1"], "y" => ["y"],
			"float 1.0" => [1.0], "array" => [["1"]], "01" => ["01"], "true1" => ["true1"],
		];
	}

	#[DataProvider("trueValues")]
	public function testTrue(mixed $value): void {
		$this->assertTrue(RequestBool::Parse(["flag" => $value], "flag"));
	}

	#[DataProvider("falseValues")]
	public function testFalse(mixed $value): void {
		$this->assertFalse(RequestBool::Parse(["flag" => $value], "flag"));
	}

	public function testAbsentIsNull(): void {
		$this->assertNull(RequestBool::Parse(["other" => "1"], "flag"));
	}

	public function testJsonNullIsNull(): void {
		$this->assertNull(RequestBool::Parse(["flag" => null], "flag"));
	}

	#[DataProvider("invalidValues")]
	public function testRefuses(mixed $value): void {
		try {
			RequestBool::Parse(["no_convert" => $value], "no_convert");
			$this->fail("expected 4009");
		} catch(MagratheaApiException $ex) {
			$this->assertSame(4009, $ex->getCode());
			$this->assertSame("Invalid boolean value: no_convert", $ex->getMessage());
		}
	}

}
