<?php

namespace MagratheaExplorer\File;

/**
 * A `source_url` (or a redirect's Location) that passed the URL rules, rebuilt from its checked
 * parts. SafeFetcher hands curl ToString(), never the caller's string: curl then sees exactly
 * the host that was checked, which closes the parser-differential tricks (`https://a\@b/`,
 * `https://a#@b/`) where the checker and curl read different hosts out of one URL.
 *
 * The rules (40012 on any of them, via SourceUrlException::Refused()):
 * - `https://` only; no user name or password;
 * - the host is a DNS name with a domain (letters, digits, hyphens, dots; an IDN goes through
 *   idn_to_ascii() when ext-intl is loaded), a dotted-quad IPv4 or a bracketed IPv6. `localhost`
 *   and `*.localhost` are refused by name, and so are single-label names (`intranet`): no public
 *   web host has one;
 * - IPv4 only as four decimal numbers: `2130706433`, `0x7f.1`, `127.1` and `017.0.0.1` are all
 *   refused (curl and browsers would read them as 127.0.0.1);
 * - port 443, or one magrathea.conf `source_url_ports` adds.
 *
 * Whether the address is public is SafeFetcher's (it resolves the name first). The path and
 * query keep what was sent, with dot segments removed and bytes outside RFC 3986 (spaces,
 * non-ASCII) percent-encoded. The fragment is dropped: it's never sent to a server.
 */
final class SourceUrl {

	const DEFAULT_PORT = 443;
	const MAX_LENGTH = 8192;

	private const PATH_CHARS = "A-Za-z0-9\\-._~!$&'()*+,;=:@\\/%";
	private const IPV4_RULE = "an IPv4 address has to be four decimal numbers, like 93.184.216.34";

	/**
	 * @param string $host lowercase ASCII DNS name, or a canonical IP address (IPv6 without brackets)
	 * @param string $path percent-encoded, "/" at least
	 * @param ?string $query without its "?", null when there's none
	 */
	private function __construct(
		public readonly string $host,
		public readonly bool $isIp,
		public readonly int $port,
		public readonly string $path,
		public readonly ?string $query,
	) {}

	/**
	 * @param int[] $allowedPorts the ports a URL may name (SafeFetcher::ConfiguredPorts())
	 * @throws SourceUrlException REFUSED, with the rule it breaks
	 */
	public static function Parse(string $raw, array $allowedPorts): self {
		$url = trim($raw, " \t\r\n");
		if($url === "") throw SourceUrlException::Refused("empty");
		if(strlen($url) > self::MAX_LENGTH) throw SourceUrlException::Refused("longer than ".self::MAX_LENGTH." characters");
		if(preg_match('/[\x00-\x1F\x7F]/', $url) === 1) throw SourceUrlException::Refused("contains control characters");
		if(!mb_check_encoding($url, "UTF-8")) throw SourceUrlException::Refused("not valid UTF-8");
		if(str_contains($url, "\\")) throw SourceUrlException::Refused("contains a backslash");

		if(preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):/', $url, $m) !== 1) {
			throw SourceUrlException::Refused("not an absolute https:// URL");
		}
		if(strtolower($m[1]) !== "https") throw SourceUrlException::Refused("only https URLs are accepted");
		$rest = substr($url, strlen($m[0]));
		if(!str_starts_with($rest, "//")) throw SourceUrlException::Refused("not an absolute https:// URL");
		$rest = substr($rest, 2);

		$authorityLength = strcspn($rest, "/?#");
		$authority = substr($rest, 0, $authorityLength);
		$rest = substr($rest, $authorityLength);
		if(str_contains($authority, "@")) throw SourceUrlException::Refused("user names and passwords aren't accepted");

		[$hostPart, $portPart, $bracketed] = self::SplitAuthority($authority);
		$port = self::ParsePort($portPart, $allowedPorts);
		[$host, $isIp] = $bracketed ? [self::ParseIpv6Literal($hostPart), true] : self::ParseHostName($hostPart);

