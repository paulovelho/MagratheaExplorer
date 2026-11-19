<?php

namespace MagratheaExplorer\File;

use Magrathea\Config;
use Magrathea\MagratheaPHP;
use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\ExplorerConfig;
use MagratheaExplorer\RequestBody;

/**
 * Downloads a `source_url` for `POST /files` without letting the caller point the server at
 * anything it shouldn't reach (SSRF). Tier 2 plan §6.2; ext-curl, one handle per hop.
 *
 * - **The URL rules** are SourceUrl's: https, no userinfo, a real host, port 443 (or one
 *   `source_url_ports` adds).
 * - **Every address the name resolves to must be allowed** (PublicAddressPolicy): one private
 *   answer among public ones refuses the URL.
 * - **curl connects only to those addresses:** CURLOPT_RESOLVE pins the name, so it never
 *   resolves it again (no DNS rebinding between the check and the connection). TLS still checks
 *   the certificate against the host name. The connected address is checked again before the
 *   request is sent (CURLOPT_PREREQFUNCTION, PHP 8.4 + libcurl 7.80) and when the headers
 *   arrive (every version).
 * - **Redirects by hand,** at most 3, each Location held to every rule again. curl's own
 *   following is off, and HTTPS is its only protocol, redirects included.
 * - **Size:** a Content-Length over the limit is refused before the body; the body is counted
 *   as it arrives and cut at the limit either way. `Accept-Encoding: identity`, and a response
 *   with any other Content-Encoding is refused, so the bytes counted are the bytes stored.
 * - **Time:** 10 s to connect, `source_url_timeout_s` (90) for everything, redirects included,
 *   and at least 1 KB/s over any 30 s.
 * - **Nothing is forwarded:** no cookies, no credentials, no proxy (the environment's
 *   `https_proxy` included), a fixed User-Agent.
 *
 * The body is streamed into a file in `processing_tmp_path` (inside the account), which the
 * caller owns once Fetch() returns, until the end of the request: a failure deletes it, and so
 * does a shutdown function if it's still there (a PHP fatal skips every `finally`). Only a
 * killed process leaves one behind, and SweepStale() (`cron.php --process`) removes it.
 */
final class SafeFetcher {

	const MAX_REDIRECTS = 3;
	const CONNECT_TIMEOUT_S = 10;
	const DEFAULT_TIMEOUT_S = 90;
	const LOW_SPEED_BYTES_PER_S = 1024;
	const LOW_SPEED_TIME_S = 30;
	const REDIRECT_STATUSES = [301, 302, 303, 307, 308];
	/** A GET without Range has no business getting a 206 (part of a file) or a 204 (none). */
	const OK_STATUSES = [200, 203];
	const FILE_PREFIX = "explorer_fetch_";
	/** A download older than this is no request's any more: SweepStale() removes it. */
	const STALE_AFTER_S = 86400;
	const SWEEP_MAX_FILES = 1000;

	/**
	 * Every argument defaults to production: tests inject a resolver and a policy that allow
	 * loopback, a local server's port and its CA.
	 * @param ?int[] $allowedPorts null = magrathea.conf (ConfiguredPorts())
	 * @param ?int $timeoutS null = magrathea.conf (ConfiguredTimeoutS())
	 */
	public function __construct(
		private readonly HostResolver $resolver = new DnsHostResolver(),
		private readonly AddressPolicy $policy = new PublicAddressPolicy(),
		private readonly ?array $allowedPorts = null,
		private readonly ?int $timeoutS = null,
		private readonly ?string $caInfo = null,
		private readonly int $lowSpeedTimeS = self::LOW_SPEED_TIME_S,
	) {}

	/** magrathea.conf `source_url_enabled`: uploads from a URL are opt-in. Absent = off. */
	public static function IsEnabled(): bool {
		return ExplorerConfig::GetBool("source_url_enabled", false);
	}

	/** magrathea.conf `source_url_timeout_s`: the whole fetch, redirects included. Absent = 90, under Cloudflare's 100 s. */
	public static function ConfiguredTimeoutS(): int {
		return ExplorerConfig::GetPositiveInt("source_url_timeout_s", self::DEFAULT_TIMEOUT_S);
	}

