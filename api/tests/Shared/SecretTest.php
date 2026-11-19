<?php

namespace MagratheaExplorer\Tests\Shared;

use MagratheaExplorer\Secret;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Tier 2 §4 of the 1.5.0 plan: secrets are minted here and stored only as hashes. */
class SecretTest extends TestCase {

	const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

	public function testKeySecretIsALowercaseUuidV4(): void {
		for($i = 0; $i < 200; $i++) {
			$this->assertMatchesRegularExpression(self::UUID_V4, Secret::NewKeySecret());
		}
	}

	public function testKeySecretsDontRepeat(): void {
		$secrets = [];
		for($i = 0; $i < 1000; $i++) $secrets[] = Secret::NewKeySecret();
		$this->assertCount(1000, array_unique($secrets));
	}

	public function testProvisioningSecretShape(): void {
		$secret = Secret::NewProvisioningSecret();
		$this->assertMatchesRegularExpression('/^xpv_[0-9a-f]{64}$/', $secret);
		$this->assertTrue(Secret::IsProvisioningSecret($secret));
		$this->assertNotSame($secret, Secret::NewProvisioningSecret());
	}

	public static function notProvisioningSecrets(): array {
		return [
			"null" => [null], "empty" => [""], "a key secret" => [Secret::NewKeySecret()],
			"no prefix" => [str_repeat("a", 64)], "short" => ["xpv_".str_repeat("a", 63)], "long" => ["xpv_".str_repeat("a", 65)],
			"not hex" => ["xpv_".str_repeat("g", 64)], "other prefix" => ["xpk_".str_repeat("a", 64)],
		];
	}

	#[DataProvider("notProvisioningSecrets")]
	public function testNotProvisioningSecrets(?string $value): void {
		$this->assertFalse(Secret::IsProvisioningSecret($value));
	}

	public function testHashIsSha256Hex(): void {
		$secret = "0193a4b2-7c1d-7e2f-8a3b-4c5d6e7f8091";
		$this->assertSame(hash("sha256", $secret), Secret::Hash($secret));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', Secret::Hash($secret));
	}

	/**
	 * The plaintext column compared case-insensitively and the migration hashes
	 * SHA2(LOWER(TRIM(uuid)), 256): a key sent in upper case, or with spaces around it, still matches.
	 */
	public function testHashMatchesWhatTheMigrationStores(): void {
		$stored = "0193a4b2-7c1d-7e2f-8a3b-4c5d6e7f8091";
		$this->assertSame(Secret::Hash($stored), Secret::Hash(strtoupper($stored)));
		$this->assertSame(Secret::Hash($stored), Secret::Hash(" ".$stored."\t"));
		$this->assertNotSame(Secret::Hash($stored), Secret::Hash("0193a4b2-7c1d-7e2f-8a3b-4c5d6e7f8092"));
	}

}
