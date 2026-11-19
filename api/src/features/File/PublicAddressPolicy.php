<?php

namespace MagratheaExplorer\File;

/**
 * "Public" for a `source_url` fetch: one table in code, not filter_var()'s flags, whose coverage
 * changes between PHP versions (8.1, the floor, has no FILTER_FLAG_GLOBAL_RANGE).
 *
 * - IPv4: everything but the blocks below (RFC 6890's special-purpose registry, the ones that
 *   aren't globally reachable, plus multicast and the old class E).
 * - IPv6: **only global unicast, 2000::/3**, minus the special blocks inside it. An allowlist,
 *   not the denylist the plan had: whatever IANA reserves outside 2000::/3 later is refused
 *   without a code change.
 * - IPv6 that embeds an IPv4 address (IPv4-mapped ::ffff:0:0/96, NAT64 64:ff9b::/96, 6to4
 *   2002::/16) is judged as that IPv4 address: `::ffff:127.0.0.1` is loopback.
 */
final class PublicAddressPolicy implements AddressPolicy {

	const IPV4_BLOCKED = [
		"0.0.0.0/8",        // "this network"
		"10.0.0.0/8",       // private
		"100.64.0.0/10",    // carrier-grade NAT
		"127.0.0.0/8",      // loopback
		"169.254.0.0/16",   // link-local, cloud metadata (169.254.169.254)
		"172.16.0.0/12",    // private
		"192.0.0.0/24",     // IETF protocol assignments
		"192.0.2.0/24",     // documentation (TEST-NET-1)
		"192.88.99.0/24",   // 6to4 relay anycast (deprecated)
		"192.168.0.0/16",   // private
		"198.18.0.0/15",    // benchmarking
		"198.51.100.0/24",  // documentation (TEST-NET-2)
		"203.0.113.0/24",   // documentation (TEST-NET-3)
		"224.0.0.0/4",      // multicast
		"240.0.0.0/4",      // reserved, 255.255.255.255 included
	];

	const IPV6_GLOBAL = "2000::/3";

	/** Inside 2000::/3, but not a host to fetch from. */
	const IPV6_BLOCKED = [
		"2001::/23",        // IETF protocol assignments: Teredo (2001::/32), benchmarking, ORCHID
		"2001:db8::/32",    // documentation
		"3fff::/20",        // documentation (RFC 9637)
	];

	/** prefix => offset of the embedded IPv4 address, in bytes */
	const IPV6_EMBEDDED_IPV4 = [
		"::ffff:0:0/96" => 12, // IPv4-mapped
		"64:ff9b::/96" => 12,  // NAT64, well-known prefix
		"2002::/16" => 2,      // 6to4
	];

	public function IsAllowed(string $ip): bool {
		$bytes = @inet_pton($ip);
		if($bytes === false) return false;
		return strlen($bytes) === 4 ? self::Ipv4Allowed($bytes) : self::Ipv6Allowed($bytes);
	}

	private static function Ipv4Allowed(string $bytes): bool {
		foreach(self::IPV4_BLOCKED as $cidr) {
			if(self::InCidr($bytes, $cidr)) return false;
		}
		return true;
	}

	private static function Ipv6Allowed(string $bytes): bool {
		foreach(self::IPV6_EMBEDDED_IPV4 as $cidr => $offset) {
			if(self::InCidr($bytes, $cidr)) return self::Ipv4Allowed(substr($bytes, $offset, 4));
		}
		if(!self::InCidr($bytes, self::IPV6_GLOBAL)) return false;
		foreach(self::IPV6_BLOCKED as $cidr) {
			if(self::InCidr($bytes, $cidr)) return false;
		}
		return true;
	}

	/** $bytes (inet_pton()) inside $cidr. An address of the other family never is. */
	public static function InCidr(string $bytes, string $cidr): bool {
		[$network, $bits] = explode("/", $cidr, 2);
		$prefix = inet_pton($network);
		if($prefix === false || strlen($prefix) !== strlen($bytes)) return false;
		$bits = (int)$bits;
		$whole = intdiv($bits, 8);
		if(substr($bytes, 0, $whole) !== substr($prefix, 0, $whole)) return false;
		$rest = $bits % 8;
		if($rest === 0) return true;
		$mask = (0xFF << (8 - $rest)) & 0xFF;
		return (ord($bytes[$whole]) & $mask) === (ord($prefix[$whole]) & $mask);
	}

}