	/** 443, plus magrathea.conf `source_url_ports` (comma-separated). Anything but port numbers there → 5004. */
	public static function ConfiguredPorts(): array {
		$ports = [SourceUrl::DEFAULT_PORT];
		$raw = Config::Instance()->Get("source_url_ports");
		if($raw === null || trim((string)$raw) === "") return $ports;
		foreach(explode(",", (string)$raw) as $item) {
			$item = trim($item);
			if(preg_match('/^\d{1,5}$/', $item) !== 1 || (int)$item < 1 || (int)$item > 65535) {
				ErrorCodes::Instance()->ThrowException(5004, null, "magrathea.conf `source_url_ports`");
			}
			$ports[] = (int)$item;
		}
		return array_values(array_unique($ports));
	}

	/** @return int[] */
	public function Ports(): array {
		return $this->allowedPorts ?? self::ConfiguredPorts();
	}

	public function TimeoutS(): int {
		return $this->timeoutS ?? self::ConfiguredTimeoutS();
	}

	/**
	 * @param int $maxBytes the key's effective upload limit
	 * @param string $targetDir where the body goes: ExplorerConfig::ProcessingTmpPath()
	 * @param ?\Closure $checkLength called with the final response's Content-Length, when it has
	 *                  one, before the body is read. Throw to refuse it (FileApi: the key's quota).
	 * @throws SourceUrlException REFUSED, FAILED, TOO_LARGE; whatever $checkLength throws
	 */
	public function Fetch(SourceUrl $url, int $maxBytes, string $targetDir, ?\Closure $checkLength = null): FetchedFile {
		$deadline = microtime(true) + $this->TimeoutS();
		$path = @tempnam($targetDir, self::FILE_PREFIX);
		// tempnam() falls back to the system's temp dir, with a mere notice, when $targetDir is
		// unusable: never let a download land in the shared /tmp.
		if($path !== false && dirname($path) !== realpath($targetDir)) {
			@unlink($path);
			$path = false;
		}
		if($path === false) {
			ErrorCodes::Instance()->ThrowException(5001, null, "could not create the download file");
		}
		register_shutdown_function(function() use ($path) {
			if(is_file($path)) @unlink($path);
		});
		try {
			$current = $url;
			for($redirects = 0; ; $redirects++) {
				$hop = $this->Hop($current, $maxBytes, $path, $deadline, $checkLength);
				if($hop["location"] === null) {
					clearstatcache(true, $path);
					return new FetchedFile($path, (int)filesize($path), $hop["disposition"], $current);
				}
				if($redirects >= self::MAX_REDIRECTS) {
					throw SourceUrlException::Refused("more than ".self::MAX_REDIRECTS." redirects");
				}
				try {
					$current = $current->Resolve($hop["location"], $this->Ports());
				} catch(SourceUrlException $ex) {
					throw SourceUrlException::Refused("redirect: ".$ex->getMessage());
				}
			}
		} catch(\Throwable $ex) {
			@unlink($path);
			throw $ex;
		}
	}

	/**
	 * Downloads left in $dir by a request that was killed (SIGKILL, a host crash): files named
	 * like Fetch()'s, untouched for a day. At most SWEEP_MAX_FILES a run.
	 * @return int how many were deleted
	 */
	public static function SweepStale(string $dir, ?int $now = null): int {
		$now ??= time();
		$deleted = 0;
		foreach(glob(rtrim($dir, "/")."/".self::FILE_PREFIX."*", GLOB_NOSORT) ?: [] as $path) {
			if($deleted >= self::SWEEP_MAX_FILES) break;
			if(!is_file($path) || is_link($path)) continue;
			$mtime = @filemtime($path);
			if($mtime !== false && $now - $mtime > self::STALE_AFTER_S && @unlink($path)) $deleted++;
		}
		return $deleted;
	}

	/** A name with no addresses and a name with a non-public one get this same refusal. */
	const NOT_PUBLIC_HOST = "the host doesn't resolve to a public address";

	/**
	 * The addresses $url may be fetched from: its IP, or every address its name resolves to, all
	 * of them allowed.
	 *
	 * Nothing here tells the caller about the server's network. The reason never names an
	 * address, and a name that doesn't exist gets the same refusal as one that resolves to a
	 * private address: otherwise "doesn't exist" vs "is private" would let a key holder probe
	 * which internal names the server's resolver knows. Only a lookup that failed (a timeout, a
	 * SERVFAIL) is a 5021, which a retry can get past, and that says nothing about the name.
	 * @return string[] canonical (inet_ntop) form
	 */
	public function Addresses(SourceUrl $url): array {
		$addresses = $url->isIp ? [$url->host] : $this->resolver->Resolve($url->host);
		if($addresses === null) throw SourceUrlException::Failed("DNS lookup failed for ".$url->host);
		if($addresses === []) throw SourceUrlException::Refused(self::NOT_PUBLIC_HOST);
		$canonical = [];
		foreach($addresses as $ip) {
			$bytes = @inet_pton($ip);
			if($bytes === false || !$this->policy->IsAllowed($ip)) {
				throw SourceUrlException::Refused($url->isIp ? "not a public address" : self::NOT_PUBLIC_HOST);
			}
			$canonical[] = inet_ntop($bytes);
		}
		return array_values(array_unique($canonical));
	}

