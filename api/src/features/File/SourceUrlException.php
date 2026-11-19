<?php

namespace MagratheaExplorer\File;

/**
 * Why a `source_url` upload didn't produce a file. SourceUrl and SafeFetcher throw it, and
 * FileApi turns it into the API's code: they don't know about keys (whose limit a 4002 names)
 * or the error table, so they stay testable on their own.
 *
 * - REFUSED: the URL breaks a rule (scheme, host, port, a non-public address, redirects) → 40012;
 * - FAILED: the fetch itself went wrong (a DNS lookup that failed, TLS, connection, timeout, an
 *   upstream status) → 5021. A name that doesn't exist is REFUSED, like a private one
 *   (SafeFetcher::Addresses());
 * - TOO_LARGE: over the size limit → 4002. $size is the upstream's Content-Length, null when
 *   it didn't send one (the body was cut off at the limit).
 *
 * The message is the detail the caller sees: never an address, never a curl internal.
 */
final class SourceUrlException extends \RuntimeException {

	const REFUSED = "refused";
	const FAILED = "failed";
	const TOO_LARGE = "too_large";

	private function __construct(public readonly string $kind, string $message, public readonly ?int $size = null) {
		parent::__construct($message);
	}

	public static function Refused(string $reason): self {
		return new self(self::REFUSED, $reason);
	}

	public static function Failed(string $reason): self {
		return new self(self::FAILED, $reason);
	}

	public static function TooLarge(?int $size): self {
		return new self(self::TOO_LARGE, $size === null ? "over the size limit" : $size." bytes, over the size limit", $size);
	}

}
