<?php

namespace MagratheaExplorer\Tests\Shared;

use Magrathea\Exceptions\MagratheaApiException;
use MagratheaExplorer\ClientIp;
use MagratheaExplorer\Tests\Support\TestConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Tier 2 §4.4 of the 1.5.0 plan: whose failures the rate limit counts. */
class ClientIpTest extends TestCase {

	protected function tearDown(): void {
		TestConfig::Set();
	}

	public function testRemoteAddrByDefault(): void {
		$server = ["REMOTE_ADDR" => "203.0.113.7", "HTTP_CF_CONNECTING_IP" => "198.51.100.1", "HTTP_X_FORWARDED_FOR" => "198.51.100.2"];
		$this->assertSame("203.0.113.7", ClientIp::Resolve($server, null));
	}

	public function testConfiguredHeaderWins(): void {
		$server = ["REMOTE_ADDR" => "172.70.1.1", "HTTP_CF_CONNECTING_IP" => " 198.51.100.1 "];
		$this->assertSame("198.51.100.1", ClientIp::Resolve($server, "CF-Connecting-IP"));
		$this->assertSame("198.51.100.1", ClientIp::Resolve($server, "cf-connecting-ip"));
	}

	public static function untrustedHeaderValues(): array {
		return [
			"absent" => [null], "empty" => [""], "garbage" => ["not-an-ip"],
			"a list" => ["198.51.100.1, 172.70.1.1"], "with a port" => ["198.51.100.1:443"],
		];
	}

	/** A configured header that doesn't hold one valid IP is ignored, never half-read. */
	#[DataProvider("untrustedHeaderValues")]
	public function testUnusableHeaderFallsBackToRemoteAddr(?string $value): void {
		$server = ["REMOTE_ADDR" => "172.70.1.1"];
		if($value !== null) $server["HTTP_X_REAL_IP"] = $value;
		$this->assertSame("172.70.1.1", ClientIp::Resolve($server, "X-Real-IP"));
	}

	public function testNoRemoteAddrIsUnknown(): void {
		$this->assertSame("unknown", ClientIp::Resolve([], null));
		$this->assertSame("unknown", ClientIp::Resolve(["REMOTE_ADDR" => "localhost"], null));
	}

	public function testIpv6FromTheHeader(): void {
		$this->assertSame("2001:db8::1", ClientIp::Resolve(["HTTP_CF_CONNECTING_IP" => "2001:db8::1"], "CF-Connecting-IP"));
	}

	public static function buckets(): array {
		return [
			"IPv4 as is" => ["203.0.113.7", "203.0.113.7"],
			"IPv6 by its /64" => ["2001:db8:1:2:3:4:5:6", "2001:db8:1:2::/64"],
			"same /64, same bucket" => ["2001:db8:1:2:ffff:ffff:ffff:ffff", "2001:db8:1:2::/64"],
			"compressed IPv6" => ["2001:db8::1", "2001:db8::/64"],
			"IPv4-mapped IPv6 is its IPv4" => ["::ffff:203.0.113.7", "203.0.113.7"],
			"loopback v6" => ["::1", "::/64"],
			"unknown" => ["unknown", "unknown"],
			"garbage" => ["300.1.1.1", "unknown"],
		];
	}

	#[DataProvider("buckets")]
	public function testBucket(string $ip, string $expected): void {
		$this->assertSame($expected, ClientIp::Bucket($ip));
	}

	public function testConfiguredHeader(): void {
		TestConfig::Set([]);
		$this->assertNull(ClientIp::ConfiguredHeader());
		TestConfig::Set(["client_ip_header" => ""]);
		$this->assertNull(ClientIp::ConfiguredHeader());
		TestConfig::Set(["client_ip_header" => " CF-Connecting-IP "]);
		$this->assertSame("CF-Connecting-IP", ClientIp::ConfiguredHeader());
	}

	public function testConfiguredHeaderThatIsntAHeaderNameIs5004(): void {
		TestConfig::Set(["client_ip_header" => "CF Connecting: IP"]);
		try {
			ClientIp::ConfiguredHeader();
			$this->fail("expected 5004");
		} catch(MagratheaApiException $ex) {
			$this->assertSame(5004, $ex->getCode());
			$this->assertStringContainsString("client_ip_header", $ex->getMessage());
		}
	}

}