		$fragmentAt = strpos($rest, "#");
		if($fragmentAt !== false) $rest = substr($rest, 0, $fragmentAt);
		$queryAt = strpos($rest, "?");
		$path = $queryAt === false ? $rest : substr($rest, 0, $queryAt);
		$query = $queryAt === false ? null : substr($rest, $queryAt + 1);

		$path = self::RemoveDotSegments(self::Encode($path === "" ? "/" : $path, ""));
		if($path === "") $path = "/";
		if($query !== null) $query = self::Encode($query, "?");

		return new self($host, $isIp, $port, $path, $query);
	}

	/** The URL as curl gets it: `https://host[:port]/path[?query]`. Also what an Idempotency-Key's request hash takes. */
	public function ToString(): string {
		return "https://".$this->Authority().$this->path.($this->query !== null ? "?".$this->query : "");
	}

	/** `host[:port]`, an IPv6 address in brackets, the port only when it isn't 443. */
	public function Authority(): string {
		$host = $this->isIp && str_contains($this->host, ":") ? "[".$this->host."]" : $this->host;
		return $this->port === self::DEFAULT_PORT ? $host : $host.":".$this->port;
	}

	/** The path's last segment, still percent-encoded. "" when the path ends in "/". */
	public function LastSegment(): string {
		$slash = strrpos($this->path, "/");
		return $slash === false ? $this->path : substr($this->path, $slash + 1);
	}

	/**
	 * A redirect's Location, resolved against this URL (RFC 3986 §5.2: absolute, `//host/…`,
	 * `/path`, `?query`, `relative/path`, `../up`), then held to every rule again.
	 * @throws SourceUrlException REFUSED
	 */
	public function Resolve(string $location, array $allowedPorts): self {
		$location = trim($location, " \t");
		if(preg_match('/^[A-Za-z][A-Za-z0-9+.\-]*:/', $location) === 1) return self::Parse($location, $allowedPorts);
		if(str_starts_with($location, "//")) return self::Parse("https:".$location, $allowedPorts);
		$base = "https://".$this->Authority();
		if($location === "" || $location[0] === "#") return self::Parse($this->ToString(), $allowedPorts);
		if($location[0] === "/") return self::Parse($base.$location, $allowedPorts);
		if($location[0] === "?") return self::Parse($base.$this->path.$location, $allowedPorts);
		$directory = substr($this->path, 0, strrpos($this->path, "/") + 1);
		return self::Parse($base.$directory.$location, $allowedPorts);
	}

	/** @return array{string, ?string, bool} the host, the port as written (null = none), whether the host was bracketed */
	private static function SplitAuthority(string $authority): array {
		if(str_starts_with($authority, "[")) {
			$close = strpos($authority, "]");
			if($close === false) throw SourceUrlException::Refused("invalid IPv6 address");
			$after = substr($authority, $close + 1);
			if($after !== "" && !str_starts_with($after, ":")) throw SourceUrlException::Refused("invalid host");
			return [substr($authority, 1, $close - 1), $after === "" ? null : substr($after, 1), true];
		}
		$colon = strrpos($authority, ":");
		if($colon === false) return [$authority, null, false];
		return [substr($authority, 0, $colon), substr($authority, $colon + 1), false];
	}

	private static function ParsePort(?string $raw, array $allowedPorts): int {
		if($raw === null) {
			$port = self::DEFAULT_PORT;
		} else {
			if(preg_match('/^\d{1,5}$/', $raw) !== 1 || (int)$raw < 1 || (int)$raw > 65535) {
				throw SourceUrlException::Refused("invalid port");
			}
			$port = (int)$raw;
		}
		if(!in_array($port, $allowedPorts, true)) {
			throw SourceUrlException::Refused("port ".$port." isn't allowed (allowed: ".implode(", ", $allowedPorts).")");
		}
		return $port;
	}

	private static function ParseIpv6Literal(string $raw): string {
		if(str_contains($raw, "%")) throw SourceUrlException::Refused("IPv6 zone identifiers aren't accepted");
		if(filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) throw SourceUrlException::Refused("invalid IPv6 address");
		return inet_ntop(inet_pton($raw));
	}

	/** @return array{string, bool} the host and whether it's an IPv4 address */
	private static function ParseHostName(string $raw): array {
		$host = $raw;
		if(preg_match('/[^\x00-\x7F]/', $host) === 1) $host = self::IdnToAscii($host);
		$host = strtolower($host);
		if(str_ends_with($host, ".")) $host = substr($host, 0, -1); // a fully qualified name: same host
		if($host === "") throw SourceUrlException::Refused("no host");
		if(strlen($host) > 253) throw SourceUrlException::Refused("host name too long");

		$labels = explode(".", $host);
		foreach($labels as $label) {
			if(preg_match('/^[a-z0-9-]{1,63}$/', $label) !== 1) throw SourceUrlException::Refused("invalid host name");
		}
		// Ends in a number: browsers and curl read the whole host as an IPv4 address, in any of
		// its old forms (decimal, octal, hex, fewer than four parts). Only the dotted quad passes.
		if(preg_match('/^(\d+|0x[0-9a-f]*)$/', end($labels)) === 1) {
			if(count($labels) !== 4) throw SourceUrlException::Refused(self::IPV4_RULE);
			foreach($labels as $label) {
				if(preg_match('/^(0|[1-9]\d{0,2})$/', $label) !== 1 || (int)$label > 255) throw SourceUrlException::Refused(self::IPV4_RULE);
			}
			return [$host, true];
		}
		if($host === "localhost" || str_ends_with($host, ".localhost")) throw SourceUrlException::Refused("localhost isn't accepted");
		if(count($labels) < 2) throw SourceUrlException::Refused("the host needs a domain (single-label names aren't accepted)");
		return [$host, false];
	}

	private static function IdnToAscii(string $host): string {
		if(!function_exists("idn_to_ascii")) {
			throw SourceUrlException::Refused("international host names aren't supported on this server: send it as punycode (xn--…)");
		}
		$ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_USE_STD3_RULES, INTL_IDNA_VARIANT_UTS46);
		if($ascii === false || $ascii === "") throw SourceUrlException::Refused("invalid international host name");
		return $ascii;
	}

	/** Percent-encodes every byte outside RFC 3986's path characters (plus $extra), `%` left as sent. Bytewise: UTF-8 becomes %XX%XX. */
	private static function Encode(string $value, string $extra): string {
		return preg_replace_callback('/[^'.self::PATH_CHARS.$extra.']/', fn($m) => sprintf("%%%02X", ord($m[0])), $value);
	}

	/** RFC 3986 §5.2.4: `/a/./b/../c` → `/a/c`, never above the root. curl would do the same on its side. */
	private static function RemoveDotSegments(string $path): string {
		$output = "";
		while($path !== "") {
			if(str_starts_with($path, "../")) {
				$path = substr($path, 3);
			} elseif(str_starts_with($path, "./") || str_starts_with($path, "/./")) {
				$path = substr($path, 2); // "./x" → "x", "/./x" → "/x"
			} elseif($path === "/.") {
				$path = "/";
			} elseif(str_starts_with($path, "/../") || $path === "/..") {
				$path = $path === "/.." ? "/" : substr($path, 3);
				$slash = strrpos($output, "/");
				$output = $slash === false ? "" : substr($output, 0, $slash);
			} elseif($path === "." || $path === "..") {
				$path = "";
			} else {
				$next = strpos($path, "/", $path[0] === "/" ? 1 : 0);
				$output .= $next === false ? $path : substr($path, 0, $next);
				$path = $next === false ? "" : substr($path, $next);
			}
		}
		return $output;
	}

}
