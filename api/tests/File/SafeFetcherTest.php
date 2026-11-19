<?php

namespace MagratheaExplorer\Tests\File;

use MagratheaExplorer\File\AddressPolicy;
use MagratheaExplorer\File\FetchedFile;
use MagratheaExplorer\File\HostResolver;
use MagratheaExplorer\File\SafeFetcher;
use MagratheaExplorer\File\SourceUrl;
use MagratheaExplorer\File\SourceUrlException;
use MagratheaExplorer\Tests\Support\ScratchDir;
use MagratheaExplorer\Tests\Support\TestConfig;
use MagratheaExplorer\Tests\Support\TlsTestServer;
use PHPUnit\Framework\TestCase;

/**
 * Tier 2 §6.6 of the 1.5.0 plan, the integration run: SafeFetcher against a local HTTPS server
 * (tests/Support/tls-server.php), with a resolver that maps the test names to loopback (so no
 * DNS is involved: every connection goes through CURLOPT_RESOLVE) and a policy that allows
 * 127.0.0.1 only.
 */
class SafeFetcherTest extends TestCase {

	private static ?TlsTestServer $server = null;
	private string $dir;

	public static function setUpBeforeClass(): void {
		if(TlsTestServer::Unavailable() === null) self::$server = TlsTestServer::Start();
	}

	public static function tearDownAfterClass(): void {
		self::$server?->Stop();
		self::$server = null;
	}

	protected function setUp(): void {
		$reason = TlsTestServer::Unavailable();
		if($reason !== null) $this->markTestSkipped($reason);
		TestConfig::Set();
		$this->dir = ScratchDir::Create();
	}

	protected function tearDown(): void {
		ScratchDir::Remove($this->dir);
		TestConfig::Set();
	}

	// ------------------------------------------------------------ fetching

	/** Verbatim's promise: the bytes stored are the bytes served, so their sha256 is the source's. */
	public function testFetchesTheBytesAsServed(): void {
		$fetched = $this->Fetch("/bytes/100000");
		$this->assertSame(100000, $fetched->size);
		$this->assertSame(hash("sha256", TlsTestServer::Body(100000)), hash_file("sha256", $fetched->path));
		$this->assertStringStartsWith($this->dir."/", $fetched->path, "inside the target directory");
		$this->assertNull($fetched->contentDisposition);
		$this->assertSame(self::$server->Url("/bytes/100000"), $fetched->finalUrl->ToString());
	}

	public function testWithoutContentLength(): void {
		$this->assertSame(hash("sha256", TlsTestServer::Body(50000)), hash_file("sha256", $this->Fetch("/close/50000")->path));
		$this->assertSame(hash("sha256", TlsTestServer::Body(50000)), hash_file("sha256", $this->Fetch("/chunked/50000")->path));
	}

	public function testAnIpLiteralIsNotResolved(): void {
		$fetched = $this->Fetch("/bytes/10", host: "127.0.0.1");
		$this->assertSame(10, $fetched->size);
	}

	public function testContentDispositionIsReturned(): void {
		$this->assertSame('attachment; filename="from-header.txt"', $this->Fetch("/disposition")->contentDisposition);
	}

	/** A fixed User-Agent, identity encoding, no cookies, no credentials. */
	public function testRequestHeaders(): void {
		$headers = file_get_contents($this->Fetch("/headers")->path);
		$this->assertMatchesRegularExpression('/^User-Agent: MagratheaExplorer\//mi', $headers);
		$this->assertMatchesRegularExpression('/^Accept-Encoding: identity\r?$/mi', $headers);
		$this->assertDoesNotMatchRegularExpression('/^(Cookie|Authorization|Proxy-Authorization):/mi', $headers);
	}

	/** libcurl would take https_proxy from the environment: CURLOPT_PROXY "" and NOPROXY "*" keep it out. */
	public function testTheEnvironmentsProxyIsIgnored(): void {
		$before = getenv("https_proxy");
		putenv("https_proxy=http://127.0.0.1:9");
		putenv("HTTPS_PROXY=http://127.0.0.1:9");
		try {
			$this->assertSame(10, $this->Fetch("/bytes/10")->size);
		} finally {
			putenv($before === false ? "https_proxy" : "https_proxy=".$before);
			putenv("HTTPS_PROXY");
		}
	}

	// ------------------------------------------------------------ redirects

	public function testThreeRedirectsAreFollowed(): void {
		$fetched = $this->Fetch("/redirect/3");
		$this->assertSame("done", file_get_contents($fetched->path));
		$this->assertSame(self::$server->Url("/redirect/0"), $fetched->finalUrl->ToString());
	}

