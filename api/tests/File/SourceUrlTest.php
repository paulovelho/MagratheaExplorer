<?php

namespace MagratheaExplorer\Tests\File;

use MagratheaExplorer\File\SourceUrl;
use MagratheaExplorer\File\SourceUrlException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Tier 2 §6.2 of the 1.5.0 plan: the URL rules of a `source_url`, and redirect resolution. */
class SourceUrlTest extends TestCase {

	private const PORTS = [443];

	public static function refused(): array {
		return [
			"empty" => ["", "empty"],
			"spaces only" => ["   ", "empty"],
			"http" => ["http://example.com/a.jpg", "only https"],
			"ftp" => ["ftp://example.com/a.jpg", "only https"],
			"file" => ["file:///etc/passwd", "only https"],
			"gopher" => ["gopher://example.com/", "only https"],
			"no scheme" => ["example.com/a.jpg", "absolute"],
			"scheme-relative" => ["//example.com/a.jpg", "absolute"],
			"https without //" => ["https:example.com/a.jpg", "absolute"],
			"userinfo" => ["https://user:pass@example.com/", "passwords"],
			"user only" => ["https://user@example.com/", "passwords"],
			"@ in a parser trick" => ["https://example.com@127.0.0.1/", "passwords"],
			"backslash trick" => ["https://example.com\\@127.0.0.1/", "backslash"],
			"control character" => ["https://example.com/a\nb", "control"],
			"tab" => ["https://exam\tple.com/", "control"],
			"NUL" => ["https://example.com/\0", "control"],
			"invalid UTF-8" => ["https://example.com/\xff", "UTF-8"],
			"no host" => ["https:///a.jpg", "no host"],
			"empty label" => ["https://example..com/", "invalid host"],
			"underscore" => ["https://ex_ample.com/", "invalid host"],
			"percent in host" => ["https://ex%61mple.com/", "invalid host"],
			"label over 63" => ["https://".str_repeat("a", 64).".com/", "invalid host"],
			"host over 253" => ["https://".str_repeat("a.", 127)."com/", "too long"],
			"localhost" => ["https://localhost/", "localhost"],
			"LOCALHOST." => ["https://LOCALHOST./", "localhost"],
			"sub.localhost" => ["https://api.localhost/", "localhost"],
			"single label" => ["https://intranet/", "domain"],
			"decimal IPv4" => ["https://2130706433/", "four decimal"],
			"hex IPv4" => ["https://0x7f000001/", "four decimal"],
			"hex part" => ["https://0x7f.0.0.1/", "four decimal"],
			"octal part" => ["https://0177.0.0.1/", "four decimal"],
			"leading zero" => ["https://127.0.0.01/", "four decimal"],
			"short IPv4" => ["https://127.1/", "four decimal"],
			"three parts" => ["https://10.0.1/", "four decimal"],
			"octet over 255" => ["https://1.2.3.256/", "four decimal"],
			"name ending in a number" => ["https://example.123/", "four decimal"],
			"name ending in hex" => ["https://example.0x1f/", "four decimal"],
			"IPv6 unclosed" => ["https://[::1/", "IPv6"],
			"IPv6 invalid" => ["https://[::g]/", "IPv6"],
			"IPv6 zone" => ["https://[fe80::1%25eth0]/", "zone"],
			"IPv6 junk after" => ["https://[::1]x/", "invalid host"],
			"IPv4 in brackets" => ["https://[127.0.0.1]/", "IPv6"],
			"port 80" => ["https://example.com:80/", "port 80 isn't allowed"],
			"port 8443" => ["https://example.com:8443/", "port 8443"],
			"empty port" => ["https://example.com:/", "invalid port"],
			"port 0" => ["https://example.com:0/", "invalid port"],
			"port 65536" => ["https://example.com:65536/", "invalid port"],
			"port with sign" => ["https://example.com:+443/", "invalid port"],
			"too long" => ["https://example.com/".str_repeat("a", SourceUrl::MAX_LENGTH), "longer"],
		];
	}

