<?php

namespace MagratheaExplorer\Tests\File;

use MagratheaExplorer\File\PublicAddressPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Tier 2 §6.2 of the 1.5.0 plan: which addresses a `source_url` may be fetched from. */
class PublicAddressPolicyTest extends TestCase {

	public static function refused(): array {
		return [
			// IPv4, each block at both ends
			"0.0.0.0" => ["0.0.0.0"], "0/8 top" => ["0.255.255.255"],
			"10/8" => ["10.0.0.0"], "10/8 top" => ["10.255.255.255"],
			"CGNAT" => ["100.64.0.0"], "CGNAT top" => ["100.127.255.255"],
			"loopback" => ["127.0.0.1"], "loopback top" => ["127.255.255.255"],
			"metadata" => ["169.254.169.254"], "link-local" => ["169.254.0.0"],
			"172.16/12" => ["172.16.0.0"], "172.16/12 top" => ["172.31.255.255"],
			"192.0.0/24" => ["192.0.0.1"], "TEST-NET-1" => ["192.0.2.10"], "6to4 relay" => ["192.88.99.1"],
			"192.168/16" => ["192.168.1.1"], "192.168/16 top" => ["192.168.255.255"],
			"benchmarking" => ["198.18.0.1"], "benchmarking top" => ["198.19.255.255"],
			"TEST-NET-2" => ["198.51.100.7"], "TEST-NET-3" => ["203.0.113.9"],
			"multicast" => ["224.0.0.1"], "multicast top" => ["239.255.255.255"],
			"class E" => ["240.0.0.1"], "broadcast" => ["255.255.255.255"],
			// IPv6
			"unspecified" => ["::"], "loopback v6" => ["::1"],
			"IPv4-compatible (deprecated)" => ["::127.0.0.1"],
			"unique local" => ["fc00::1"], "unique local fd" => ["fd12:3456::1"],
			"link-local v6" => ["fe80::1"], "site-local (deprecated)" => ["fec0::1"],
			"multicast v6" => ["ff02::1"],
			"discard" => ["100::1"],
			"Teredo" => ["2001::1"], "benchmarking v6" => ["2001:2::1"], "ORCHID" => ["2001:10::1"],
			"2001::/23 top" => ["2001:1ff:ffff::1"],
			"documentation v6" => ["2001:db8::1"], "documentation 3fff" => ["3fff::1"],
			"local NAT64" => ["64:ff9b:1::1"],
			"outside 2000::/3" => ["4000::1"], "SRv6" => ["5f00::1"],
			// IPv6 carrying a refused IPv4
			"mapped loopback" => ["::ffff:127.0.0.1"], "mapped metadata" => ["::ffff:169.254.169.254"],
			"mapped private" => ["::ffff:10.1.2.3"],
			"NAT64 loopback" => ["64:ff9b::7f00:1"], "NAT64 private" => ["64:ff9b::c0a8:101"],
			"6to4 loopback" => ["2002:7f00:1::1"], "6to4 private" => ["2002:c0a8:101::1"],
			// not addresses
			"garbage" => ["not-an-ip"], "empty" => [""], "zone id" => ["fe80::1%eth0"],
		];
	}

	#[DataProvider("refused")]
	public function testRefused(string $ip): void {
		$this->assertFalse((new PublicAddressPolicy())->IsAllowed($ip));
	}

	public static function allowed(): array {
		return [
			// just outside each IPv4 block
			"1.0.0.1" => ["1.0.0.1"], "9.255.255.255" => ["9.255.255.255"], "11.0.0.0" => ["11.0.0.0"],
			"100.63.255.255" => ["100.63.255.255"], "100.128.0.0" => ["100.128.0.0"],
			"126.255.255.255" => ["126.255.255.255"], "128.0.0.0" => ["128.0.0.0"],
			"169.253.255.255" => ["169.253.255.255"], "169.255.0.0" => ["169.255.0.0"],
			"172.15.255.255" => ["172.15.255.255"], "172.32.0.0" => ["172.32.0.0"],
			"192.0.1.0" => ["192.0.1.0"], "192.0.3.0" => ["192.0.3.0"],
			"192.167.255.255" => ["192.167.255.255"], "192.169.0.0" => ["192.169.0.0"],
			"198.17.255.255" => ["198.17.255.255"], "198.20.0.0" => ["198.20.0.0"],
			"223.255.255.255" => ["223.255.255.255"],
			"8.8.8.8" => ["8.8.8.8"], "Cloudflare" => ["104.16.0.1"],
			// IPv6 global unicast
			"Google DNS v6" => ["2001:4860:4860::8888"], "Cloudflare v6" => ["2606:4700::6810:84e5"],
			"just above 2001::/23" => ["2001:200::1"], "just below 2001:db8::/32" => ["2001:db7:ffff::1"],
			"just above 2001:db8::/32" => ["2001:db9::1"], "2000::1" => ["2000::1"], "3ffe::1" => ["3ffe::1"],
			// carrying a public IPv4
			"mapped public" => ["::ffff:8.8.8.8"], "NAT64 public" => ["64:ff9b::808:808"], "6to4 public" => ["2002:808:808::1"],
		];
	}

	#[DataProvider("allowed")]
	public function testAllowed(string $ip): void {
		$this->assertTrue((new PublicAddressPolicy())->IsAllowed($ip));
	}

	public function testInCidr(): void {
		$this->assertTrue(PublicAddressPolicy::InCidr(inet_pton("172.31.0.1"), "172.16.0.0/12"));
		$this->assertFalse(PublicAddressPolicy::InCidr(inet_pton("172.32.0.1"), "172.16.0.0/12"));
		$this->assertTrue(PublicAddressPolicy::InCidr(inet_pton("8.8.8.8"), "0.0.0.0/0"));
		$this->assertTrue(PublicAddressPolicy::InCidr(inet_pton("8.8.8.8"), "8.8.8.8/32"));
		$this->assertFalse(PublicAddressPolicy::InCidr(inet_pton("8.8.8.9"), "8.8.8.8/32"));
		$this->assertTrue(PublicAddressPolicy::InCidr(inet_pton("fe80::1"), "fe80::/10"));
		$this->assertTrue(PublicAddressPolicy::InCidr(inet_pton("febf::1"), "fe80::/10"));
		$this->assertFalse(PublicAddressPolicy::InCidr(inet_pton("fec0::1"), "fe80::/10"));
		// the other family never matches, whatever the bytes
		$this->assertFalse(PublicAddressPolicy::InCidr(inet_pton("127.0.0.1"), "::/0"));
		$this->assertFalse(PublicAddressPolicy::InCidr(inet_pton("::1"), "0.0.0.0/0"));
	}

	/** Every CIDR in the tables parses: a typo there would silently allow the block. */
	public function testTablesParse(): void {
		$cidrs = array_merge(PublicAddressPolicy::IPV4_BLOCKED, PublicAddressPolicy::IPV6_BLOCKED,
			[PublicAddressPolicy::IPV6_GLOBAL], array_keys(PublicAddressPolicy::IPV6_EMBEDDED_IPV4));
		foreach($cidrs as $cidr) {
			[$network, $bits] = explode("/", $cidr);
			$bytes = inet_pton($network);
			$this->assertNotFalse($bytes, $cidr);
			$this->assertLessThanOrEqual(strlen($bytes) * 8, (int)$bits, $cidr);
			$this->assertTrue(PublicAddressPolicy::InCidr($bytes, $cidr), $cidr." contains its own network address");
		}
	}

}