	public function testAFourthIsRefused(): void {
		$this->AssertFails("/redirect/4", SourceUrlException::REFUSED, "more than 3 redirects");
	}

	public function testRedirectToAnotherAllowedHost(): void {
		$to = self::$server->Url("/bytes/7", "other.test");
		$fetched = $this->Fetch("/location?to=".rawurlencode($to));
		$this->assertSame(7, $fetched->size);
		$this->assertSame("other.test", $fetched->finalUrl->host);
	}

	public function testRedirectToARefusedAddress(): void {
		$this->AssertFails("/location?to=".rawurlencode("https://10.0.0.1:".self::$server->port."/"), SourceUrlException::REFUSED, "not a public address");
	}

	/** One refused answer among allowed ones refuses the URL: `[public, private]` gets nothing. */
	public function testRedirectToANameWithOneRefusedAnswer(): void {
		$to = self::$server->Url("/bytes/7", "mixed.test");
		$this->AssertFails("/location?to=".rawurlencode($to), SourceUrlException::REFUSED, "doesn't resolve to a public address");
	}

	public function testRedirectToHttp(): void {
		$this->AssertFails("/location?to=".rawurlencode("http://fetch.test/"), SourceUrlException::REFUSED, "redirect: only https");
	}

	public function testRedirectToAPortNotAllowed(): void {
		$this->AssertFails("/location?to=".rawurlencode("https://fetch.test:1/"), SourceUrlException::REFUSED, "redirect: port 1");
	}

	public function testRedirectWithoutALocation(): void {
		$this->AssertFails("/no-location", SourceUrlException::FAILED, "302 without a Location");
	}

	// ------------------------------------------------------------ upstream failures

	public function testUpstreamStatus(): void {
		$this->AssertFails("/status/404", SourceUrlException::FAILED, "upstream 404");
		$this->AssertFails("/status/500", SourceUrlException::FAILED, "upstream 500");
		$this->AssertFails("/status/204", SourceUrlException::FAILED, "upstream 204");
		$this->AssertFails("/status/206", SourceUrlException::FAILED, "upstream 206");
	}

	public function testOnlyIdentityEncoding(): void {
		$this->AssertFails("/gzip", SourceUrlException::FAILED, "Content-Encoding: gzip");
	}

	public function testABodyShorterThanItsContentLength(): void {
		$this->AssertFails("/lie/100", SourceUrlException::FAILED, "before the Content-Length");
	}

	public function testATricklingServer(): void {
		$started = microtime(true);
		$this->AssertFails("/trickle", SourceUrlException::FAILED, "too slow");
		$this->assertLessThan(8, microtime(true) - $started);
	}

	public function testTheWholeFetchTimesOut(): void {
		$fetcher = $this->Fetcher(timeoutS: 2, lowSpeedTimeS: 30);
		$this->AssertFails("/slow/5", SourceUrlException::FAILED, "timed out after 2 s", $fetcher);
	}

	/**
	 * No oracle: a name that doesn't exist and one that resolves to a private address give the
	 * same refusal, word for word, so a key holder can't probe the server's internal names.
	 */
	public function testANameWithoutAddressesIsRefusedLikeAPrivateOne(): void {
		$missing = $this->AssertFails("/bytes/10", SourceUrlException::REFUSED, "doesn't resolve to a public address", host: "unknown.test");
		$private = $this->AssertFails("/bytes/10", SourceUrlException::REFUSED, "doesn't resolve to a public address", host: "private.test");
		$this->assertSame($missing->getMessage(), $private->getMessage());
	}

	/** A lookup that failed (timeout, SERVFAIL) says nothing about the name, and a retry can get past it. */
	public function testAFailedLookup(): void {
		$this->AssertFails("/bytes/10", SourceUrlException::FAILED, "DNS lookup failed for dnsfail.test", host: "dnsfail.test");
	}

	/** The pinned address is the one curl uses: 127.0.0.2 is loopback too, but nothing listens there. */
	public function testItConnectsToTheCheckedAddressOnly(): void {
		$this->AssertFails("/bytes/10", SourceUrlException::FAILED, "could not connect", host: "elsewhere.test");
	}

	public function testAnUntrustedCertificate(): void {
		$fetcher = new SafeFetcher(self::Resolver(), self::Policy(), [443, self::$server->port], 10, null, 2);
		$this->AssertFails("/bytes/10", SourceUrlException::FAILED, "TLS certificate invalid", $fetcher);
	}

	/** VERIFYHOST: the certificate is for fetch.test and other.test, not this one. */
	public function testACertificateForAnotherHost(): void {
		$this->AssertFails("/bytes/10", SourceUrlException::FAILED, "TLS certificate invalid", host: "nocert.test");
	}

