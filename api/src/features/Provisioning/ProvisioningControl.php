<?php

namespace MagratheaExplorer\Provisioning;

use Magrathea\DB\Database;
use Magrathea\MagratheaModelControl;
use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\Key\Key;
use MagratheaExplorer\Key\KeyControl;
use MagratheaExplorer\Key\KeySettings;
use MagratheaExplorer\Secret;

/**
 * Provisioning (1.5.0): a consuming project (Turbopod) creates keys itself, one per tenant, with
 * POST /provision/keys and the provisioning secret. That's all the secret does: it can't read,
 * change or delete any key, existing ones included. Each key it creates is then managed with
 * that key's own secret (GET /key, DELETE /key, POST /key/rotate-secret), and its limits by the
 * operator, in admin → Keys. So a leaked provisioning secret can only add keys: at most
 * `max_keys`, each with the template's limits.
 */
class ProvisioningControl extends MagratheaModelControl {
	protected static $model = Provisioning::class;

	/** The one row. The migration inserts it; GetOrCreate() puts it back if it was deleted. */
	const ROW_ID = 1;

	public static function Get(): ?Provisioning {
		return self::GetRowWhere(["id" => self::ROW_ID]);
	}

	public static function GetOrCreate(): Provisioning {
		Database::Instance()->Query("INSERT IGNORE INTO `provisioning` (`id`) VALUES (".self::ROW_ID.")");
		return self::Get();
	}

	/** Whether $token is the provisioning secret. Constant-time on the hash. */
	public static function Authenticate(?string $token): bool {
		if(!Secret::IsProvisioningSecret($token)) return false;
		$provisioning = self::Get();
		if($provisioning === null || !$provisioning->IsOn()) return false;
		return hash_equals(strtolower((string)$provisioning->secret_hash), Secret::Hash($token));
	}

	/** `last_used_at`, written at most once a minute rather than on every request. */
	public static function TouchLastUsed(): void {
		Database::Instance()->Query(
			"UPDATE `provisioning` SET `last_used_at` = NOW() WHERE `id` = ".self::ROW_ID
			." AND (`last_used_at` IS NULL OR `last_used_at` < NOW() - INTERVAL 1 MINUTE)"
		);
	}

	/**
	 * A new provisioning secret, returned once: only its hash is stored. The previous one, if
	 * any, stops working right away.
	 */
	public static function NewSecret(): string {
		self::GetOrCreate();
		$secret = Secret::NewProvisioningSecret();
		Database::Instance()->PrepareAndExecute(
			"UPDATE `provisioning` SET `secret_hash` = ? WHERE `id` = ?",
			["string", "int"],
			[Secret::Hash($secret), self::ROW_ID]
		);
		return $secret;
	}

	/** No secret: POST /provision/keys refuses everything. Existing keys aren't affected. */
	public static function TurnOff(): void {
		Database::Instance()->Query("UPDATE `provisioning` SET `secret_hash` = NULL WHERE `id` = ".self::ROW_ID);
	}

	/** Provisioned keys that exist, pending deletions included: what `max_keys` counts. */
	public static function CountKeys(): int {
		return (int)Database::Instance()->QueryOne("SELECT COUNT(*) FROM `access_keys` WHERE `provisioned` = 1");
	}

	/** Provisioned keys with a pending deletion (admin → Provisioning). */
	public static function CountPendingDeletion(): int {
		return (int)Database::Instance()->QueryOne(
			"SELECT COUNT(DISTINCT k.`id`) FROM `access_keys` k"
			." INNER JOIN `scheduled_deletions` d ON d.`key_id` = k.`id` AND d.`cancelled_at` IS NULL"
			." WHERE k.`provisioned` = 1"
		);
	}

	/**
	 * POST /provision/keys: a key named $name with the provisioning settings, in one transaction
	 * (key, settings, root folder). The provisioning row is locked meanwhile (FOR UPDATE), so two
	 * creates racing for the last of `max_keys` run one after the other: the second gets 4033.
	 * @param string $name already checked (KeyControl::NormalizeName())
	 * @param ?\Closure $onCreated called with the new Key inside the transaction: throwing rolls the
	 *                             key back. ProvisioningApi records it as the Idempotency-Key's resource.
	 * @return array{0: Key, 1: string} the key and its secret, which isn't stored
	 * @throws \Magrathea\Exceptions\MagratheaApiException 4033 (limit), 4094 (name taken)
	 * @throws \Magrathea\Exceptions\MagratheaDBException 1062 when the name was taken meanwhile by an admin key
	 */
	public static function CreateKey(string $name, ?\Closure $onCreated = null): array {
		return Database::Instance()->Transaction(function() use ($name, $onCreated) {
			$locked = self::RunQuery("SELECT * FROM `provisioning` WHERE `id` = ".self::ROW_ID." FOR UPDATE");
			$provisioning = $locked[0] ?? null;
			if($provisioning === null) {
				// auth found it a moment ago: deleted by hand in between
				ErrorCodes::Instance()->ThrowException(5004, null, "provisioning isn't set up");
			}
			if($provisioning->max_keys !== null && self::CountKeys() >= (int)$provisioning->max_keys) {
				ErrorCodes::Instance()->ThrowException(4033, null, "this instance allows ".(int)$provisioning->max_keys." provisioned keys");
			}
			if(KeyControl::NameTaken($name)) {
				ErrorCodes::Instance()->ThrowException(4094);
			}
			[$key, $secret] = KeyControl::Create($name, KeySettings::Of($provisioning), true);
			if($onCreated !== null) $onCreated($key);
			return [$key, $secret];
		});
	}

}