	/** @return array{location: ?string, disposition: ?string} location = a redirect to follow */
	private function Hop(SourceUrl $url, int $maxBytes, string $path, float $deadline, ?\Closure $checkLength): array {
		$addresses = $this->Addresses($url);
		$remainingMs = (int)ceil(($deadline - microtime(true)) * 1000);
		if($remainingMs <= 0) throw SourceUrlException::Failed("timed out after ".$this->TimeoutS()." s");

		$out = @fopen($path, "wb"); // truncated: each hop starts from an empty file
		if($out === false) {
			ErrorCodes::Instance()->ThrowException(5001, null, "could not write the download file");
		}
		$state = new class {
			public int $status = 0;
			/** @var array<string, string> this response's headers, lowercase names, the first of each */
			public array $headers = [];
			public ?string $location = null;
			public ?string $disposition = null;
			public bool $readingBody = false;
			public ?int $contentLength = null;
			public int $bytes = 0;
			public ?\Throwable $abort = null;
		};

		$ch = curl_init();
		$options = [
			CURLOPT_URL => $url->ToString(),
			CURLOPT_HTTPGET => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_MAXREDIRS => 0,
			CURLOPT_PROXY => "",
			CURLOPT_NOPROXY => "*",
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_NOSIGNAL => true,
			CURLOPT_CONNECTTIMEOUT_MS => min(self::CONNECT_TIMEOUT_S * 1000, $remainingMs),
			CURLOPT_TIMEOUT_MS => $remainingMs,
			CURLOPT_LOW_SPEED_LIMIT => self::LOW_SPEED_BYTES_PER_S,
			CURLOPT_LOW_SPEED_TIME => $this->lowSpeedTimeS,
			CURLOPT_USERAGENT => "MagratheaExplorer/".MagratheaPHP::Instance()->AppVersion(),
			CURLOPT_HTTPHEADER => ["Accept: */*", "Accept-Encoding: identity"],
			CURLOPT_HEADERFUNCTION => function($ch, string $line) use ($state, $addresses, $maxBytes, $checkLength): int {
				try {
					return $this->OnHeaderLine($ch, $line, $state, $addresses, $maxBytes, $checkLength) ? strlen($line) : 0;
				} catch(\Throwable $ex) {
					$state->abort = $ex;
					return 0; // anything but strlen($line) aborts the transfer
				}
			},
			CURLOPT_WRITEFUNCTION => function($ch, string $data) use ($state, $out, $maxBytes): int {
				$length = strlen($data);
				if(!$state->readingBody) return 0;
				if($state->bytes + $length > $maxBytes) {
					$state->abort = SourceUrlException::TooLarge($state->contentLength);
					return 0;
				}
				if(fwrite($out, $data) !== $length) {
					$state->abort = SourceUrlException::Failed("could not write the download file");
					return 0;
				}
				$state->bytes += $length;
				return $length;
			},
		];
		if(defined("CURLOPT_PROTOCOLS_STR")) {
			$options[CURLOPT_PROTOCOLS_STR] = "https";
			$options[CURLOPT_REDIR_PROTOCOLS_STR] = "https";
		} else {
			$options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
			$options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
		}
		// An IP literal isn't resolved at all; a name is pinned to the addresses just checked.
		if(!$url->isIp) {
			$pinned = array_map(fn($ip) => str_contains($ip, ":") ? "[".$ip."]" : $ip, $addresses);
			$options[CURLOPT_RESOLVE] = [$url->host.":".$url->port.":".implode(",", $pinned)];
		}
		if($this->caInfo !== null) $options[CURLOPT_CAINFO] = $this->caInfo;
		if(defined("CURLOPT_PREREQFUNCTION")) {
			// after the connection, before the request is sent
			$options[CURLOPT_PREREQFUNCTION] = function($ch, string $primaryIp) use ($addresses): int {
				return self::IsOneOf($primaryIp, $addresses) ? CURL_PREREQFUNC_OK : CURL_PREREQFUNC_ABORT;
			};
		}
		curl_setopt_array($ch, $options);

		curl_exec($ch);
		$errno = curl_errno($ch);
		$reason = $errno === 0 ? null : $this->CurlReason($ch, $errno, $state->status, $deadline);
		unset($ch);
		fclose($out);

		if($state->abort !== null) throw $state->abort;
		if($state->location !== null) return ["location" => $state->location, "disposition" => null];
		if($reason !== null) throw SourceUrlException::Failed($reason);
		if(!$state->readingBody) throw SourceUrlException::Failed("no response");
		return ["location" => null, "disposition" => $state->disposition];
	}