	// ------------------------------------------------------------ size

	public function testAContentLengthOverTheLimit(): void {
		$ex = $this->AssertFails("/bytes/5000", SourceUrlException::TOO_LARGE, "5000 bytes", maxBytes: 1000);
		$this->assertSame(5000, $ex->size);
	}

	public function testABodyOverTheLimitWithoutContentLength(): void {
		$ex = $this->AssertFails("/close/50000", SourceUrlException::TOO_LARGE, "over the size limit", maxBytes: 1000);
		$this->assertNull($ex->size);
		$ex = $this->AssertFails("/chunked/50000", SourceUrlException::TOO_LARGE, "over the size limit", maxBytes: 1000);
		$this->assertNull($ex->size);
	}

	public function testExactlyTheLimit(): void {
		$this->assertSame(1000, $this->Fetch("/bytes/1000", 1000)->size);
		$this->assertSame(1000, $this->Fetch("/close/1000", 1000)->size);
	}

	/** FileApi's quota check: called with the Content-Length before the body, and what it throws is rethrown. */
	public function testCheckLength(): void {
		$seen = null;
		$fetched = $this->Fetch("/bytes/321", checkLength: function(int $length) use (&$seen) { $seen = $length; });
		$this->assertSame(321, $seen);
		unlink($fetched->path); // the caller's

		try {
			$this->Fetch("/bytes/321", checkLength: function(int $length) { throw new \DomainException("over quota"); });
			$this->fail("expected the callback's exception");
		} catch(\DomainException $ex) {
			$this->assertSame("over quota", $ex->getMessage());
		}
		$this->assertSame([], self::Files($this->dir), "nothing left behind");
	}

	// ------------------------------------------------------------ leftovers

