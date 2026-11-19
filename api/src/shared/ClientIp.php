<?php

namespace MagratheaExplorer;

use Magrathea\Config;

/**
 * Who a request comes from, for the rate limit on failed authentications and for logs.
 *
 * REMOTE_ADDR by default. Behind Cloudflare or another proxy that's the proxy, so every client
 * would share one bucket and anyone could lock everyone out: magrathea.conf `client_ip_header`
 * names the header the proxy puts the client's IP in (`CF-Connecting-IP`, `X-Real-IP`). It's
 * trusted only when it's set, and only when it holds exactly one valid IP. A list
 * (`X-Forwarded-For: a, b`) is refused: its left end is whatever the client wrote.
 * Setting it is only safe when every request reaches PHP through that proxy: a client that can
 * reach the origin directly can send the header itself.
 */
final class ClientIp {

	public static function FromRequest(): string {
		return self::Resolve($_SERVER, self::ConfiguredHeader());
	}

	/** magrathea.conf `client_ip_header`. Absent or empty = null (REMOTE_ADDR only). Not a header name → 5004. */
	public static function ConfiguredHeader(): ?string {
		$raw = Config::Instance()->Get("client_ip_header");
		if($raw === null || trim((string)$raw) === "") return null;
		$header = trim((string)$raw);
		if(preg_match('/^[A-Za-z0-9-]+$/', $header) !== 1) {
			ErrorCodes::Instance()->ThrowException(5004, null, "magrathea.conf `client_ip_header`");
		}
		return $header;
	}

	/**
	 * The client IP from $server ($_SERVER): the configured header when it holds one valid IP,
	 * else REMOTE_ADDR, else "unknown" (the CLI).
	 */
	public static function Resolve(array $server, ?string $header): string {
		if($header !== null) {
			$value = $server["HTTP_".strtoupper(str_replace("-", "_", $header))] ?? null;
			if(is_string($value) && filter_var(trim($value), FILTER_VALIDATE_IP) !== false) return trim($value);
		}
		$remote = $server["REMOTE_ADDR"] ?? null;
		if(is_string($remote) && filter_var($remote, FILTER_VALIDATE_IP) !== false) return $remote;
		return "unknown";
	}

	/**
	 * What failures are counted by: an IPv4 address as is, an IPv6 address by its /64 (a single
	 * host is usually given a whole /64, so counting per address would count nothing), an
	 * IPv4-mapped IPv6 address (`::ffff:1.2.3.4`) as its IPv4. Anything else as "unknown".
	 */
	public static function Bucket(string $ip): string {
		$packed = @inet_pton($ip);
		if($packed === false) return "unknown";
		if(strlen($packed) === 4) return inet_ntop($packed);
		if(substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") return inet_ntop(substr($packed, 12));
		return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8))."/64";
	}

}
