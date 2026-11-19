<?php

namespace MagratheaExplorer\Tests\File;

use Magrathea\Exceptions\MagratheaApiException;
use MagratheaExplorer\File\FileApi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** POST /files' fields that both sources share (Tier 2 §6.1 of the 1.5.0 plan): `tags` and `name`. */
class FileApiTest extends TestCase {

	public static function tags(): array {
		return [
			"absent" => [[], []],
			"null (JSON)" => [["tags" => null], []],
			"empty" => [["tags" => ""], []],
			"comma-separated" => [["tags" => "invoice, 2026"], ["invoice", "2026"]],
			"empty items dropped" => [["tags" => "a,, ,b,"], ["a", "b"]],
			"a tag named 0 kept" => [["tags" => "0,a"], ["0", "a"]],
			"JSON list" => [["tags" => ["invoice", " 2026 "]], ["invoice", "2026"]],
			"empty JSON list" => [["tags" => []], []],
			"form array (tags[]=)" => [["tags" => ["a", "b"]], ["a", "b"]],
		];
	}

	#[DataProvider("tags")]
	public function testTags(array $post, array $expected): void {
		$this->assertSame($expected, FileApi::ParseTags($post));
	}

	public static function badTags(): array {
		return [
			"a number" => [["tags" => 5]], "true" => [["tags" => true]], "an object" => [["tags" => ["a" => "b"]]],
			"a list with a number" => [["tags" => ["a", 1]]], "nested" => [["tags" => [["a"]]]],
		];
	}

	/** JSON bodies made these a TypeError (a 500) in explode(). */
	#[DataProvider("badTags")]
	public function testBadTagsAre40013(array $post): void {
		$this->assertRefused(fn() => FileApi::ParseTags($post), "tags");
	}

	public static function names(): array {
		return [
			"absent" => [[], null],
			"null (JSON)" => [["name" => null], null],
			"blank = the file's own" => [["name" => "  "], null],
			"trimmed" => [["name" => " report.pdf "], "report.pdf"],
			"UTF-8" => [["name" => "relatório.pdf"], "relatório.pdf"],
			"255 characters" => [["name" => str_repeat("é", 255)], str_repeat("é", 255)],
			"a slash is a character" => [["name" => "a/b.pdf"], "a/b.pdf"],
		];
	}

	#[DataProvider("names")]
	public function testName(array $post, ?string $expected): void {
		$this->assertSame($expected, FileApi::ParseName($post));
	}

	public static function badNames(): array {
		return [
			"a number" => [["name" => 5]], "a list" => [["name" => ["a"]]], "true" => [["name" => true]],
			"256 characters" => [["name" => str_repeat("a", 256)]], "a newline" => [["name" => "a\nb"]],
			"NUL" => [["name" => "a\0b"]], "invalid UTF-8" => [["name" => "caf\xE9"]],
		];
	}

	#[DataProvider("badNames")]
	public function testBadNamesAre40013(array $post): void {
		$this->assertRefused(fn() => FileApi::ParseName($post), "name");
	}

	private function assertRefused(callable $parse, string $field): void {
		try {
			$parse();
			$this->fail("expected 40013");
		} catch(MagratheaApiException $ex) {
			$this->assertSame(40013, $ex->getCode());
			$this->assertStringContainsString($field, $ex->getMessage());
		}
	}

}
