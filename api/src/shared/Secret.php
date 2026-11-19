<?php

namespace MagratheaExplorer;

/**
 * Bearer secrets: minted here, stored only as Hash(). Shown once, to whoever created or rotated
 * them, and never readable again (1.5.0: key secrets were plaintext before).
 *
 * - A key's secret is a UUID v4 (122 random bits), the format every key has always had, so
 *   clients that store or check it as a UUID keep working.
 * - The provisioning secret is `xpv_` + 64 hex characters (256 bits). The prefix tells secret
 *   scanners what it is, and makes a key and the provisioning secret hard to swap by mistake.
 */
final class Secret {

	const PROVISIONING_PREFIX = "xpv_";

	public static function NewKeySecret(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // RFC 9562 variant
		$hex = bin2hex($bytes);
		return substr($hex, 0, 8)."-".substr($hex, 8, 4)."-".substr($hex, 12, 4)."-".substr($hex, 16, 4)."-".substr($hex, 20, 12);
	}

	public static function NewProvisioningSecret(): string {
		return self::PROVISIONING_PREFIX.bin2hex(random_bytes(32));
	}

	/** Has the provisioning secret's shape. Says nothing about whether it's the right one. */
	public static function IsProvisioningSecret(?string $value): bool {
		return $value !== null && preg_match('/^xpv_[0-9a-f]{64}$/i', trim($value)) === 1;
	}

	/**
	 * What's stored, and what a bearer is looked up by: sha256 of the secret, trimmed and lowercased,
	 * as lowercase hex. Lowercased because the plaintext column compared case-insensitively (a key
	 * sent in upper case worked) and the 1.5.0 migration hashes LOWER(uuid): it still works. Every
	 * secret minted here is hex, so lowercasing costs no entropy. sha256 is enough: secrets are
	 * random, 122 bits and up, and there's nothing to brute-force.
	 */
	public static function Hash(string $secret): string {
		return hash("sha256", strtolower(trim($secret)));
	}

}