	/**
	 * One header line of the response. At the blank line that ends a final response's headers:
	 * a redirect stops the transfer (false), anything that isn't a 200 or 203 is refused, and so
	 * is a size over the limit; otherwise the body is read (true).
	 */
	private function OnHeaderLine($ch, string $line, object $state, array $addresses, int $maxBytes, ?\Closure $checkLength): bool {
		$trimmed = rtrim($line, "\r\n");
		if(preg_match('#^HTTP/\S+\s+(\d{3})#', $trimmed, $m) === 1) {
			// a status line starts a response: a 1xx is followed by another one
			$state->status = (int)$m[1];
			$state->headers = [];
			return true;
		}
		if($trimmed !== "") {
			$colon = strpos($trimmed, ":");
			if($colon !== false) $state->headers[strtolower(trim(substr($trimmed, 0, $colon)))] ??= trim(substr($trimmed, $colon + 1));
			return true;
		}
		if($state->status < 200) return true;

		// The tripwire behind CURLOPT_RESOLVE: on a libcurl without PREREQFUNCTION this is the
		// first point the connected address can be read.
		if(!self::IsOneOf((string)curl_getinfo($ch, CURLINFO_PRIMARY_IP), $addresses)) {
			throw SourceUrlException::Failed("the connection wasn't made to the checked address");
		}
		if(in_array($state->status, self::REDIRECT_STATUSES, true)) {
			$location = $state->headers["location"] ?? "";
			if($location === "") throw SourceUrlException::Failed("upstream ".$state->status." without a Location");
			$state->location = $location;
			return false;
		}
		if(!in_array($state->status, self::OK_STATUSES, true)) {
			throw SourceUrlException::Failed("upstream ".$state->status);
		}
		$encoding = strtolower($state->headers["content-encoding"] ?? "");
		if($encoding !== "" && $encoding !== "identity") {
			$shown = substr((string)preg_replace('/[^a-z0-9, _-]/', "", $encoding), 0, 40);
			throw SourceUrlException::Failed("upstream sent Content-Encoding: ".$shown." (only identity is accepted)");
		}
		$state->contentLength = RequestBody::ContentLength($state->headers["content-length"] ?? null);
		if($state->contentLength !== null) {
			if($state->contentLength > $maxBytes) throw SourceUrlException::TooLarge($state->contentLength);
			if($checkLength !== null) $checkLength($state->contentLength);
		}
		$state->disposition = $state->headers["content-disposition"] ?? null;
		$state->readingBody = true;
		return true;
	}

	/** What went wrong, for the caller: never an address, never curl's own message (it names them). */
	private function CurlReason($ch, int $errno, int $status, float $deadline): string {
		switch($errno) {
			case 28: // CURLE_OPERATION_TIMEDOUT: connect, total and low speed all end here
				if($status === 0 && (float)curl_getinfo($ch, CURLINFO_CONNECT_TIME) === 0.0) return "could not connect within ".self::CONNECT_TIMEOUT_S." s";
				if(microtime(true) >= $deadline - 0.5) return "timed out after ".$this->TimeoutS()." s";
				return "too slow (under 1 KB/s for ".$this->lowSpeedTimeS." s)";
			case 6: return "DNS lookup failed"; // can't happen: the name is pinned (CURLOPT_RESOLVE)
			case 7: return "could not connect";
			case 35: return "TLS handshake failed";
			case 51: case 60: return "TLS certificate invalid";
			case 58: case 59: case 77: case 80: case 83: return "TLS error";
			case 52: return "empty reply";
			case 55: case 56: return "connection lost";
			case 18: return "transfer ended before the Content-Length";
			case 16: case 92: return "HTTP/2 error";
			case 23: return "could not write the download file";
			case 42: return "the connection wasn't made to the checked address"; // PREREQFUNCTION aborted it
			default: return "curl error ".$errno;
		}
	}

	private static function IsOneOf(string $ip, array $addresses): bool {
		$bytes = @inet_pton($ip);
		return $bytes !== false && in_array(inet_ntop($bytes), $addresses, true);
	}

}
