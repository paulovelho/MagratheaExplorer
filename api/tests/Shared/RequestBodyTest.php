<?php

namespace MagratheaExplorer\Tests\Shared;

use Magrathea\Exceptions\MagratheaApiException;
use MagratheaExplorer\RequestBody;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Tier 2 §2.1-§2.2 of the 1.5.0 plan: JSON by media type, strict JSON, the size cap, the multipart overflow. */
class RequestBodyTest extends TestCase {

	public static function jsonTypes(): array {
		return [
			"plain" => ["application/json"],
			"charset" => ["application/json; charset=utf-8"],
			"charset, no space" => ["application/json;charset=UTF-8"],
			"upper case" => ["APPLICATION/JSON"],
			"padded" => ["  application/json  ; charset=utf-8"],
			"+json" => ["application/merge-patch+json"],
			"+json with charset" => ["application/vnd.api+json; charset=utf-8"],
		];
	}

	public static function notJsonTypes(): array {
		return [
			"form" => ["application/x-www-form-urlencoded"],
			"form with charset" => ["application/x-www-form-urlencoded; charset=utf-8"],
			"multipart" => ["multipart/form-data; boundary=x"],
			"text" => ["text/plain"],
			"json only as a parameter" => ["text/plain; profile=json"],
			"jsonp" => ["application/jsonp"],
			"none" => [null],
			"empty" => [""],
		];
	}

	#[DataProvider("jsonTypes")]
	public function testJsonTypes(string $contentType): void {
		$this->assertTrue(RequestBody::IsJson($contentType));
		$this->assertSame(["name" => "photos", "n" => 1], RequestBody::Parse($contentType, '{"name":"photos","n":1}'));
	}

	#[DataProvider("notJsonTypes")]
	public function testNotJsonTypes(?string $contentType): void {
		$this->assertFalse(RequestBody::IsJson($contentType));
	}

	public function testFormFallback(): void {
		$this->assertSame(["name" => "a b", "tags" => "x,y"], RequestBody::Parse("application/x-www-form-urlencoded", "name=a+b&tags=x%2Cy"));
		// what the framework did for a JSON body sent with a form type: no fields, not an error
		$this->assertSame([], RequestBody::Parse("text/plain", ""));
	}

	public function testNestedJsonKeepsTypes(): void {
		$fields = RequestBody::Parse("application/json", ' {"verbatim":true,"profile":[{"name":"thumb"}],"none":null}');
		$this->assertSame(["verbatim" => true, "profile" => [["name" => "thumb"]], "none" => null], $fields);
	}

	public function testEmptyJsonIsNoFields(): void {
		$this->assertSame([], RequestBody::Parse("application/json", ""));
		$this->assertSame([], RequestBody::Parse("application/json", " \n\t"));
		$this->assertSame([], RequestBody::Parse("application/json", "{}"));
	}

	public static function invalidJson(): array {
		return [
			"syntax" => ['{"name": ', "Syntax error"],
			"trailing garbage" => ['{"name":"a"} x', "Syntax error"],
			"single quotes" => ["{'name':'a'}", "Syntax error"],
			"bad utf-8" => ["{\"name\":\"\xC3\x28\"}", "Malformed UTF-8 characters, possibly incorrectly encoded"],
			"too deep" => [str_repeat('{"a":', 600)."1".str_repeat("}", 600), "Maximum stack depth exceeded"],
			"string" => ['"photos"', "expected a JSON object"],
			"number" => ["42", "expected a JSON object"],
			"true" => ["true", "expected a JSON object"],
			"null" => ["null", "expected a JSON object"],
			"list" => ['["photos"]', "expected a JSON object"],
			"empty list" => ["[]", "expected a JSON object"],
		];
	}

	#[DataProvider("invalidJson")]
	public function testInvalidJsonIs40010(string $raw, string $detail): void {
		try {
			RequestBody::Parse("application/json; charset=utf-8", $raw);
			$this->fail("expected 40010");
		} catch(MagratheaApiException $ex) {
			$this->assertSame(40010, $ex->getCode());
			$this->assertSame("Invalid JSON body: ".$detail, $ex->getMessage());
		}
	}

	public function testReadsUpToTheCap(): void {
		$body = str_repeat("a", RequestBody::MAX_BYTES);
		$this->assertSame($body, RequestBody::Read($this->Stream($body), (string)strlen($body)));
		$this->assertSame("x=1", RequestBody::Read($this->Stream("x=1"), null));
		$this->assertSame("", RequestBody::Read($this->Stream(""), "0"));
	}

	public function testRefusesByContentLength(): void {
		// nothing is read: the stream is empty, the header alone refuses it
		$this->AssertTooLarge(fn() => RequestBody::Read($this->Stream(""), (string)(RequestBody::MAX_BYTES + 1)));
	}

	public function testRefusesByWhatArrives(): void {
		$body = str_repeat("a", RequestBody::MAX_BYTES + 1);
		$this->AssertTooLarge(fn() => RequestBody::Read($this->Stream($body), null));
		$this->AssertTooLarge(fn() => RequestBody::Read($this->Stream($body), "10")); // a lying header
	}

	public function testContentLength(): void {
		$this->assertSame(1234, RequestBody::ContentLength("1234"));
		$this->assertSame(1234, RequestBody::ContentLength(1234));
		$this->assertSame(0, RequestBody::ContentLength("0"));
		$this->assertNull(RequestBody::ContentLength(null));
		$this->assertNull(RequestBody::ContentLength(""));
		$this->assertNull(RequestBody::ContentLength("-1"));
		$this->assertNull(RequestBody::ContentLength("12 34"));
		$this->assertNull(RequestBody::ContentLength("1e9"));
	}

	public function testPostMaxSizeOverflow(): void {
		$multipart = "multipart/form-data; boundary=----x";
		$this->assertTrue(RequestBody::ExceedsPostMaxSize($multipart, "1001", 1000));
		$this->assertFalse(RequestBody::ExceedsPostMaxSize($multipart, "1000", 1000));
		$this->assertFalse(RequestBody::ExceedsPostMaxSize($multipart, null, 1000));
		$this->assertFalse(RequestBody::ExceedsPostMaxSize($multipart, "999999999", PHP_INT_MAX), "post_max_size = 0 is no limit");
		// what the old heuristic got wrong: a JSON (or form) POST /files has no $_FILES and no $_POST either
		$this->assertFalse(RequestBody::ExceedsPostMaxSize("application/json", "1001", 1000));
		$this->assertFalse(RequestBody::ExceedsPostMaxSize("application/x-www-form-urlencoded", "1001", 1000));
		$this->assertFalse(RequestBody::ExceedsPostMaxSize(null, "1001", 1000));
	}

	/** @return resource */
	private function Stream(string $content) {
		$stream = fopen("php://memory", "r+");
		fwrite($stream, $content);
		rewind($stream);
		return $stream;
	}

	private function AssertTooLarge(callable $read): void {
		try {
			$read();
			$this->fail("expected 413");
		} catch(MagratheaApiException $ex) {
			$this->assertSame(413, $ex->getCode());
			$this->assertSame("Payload Too Large: request body over 1 MB", $ex->getMessage());
		}
	}

}
