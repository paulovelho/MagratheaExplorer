<?php

namespace MagratheaExplorer\Tests\File;

use MagratheaExplorer\File\RemoteFileName;
use MagratheaExplorer\File\SourceUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Tier 2 §6.3 of the 1.5.0 plan: the name of a file fetched from a URL. */
class RemoteFileNameTest extends TestCase {

	public static function dispositions(): array {
		return [
			"quoted" => ['attachment; filename="report 2026.pdf"', "report 2026.pdf"],
			"token" => ["attachment; filename=report.pdf", "report.pdf"],
			"inline" => ['inline; filename="a.jpg"', "a.jpg"],
			"filename* wins" => ["attachment; filename=\"fallback.pdf\"; filename*=UTF-8''na%C3%AFve.pdf", "naïve.pdf"],
			"filename* first in the header" => ["attachment; filename*=UTF-8''%E2%82%AC.pdf; filename=\"e.pdf\"", "€.pdf"],
			"filename* lower-case charset" => ["attachment; filename*=utf-8'en'a%20b.pdf", "a b.pdf"],
			"filename* ISO-8859-1" => ["attachment; filename*=ISO-8859-1''caf%E9.txt", "café.txt"],
			"filename* unknown charset → filename" => ["attachment; filename*=KOI8-R''x.pdf; filename=\"y.pdf\"", "y.pdf"],
			"filename* malformed → filename" => ["attachment; filename*=x.pdf; filename=\"y.pdf\"", "y.pdf"],
			"filename* bad UTF-8 → filename" => ["attachment; filename*=UTF-8''%FF.pdf; filename=\"y.pdf\"", "y.pdf"],
			"escaped quote" => ['attachment; filename="say \"hi\".txt"', 'say "hi".txt'],
			"semicolon in quotes" => ['attachment; filename="a;b.txt"; size=3', "a;b.txt"],
			"spaces around =" => ['attachment; filename = "a.txt"', "a.txt"],
			"case of the parameter" => ['attachment; FileName="a.txt"', "a.txt"],
			"raw UTF-8" => ['attachment; filename="fotó.jpg"', "fotó.jpg"],
			"raw ISO-8859-1" => ["attachment; filename=\"caf\xE9.txt\"", "café.txt"],
			"a path" => ['attachment; filename="../../etc/passwd"', "passwd"],
			"a Windows path" => ['attachment; filename="C:\\temp\\x.doc"', "x.doc"],
			"control characters" => ["attachment; filename=\"a\x01b.txt\"", "ab.txt"],
			"surrounding spaces" => ['attachment; filename="  a.txt  "', "a.txt"],
			"other parameters first" => ['attachment; creation-date="x"; filename="a.txt"', "a.txt"],
			"unclosed quote" => ['attachment; filename="a.txt', "a.txt"],
		];
	}

	#[DataProvider("dispositions")]
	public function testContentDisposition(string $header, string $expected): void {
		$this->assertSame($expected, RemoteFileName::FromContentDisposition($header));
	}

	public static function noName(): array {
		return [
			"no filename" => ["attachment"], "inline" => ["inline"], "empty" => [""], "empty filename" => ['attachment; filename=""'],
			"only a slash" => ['attachment; filename="/"'], "dot dot" => ['attachment; filename=".."'],
			"no value" => ["attachment; filename"], "garbage" => [";;;=;"],
		];
	}

	#[DataProvider("noName")]
	public function testNoUsableName(string $header): void {
		$this->assertNull(RemoteFileName::FromContentDisposition($header));
	}

	public function testUrlSegment(): void {
		$this->assertSame("Photo 1.jpg", RemoteFileName::FromUrl(self::Url("https://example.com/dir/Photo%201.jpg?sig=abc")));
		$this->assertSame("fotó.jpg", RemoteFileName::FromUrl(self::Url("https://example.com/fot%C3%B3.jpg")));
		$this->assertNull(RemoteFileName::FromUrl(self::Url("https://example.com/dir/")));
		$this->assertNull(RemoteFileName::FromUrl(self::Url("https://example.com")));
		$this->assertNull(RemoteFileName::FromUrl(self::Url("https://example.com/%FF")), "not UTF-8 once decoded");
		$this->assertSame("b", RemoteFileName::FromUrl(self::Url("https://example.com/a%2Fb")), "an encoded slash doesn't smuggle a path");
	}

	public function testChooseOrder(): void {
		$sent = self::Url("https://example.com/download?id=5");
		$final = self::Url("https://cdn.example.com/files/report.pdf");
		$this->assertSame("given.pdf", RemoteFileName::Choose('attachment; filename="given.pdf"', $final, $sent));
		$this->assertSame("report.pdf", RemoteFileName::Choose(null, $final, $sent), "the URL the file came from");
		$this->assertSame("report.pdf", RemoteFileName::Choose("attachment", $final, $sent), "a disposition without a name");
		$this->assertSame("download", RemoteFileName::Choose(null, self::Url("https://cdn.example.com/"), $sent),
			"the URL that was sent, when the final one has no segment");
		$this->assertSame("download", RemoteFileName::Choose(null, self::Url("https://example.com/")), "the fallback");
	}

	public function testTruncateKeepsTheExtension(): void {
		$name = RemoteFileName::Truncate(str_repeat("a", 300).".pdf");
		$this->assertSame(255, mb_strlen($name));
		$this->assertStringEndsWith("a.pdf", $name);

		$multibyte = RemoteFileName::Truncate(str_repeat("é", 300).".jpeg");
		$this->assertSame(255, mb_strlen($multibyte));
		$this->assertStringEndsWith(".jpeg", $multibyte);

		$this->assertSame(str_repeat("a", 255), RemoteFileName::Truncate(str_repeat("a", 300)), "no extension");
		$this->assertSame(255, mb_strlen(RemoteFileName::Truncate(str_repeat("a", 280).".".str_repeat("x", 30))), "an overlong extension is cut");
		$this->assertSame("short.txt", RemoteFileName::Truncate("short.txt"));
	}

	private static function Url(string $url): SourceUrl {
		return SourceUrl::Parse($url, [443]);
	}

}
