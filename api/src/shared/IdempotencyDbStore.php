<?php

namespace MagratheaExplorer;

use Magrathea\DB\Database;
use Magrathea\Exceptions\MagratheaDBException;
use Magrathea\Logger;

/**
 * Idempotency's records in `idempotency_keys`. No model: nothing but Idempotency reads them.
 *
 * Leases are unix times from the database's clock (UNIX_TIMESTAMP()), set and compared there, so
 * a DST change can't shorten one and two web servers can't disagree on one.
 *
 * A DB error in Insert(), Find(), TakeOver() or RecordResource() is logged and answered as a plain
 * 500: the framework's error response would carry the query and its values, in every mode.
 * Complete() and Release() let it through: IdempotencyClaim logs it and carries on.
 */
final class IdempotencyDbStore implements IdempotencyStore {

	/** Rows deleted per Purge() at most: cron.php --process runs every minute. */
	const PURGE_LIMIT = 1000;

	public function Insert(string $credentialType, int $credentialId, string $key, string $requestHash, int $leaseS): ?int {
		try {
			$id = Database::Instance()->PrepareAndExecute(
				"INSERT INTO `idempotency_keys` (`credential_type`, `credential_id`, `idempotency_key`, `request_hash`, `lease_until`)"
				." VALUES (?, ?, ?, ?, UNIX_TIMESTAMP() + ?)",
				["string", "int", "string", "string", "int"],
				[$credentialType, $credentialId, $key, $requestHash, $leaseS]
			);
		} catch(MagratheaDBException $ex) {
			if($ex->getCode() === 1062) return null;
			self::Fail($ex);
		}
		return (int)$id;
	}

	public function Find(string $credentialType, int $credentialId, string $key): ?IdempotencyRecord {
		$db = Database::Instance();
		try {
			$row = (array)$db->QueryRow(
				"SELECT `id`, `attempt`, `request_hash`, `status`, `resource_type`, `resource_id`,"
				." (`lease_until` < UNIX_TIMESTAMP()) AS `lease_expired`"
				." FROM `idempotency_keys`"
				." WHERE `credential_type` = '".$db->Escape($credentialType)."'"
				." AND `credential_id` = ".$credentialId
				." AND `idempotency_key` = '".$db->Escape($key)."'"
			);
		} catch(MagratheaDBException $ex) {
			self::Fail($ex);
		}
		if($row === []) return null;
		return new IdempotencyRecord(
			(int)$row["id"],
			(int)$row["attempt"],
			(string)$row["request_hash"],
			(string)$row["status"],
			(bool)(int)$row["lease_expired"],
			$row["resource_type"] !== null ? (string)$row["resource_type"] : null,
			$row["resource_id"] !== null ? (int)$row["resource_id"] : null,
		);
	}

	public function TakeOver(int $id, int $attempt, int $leaseS): bool {
		return $this->Changed(
			"UPDATE `idempotency_keys` SET `attempt` = `attempt` + 1, `lease_until` = UNIX_TIMESTAMP() + ?"
			." WHERE `id` = ? AND `attempt` = ? AND `status` = '".Idempotency::STATUS_IN_PROGRESS."'"
			." AND `resource_id` IS NULL AND `lease_until` < UNIX_TIMESTAMP()",
			["int", "int", "int"],
			[$leaseS, $id, $attempt]
		);
	}

	/**
	 * One UPDATE, so the check and the write can't be split. Run inside the transaction that
	 * creates the resource, it holds the row's lock until that commits: a takeover waits, then
	 * finds the resource set and changes nothing.
	 */
	public function RecordResource(int $id, int $attempt, string $type, int $resourceId): bool {
		return $this->Changed(
			"UPDATE `idempotency_keys` SET `resource_type` = ?, `resource_id` = ?"
			." WHERE `id` = ? AND `attempt` = ? AND `resource_id` IS NULL",
			["string", "int", "int", "int"],
			[$type, $resourceId, $id, $attempt]
		);
	}

	public function Complete(int $id, int $attempt): void {
		Database::Instance()->PrepareAndExecute(
			"UPDATE `idempotency_keys` SET `status` = '".Idempotency::STATUS_DONE."' WHERE `id` = ? AND `attempt` = ?",
			["int", "int"],
			[$id, $attempt]
		);
	}

	public function Release(int $id, int $attempt): void {
		Database::Instance()->PrepareAndExecute(
			"DELETE FROM `idempotency_keys` WHERE `id` = ? AND `attempt` = ? AND `resource_id` IS NULL",
			["int", "int"],
			[$id, $attempt]
		);
	}

	/**
	 * Deletes records older than the retention (Idempotency::RetentionHours()), plus an hour: on an
	 * instance in a zone with DST, `created_at` is local time, and the spring-forward night would
	 * otherwise cut one hour's records short of the 24 h floor. A row with a live lease is kept
	 * (a takeover can outlast its `created_at` by a few minutes). At most PURGE_LIMIT rows a run.
	 * @return int rows deleted
	 */
	public static function Purge(int $retentionHours, int $limit = self::PURGE_LIMIT): int {
		Database::Instance()->PrepareAndExecute(
			"DELETE FROM `idempotency_keys` WHERE `created_at` < NOW() - INTERVAL ? HOUR"
			." AND `lease_until` < UNIX_TIMESTAMP() LIMIT ".max(1, $limit),
			["int"],
			[$retentionHours + 1]
		);
		return Database::Instance()->LastAffectedRows();
	}

	private function Changed(string $sql, array $types, array $values): bool {
		try {
			Database::Instance()->PrepareAndExecute($sql, $types, $values);
		} catch(MagratheaDBException $ex) {
			self::Fail($ex);
		}
		return Database::Instance()->LastAffectedRows() === 1;
	}

	private static function Fail(MagratheaDBException $ex): never {
		Logger::Instance()->LogError($ex);
		ErrorCodes::Instance()->ThrowException(500);
		throw new \LogicException("unreachable"); // ThrowException() always throws
	}

}