	#[DataProvider("refused")]
	public function testRefused(string $url, string $reason): void {
		try {
			SourceUrl::Parse($url, self::PORTS);
			$this->fail("expected a refusal");
		} catch(SourceUrlException $ex) {
			$this->assertSame(SourceUrlException::REFUSED, $ex->kind);
			$this->assertStringContainsString($reason, $ex->getMessage());
		}
	}

	public static function accepted(): array {
		return [
			"plain" => ["https://example.com/a.jpg", "https://example.com/a.jpg"],
			"no path" => ["https://example.com", "https://example.com/"],
			"query only" => ["https://example.com?x=1", "https://example.com/?x=1"],
			"case" => ["HTTPS://Example.COM/A.jpg", "https://example.com/A.jpg"],
			"port 443 dropped" => ["https://example.com:443/a", "https://example.com/a"],
			"trailing dot" => ["https://example.com./a", "https://example.com/a"],
			"fragment dropped" => ["https://example.com/a.jpg#x@127.0.0.1", "https://example.com/a.jpg"],
			"surrounding spaces" => ["  https://example.com/a  ", "https://example.com/a"],
			"space in path" => ["https://example.com/a b.jpg", "https://example.com/a%20b.jpg"],
			"UTF-8 in path" => ["https://example.com/fotó.jpg", "https://example.com/fot%C3%B3.jpg"],
			"percent kept" => ["https://example.com/a%20b.jpg?x=%41", "https://example.com/a%20b.jpg?x=%41"],
			"query with ? and /" => ["https://example.com/a?u=/b?c", "https://example.com/a?u=/b?c"],
			"presigned R2" => [
				"https://acc.r2.cloudflarestorage.com/b/k.tif?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Signature=ab12",
				"https://acc.r2.cloudflarestorage.com/b/k.tif?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Signature=ab12",
			],
			"dot segments" => ["https://example.com/a/./b/../c", "https://example.com/a/c"],
			"above the root" => ["https://example.com/../../etc/passwd", "https://example.com/etc/passwd"],
			"IPv4" => ["https://93.184.216.34/a", "https://93.184.216.34/a"],
			"IPv4 zero octet" => ["https://10.0.0.0/", "https://10.0.0.0/"],
			"IPv6" => ["https://[2606:2800:220:1:248:1893:25c8:1946]/a", "https://[2606:2800:220:1:248:1893:25c8:1946]/a"],
			"IPv6 canonical" => ["https://[2606:2800:0220:0001:0:0:0:1]/", "https://[2606:2800:220:1::1]/"],
			"hyphen" => ["https://my-host.example.com/", "https://my-host.example.com/"],
			"punycode" => ["https://xn--bcher-kva.example/", "https://xn--bcher-kva.example/"],
		];
	}

	#[DataProvider("accepted")]
	public function testAcceptedAndRebuilt(string $url, string $canonical): void {
		$this->assertSame($canonical, SourceUrl::Parse($url, self::PORTS)->ToString());
	}

	public function testParts(): void {
		$url = SourceUrl::Parse("https://Files.Example.com:8443/dir/Photo%201.jpg?sig=x", [443, 8443]);
		$this->assertSame("files.example.com", $url->host);
		$this->assertFalse($url->isIp);
		$this->assertSame(8443, $url->port);
		$this->assertSame("/dir/Photo%201.jpg", $url->path);
		$this->assertSame("sig=x", $url->query);
		$this->assertSame("files.example.com:8443", $url->Authority());
		$this->assertSame("Photo%201.jpg", $url->LastSegment());
		$this->assertSame("https://files.example.com:8443/dir/Photo%201.jpg?sig=x", $url->ToString());

		$ip = SourceUrl::Parse("https://[2001:4860:4860::8888]/", self::PORTS);
		$this->assertTrue($ip->isIp);
		$this->assertSame("2001:4860:4860::8888", $ip->host);
		$this->assertSame("", $ip->LastSegment());
	}

