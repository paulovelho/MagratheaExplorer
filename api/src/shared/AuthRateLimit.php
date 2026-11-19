<?php

namespace MagratheaExplorer;

use Magrathea\DB\Database;

/**
 * Failed authentications per client, in fixed 15-minute windows (`auth_failures`): after
 * MAX_FAILURES in one window, the client gets 4291 (HTTP 429) with `Retry-After` until the window
 * ends. Checked before the secret is, so a blocked client has nothing left to test. Successes
 * don't count.
 *
 * Only the provisioning secret uses it. Key secrets have 122 random bits, and a key's 401 is
 * the framework's: nothing to gain. A burst of parallel failures can go a few past the limit
 * (the count is read, then incremented): it's a brake, not an exact quota.
 */
final class AuthRateLimit {

	const WINDOW_S = 900;
	const MAX_FAILURES = 10;
	/** Windows older than this are purged (cron.php --process). */
	const RETENTION_S = 86400;

	public function __construct(private string $bucket) {}

	/** For POST /provision/keys: "prov:" + the client's bucket (ClientIp::Bucket()). */
	public static function ForProvisioning(): self {
		return new self("prov:".ClientIp::Bucket(ClientIp::FromRequest()));
	}

	public function Bucket(): string {
		return $this->bucket;
	}

	/** Unix time the window holding $now starts. Windows are aligned to the epoch, not to the first failure. */
	public static function WindowStart(int $now): int {
		return intdiv($now, self::WINDOW_S) * self::WINDOW_S;
	}

	/** Seconds until $failures (this window's) stop blocking: null = not blocked. At least 1. */
	public static function RetryAfterFor(int $failures, int $now): ?int {
		if($failures < self::MAX_FAILURES) return null;
		return self::WindowStart($now) + self::WINDOW_S - $now;
	}

	/** null = may try. Otherwise the seconds to wait, for `Retry-After`. */
	public function RetryAfter(?int $now = null): ?int {
		$now ??= time();
		$failures = (int)Database::Instance()->QueryOne(
			"SELECT `failures` FROM `auth_failures` WHERE `bucket` = '".Database::Instance()->Escape($this->bucket)."'"
			." AND `window_start` = ".self::WindowStart($now)
		);
		return self::RetryAfterFor($failures, $now);
	}

	public function RecordFailure(?int $now = null): void {
		Database::Instance()->PrepareAndExecute(
			"INSERT INTO `auth_failures` (`bucket`, `window_start`, `failures`) VALUES (?, ?, 1)"
			." ON DUPLICATE KEY UPDATE `failures` = `failures` + 1",
			["string", "int"],
			[$this->bucket, self::WindowStart($now ?? time())]
		);
	}

	/** Deletes windows older than RETENTION_S. @return int rows deleted */
	public static function Purge(?int $now = null): int {
		Database::Instance()->PrepareAndExecute(
			"DELETE FROM `auth_failures` WHERE `window_start` < ?",
			["int"],
			[($now ?? time()) - self::RETENTION_S]
		);
		return Database::Instance()->LastAffectedRows();
	}

}