	/**
	 * A PHP fatal skips every `finally`: the shutdown function deletes the download anyway. A
	 * child process runs out of memory in the middle of a fetch.
	 */
	public function testAFatalErrorLeavesNoDownload(): void {
		$script = $this->dir."/../explorer_fatal_".bin2hex(random_bytes(4)).".php";
		file_put_contents($script, '<?php
require '.var_export(dirname(__DIR__)."/bootstrap.php", true).';
use MagratheaExplorer\File\{SafeFetcher, SourceUrl, AddressPolicy, HostResolver};
$resolver = new class implements HostResolver { public function Resolve(string $h): array { return ["127.0.0.1"]; } };
$policy = new class implements AddressPolicy { public function IsAllowed(string $ip): bool { return true; } };
$fetcher = new SafeFetcher($resolver, $policy, [443, (int)$argv[1]], 10, $argv[2]);
$fetcher->Fetch(SourceUrl::Parse($argv[3], [443, (int)$argv[1]]), 10000000, $argv[4], function(int $length) {
	ini_set("memory_limit", "16M");
	$waste = str_repeat("x", 64 * 1024 * 1024);
});
echo "not reached\n";
');
		try {
			$process = proc_open([PHP_BINARY, $script, (string)self::$server->port, self::$server->caFile, self::$server->Url("/bytes/1000"), $this->dir],
				[1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
			$output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
			$exit = proc_close($process);
		} finally {
			@unlink($script);
		}
		$this->assertNotSame(0, $exit, $output);
		$this->assertStringContainsString("Allowed memory size", $output);
		$this->assertStringNotContainsString("not reached", $output);
		$this->assertSame([], self::Files($this->dir), "the shutdown function deleted the download");
	}

	/** tempnam() would fall back to the shared /tmp: refused instead. */
	public function testAnUnusableTargetDirectory(): void {
		$url = SourceUrl::Parse(self::$server->Url("/bytes/10"), [443, self::$server->port]);
		try {
			$this->Fetcher()->Fetch($url, 1000, $this->dir."/missing");
			$this->fail("expected 5001");
		} catch(\Magrathea\Exceptions\MagratheaApiException $ex) {
			$this->assertSame(5001, $ex->getCode());
		}
		$this->assertSame([], glob(sys_get_temp_dir()."/".SafeFetcher::FILE_PREFIX."*") ?: [], "nothing in the system's temp dir");
	}

	public function testSweepStale(): void {
		$now = time();
		$old = $this->dir."/".SafeFetcher::FILE_PREFIX."old";
		$fresh = $this->dir."/".SafeFetcher::FILE_PREFIX."fresh";
		$other = $this->dir."/lock-process";
		$link = $this->dir."/".SafeFetcher::FILE_PREFIX."link";
		foreach([$old, $fresh, $other] as $path) file_put_contents($path, "x");
		touch($old, $now - SafeFetcher::STALE_AFTER_S - 60);
		touch($other, $now - SafeFetcher::STALE_AFTER_S - 60);
		symlink($other, $link);
		mkdir($this->dir."/".SafeFetcher::FILE_PREFIX."dir");

		$this->assertSame(1, SafeFetcher::SweepStale($this->dir, $now));
		$this->assertFileDoesNotExist($old);
		$this->assertFileExists($fresh, "a download in progress");
		$this->assertFileExists($other, "not a download");
		$this->assertTrue(is_link($link), "a symlink is never followed or removed");
		$this->assertDirectoryExists($this->dir."/".SafeFetcher::FILE_PREFIX."dir");
		$this->assertSame(0, SafeFetcher::SweepStale($this->dir."/missing", $now), "no directory, nothing to do");
	}

	// ------------------------------------------------------------ settings

	public function testSettings(): void {
		$this->assertFalse(SafeFetcher::IsEnabled(), "opt-in: absent = off");
		$this->assertSame(90, SafeFetcher::ConfiguredTimeoutS());
		$this->assertSame([443], SafeFetcher::ConfiguredPorts());
		TestConfig::Set(["source_url_enabled" => "true", "source_url_timeout_s" => "60", "source_url_ports" => " 8443, 443 ,9000"]);
		$this->assertTrue(SafeFetcher::IsEnabled());
		$this->assertSame(60, SafeFetcher::ConfiguredTimeoutS());
		$this->assertSame([443, 8443, 9000], SafeFetcher::ConfiguredPorts());
		$this->assertSame([443, 8443, 9000], (new SafeFetcher())->Ports());
	}

	public function testBadPortsSetting(): void {
		foreach(["https", "0", "65536", "443;8443", "-1"] as $raw) {
			TestConfig::Set(["source_url_ports" => $raw]);
			try {
				SafeFetcher::ConfiguredPorts();
				$this->fail("expected 5004 for ".$raw);
			} catch(\Magrathea\Exceptions\MagratheaApiException $ex) {
				$this->assertSame(5004, $ex->getCode());
				$this->assertStringContainsString("source_url_ports", $ex->getMessage());
			}
		}
	}

	// ------------------------------------------------------------ helpers

	private function Fetch(string $path, int $maxBytes = 10_000_000, ?\Closure $checkLength = null, string $host = "fetch.test", ?SafeFetcher $fetcher = null): FetchedFile {
		$fetcher ??= $this->Fetcher();
		$url = SourceUrl::Parse(self::$server->Url($path, $host), $fetcher->Ports());
		return $fetcher->Fetch($url, $maxBytes, $this->dir, $checkLength);
	}

	/** Asserts the failure, and that the download file is gone with it. */
	private function AssertFails(string $path, string $kind, string $reason, ?SafeFetcher $fetcher = null, int $maxBytes = 10_000_000, string $host = "fetch.test"): SourceUrlException {
		try {
			$this->Fetch($path, $maxBytes, null, $host, $fetcher);
			$this->fail("expected ".$kind.": ".$reason);
		} catch(SourceUrlException $ex) {
			$this->assertSame($kind, $ex->kind, $ex->getMessage());
			$this->assertStringContainsString($reason, $ex->getMessage());
			$this->assertSame([], self::Files($this->dir), "the download file is deleted on failure");
			return $ex;
		}
	}

	private function Fetcher(int $timeoutS = 10, int $lowSpeedTimeS = 2): SafeFetcher {
		return new SafeFetcher(self::Resolver(), self::Policy(), [443, self::$server->port], $timeoutS, self::$server->caFile, $lowSpeedTimeS);
	}

	private static function Resolver(): HostResolver {
		return new class implements HostResolver {
			public function Resolve(string $host): ?array {
				if($host === "dnsfail.test") return null;
				return [
					"fetch.test" => ["127.0.0.1"],
					"other.test" => ["127.0.0.1"],
					"nocert.test" => ["127.0.0.1"],
					"mixed.test" => ["127.0.0.1", "10.0.0.1"],
					"private.test" => ["10.0.0.1"],
					"elsewhere.test" => ["127.0.0.2"],
				][$host] ?? [];
			}
		};
	}

	private static function Policy(): AddressPolicy {
		return new class implements AddressPolicy {
			public function IsAllowed(string $ip): bool {
				return $ip === "127.0.0.1" || $ip === "127.0.0.2";
			}
		};
	}

	private static function Files(string $dir): array {
		return array_values(array_diff(scandir($dir), [".", ".."]));
	}

}