	public function testPortsFromTheList(): void {
		$this->assertSame(8443, SourceUrl::Parse("https://example.com:8443/", [443, 8443])->port);
		$this->assertSame(443, SourceUrl::Parse("https://example.com/", [443, 8443])->port);
	}

	/** Without ext-intl an IDN is refused with a way out; with it, converted. */
	public function testInternationalHost(): void {
		if(function_exists("idn_to_ascii")) {
			$this->assertSame("https://xn--bcher-kva.example/", SourceUrl::Parse("https://Bücher.example/", self::PORTS)->ToString());
			return;
		}
		try {
			SourceUrl::Parse("https://bücher.example/", self::PORTS);
			$this->fail("expected a refusal");
		} catch(SourceUrlException $ex) {
			$this->assertStringContainsString("punycode", $ex->getMessage());
		}
	}

	// ------------------------------------------------------------ redirects

	public static function redirects(): array {
		$base = "https://example.com/dir/file.jpg?x=1";
		return [
			"absolute" => [$base, "https://cdn.example.net/a.jpg", "https://cdn.example.net/a.jpg"],
			"scheme-relative" => [$base, "//cdn.example.net/a.jpg", "https://cdn.example.net/a.jpg"],
			"absolute path" => [$base, "/other/b.jpg", "https://example.com/other/b.jpg"],
			"relative" => [$base, "b.jpg", "https://example.com/dir/b.jpg"],
			"relative up" => [$base, "../b.jpg", "https://example.com/b.jpg"],
			"relative dot" => [$base, "./sub/b.jpg", "https://example.com/dir/sub/b.jpg"],
			"query only" => [$base, "?y=2", "https://example.com/dir/file.jpg?y=2"],
			"fragment only" => [$base, "#top", "https://example.com/dir/file.jpg?x=1"],
			"keeps the port" => ["https://example.com:8443/a/b", "c", "https://example.com:8443/a/c"],
			"from the root" => ["https://example.com/", "a.jpg", "https://example.com/a.jpg"],
			"space in Location" => [$base, "/a b.jpg", "https://example.com/a%20b.jpg"],
		];
	}

	#[DataProvider("redirects")]
	public function testRedirectResolution(string $base, string $location, string $expected): void {
		$this->assertSame($expected, SourceUrl::Parse($base, [443, 8443])->Resolve($location, [443, 8443])->ToString());
	}

	public static function refusedRedirects(): array {
		return [
			"to http" => ["http://example.com/a.jpg", "only https"],
			"to ftp" => ["ftp://example.com/a.jpg", "only https"],
			"to localhost" => ["https://localhost/a", "localhost"],
			"scheme-relative to localhost" => ["//localhost/a", "localhost"],
			"to a decimal IP" => ["https://2130706433/", "four decimal"],
			"to another port" => ["https://example.com:22/", "port 22"],
			"with userinfo" => ["https://a@example.com/", "passwords"],
			"a scheme-looking host" => ["localhost:8080/a", "only https"],
		];
	}

	#[DataProvider("refusedRedirects")]
	public function testRedirectsAreHeldToTheRules(string $location, string $reason): void {
		try {
			SourceUrl::Parse("https://example.com/a", self::PORTS)->Resolve($location, self::PORTS);
			$this->fail("expected a refusal");
		} catch(SourceUrlException $ex) {
			$this->assertSame(SourceUrlException::REFUSED, $ex->kind);
			$this->assertStringContainsString($reason, $ex->getMessage());
		}
	}

	/** Whether 127.0.0.1 may be fetched is the policy's call (SafeFetcher), not the URL's: it parses. */
	public function testAPrivateLiteralParses(): void {
		$url = SourceUrl::Parse("https://example.com/a", self::PORTS)->Resolve("https://127.0.0.1/admin", self::PORTS);
		$this->assertTrue($url->isIp);
		$this->assertSame("127.0.0.1", $url->host);
	}

}
