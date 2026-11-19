<?php

namespace MagratheaExplorer;

/**
 * Idempotency-Key (1.5.0) on `POST /files` and `POST /provision/keys`: a retry with the same key
 * gets the resource the first request made, instead of a second one.
 *
 * A record per (credential, key) in `idempotency_keys`, kept at least 24 h:
 * - no header: no idempotency, as before;
 * - a new key: this request owns it (IdempotencyClaim) and runs;
 * - the key with a different request → 4221 (422), checked first;
 * - the key with a resource → replay: the resource in its current state, `Idempotent-Replayed: true`;
 * - the key still running (lease alive) → 4091 (409);
 * - the key's owner gone (lease over, nothing created) → taken over, and this request runs.
 *
 * The resource is recorded in the transaction that creates it (IdempotencyClaim::
 * RecordResource()), and only by the attempt that owns the row: a request that dies after
 * creating it replays, and one that outlives its lease can't add a second one after a takeover.
 * Errors aren't stored: a request that fails releases its key, and a retry runs again.
 */
final class Idempotency {

	const SERVER_KEY = "HTTP_IDEMPOTENCY_KEY";
	const MAX_LENGTH = 255;

	const CREDENTIAL_KEY = "key";
	const CREDENTIAL_PROVISIONING = "provisioning";
	const RESOURCE_FILE = "file";
	const RESOURCE_KEY = "key";
	const STATUS_IN_PROGRESS = "in_progress";
	const STATUS_DONE = "done";

	/** Hours a record is kept, at least (the blueprint's floor). magrathea.conf `idempotency_retention_h` raises it. */
	const MIN_RETENTION_H = 24;
	const MIN_LEASE_S = 120;
	/** What a request may take when PHP sets no `max_execution_time` (0): deploy.md's 300. */
	const UNLIMITED_EXECUTION_S = 300;
	/** Begin()'s insert can hit a row its owner releases before the read: tries before giving up with 4091. */
	const MAX_TRIES = 3;

	/**
	 * The request's Idempotency-Key, checked. null = no header (no idempotency).
	 * @param ?array $server $_SERVER when null
	 */
	public static function KeyFromRequest(?array $server = null): ?string {
		$server ??= $_SERVER;
		if(!array_key_exists(self::SERVER_KEY, $server)) return null;
		return self::ValidateKey($server[self::SERVER_KEY]);
	}

	/** 40011 when it's empty, over 255 characters, or not printable ASCII. Surrounding spaces and tabs are dropped. */
	public static function ValidateKey(mixed $raw): string {
		$key = is_string($raw) ? trim($raw, " \t") : "";
		if($key === "") {
			ErrorCodes::Instance()->ThrowException(40011, null, "empty");
		}
		if(strlen($key) > self::MAX_LENGTH) {
			ErrorCodes::Instance()->ThrowException(40011, null, "over ".self::MAX_LENGTH." characters");
		}
		if(preg_match('/^[\x20-\x7E]+$/D', $key) !== 1) {
			ErrorCodes::Instance()->ThrowException(40011, null, "printable ASCII only");
		}
		return $key;
	}

	/**
	 * What makes two requests "the same": sha256 of a canonical JSON of the route and $fields.
	 * Object keys are sorted at every level, so their order doesn't matter; a list's order does
	 * (callers sort what's a set, like tags). Pass values as parsed (bools as bools), so `1` and
	 * `true` hash alike. Invalid UTF-8 is replaced, not refused: it only has to hash.
	 */
	public static function RequestHash(string $route, array $fields): string {
		$canonical = json_encode(
			self::Canonical(["route" => $route, "fields" => $fields]),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
		);
		return hash("sha256", $canonical);
	}

	private static function Canonical(mixed $value): mixed {
		if(!is_array($value)) return $value;
		$value = array_map([self::class, "Canonical"], $value);
		if(array_is_list($value)) return $value; // [] included
		ksort($value, SORT_STRING);
		return (object)$value;
	}

	/**
	 * How long a request owns its key before a retry may take it over: PHP's `max_execution_time`
	 * (300 when it has none), plus $fetchTimeoutS for a `source_url` upload, plus a minute, 120 s
	 * at least. It's a heuristic: on Linux `max_execution_time` counts CPU time, not the time spent
	 * waiting on storage or on the network, which is why the fetch's timeout is added to it rather
	 * than compared with it. A request that outlives the lease isn't a duplicate, though: it can't
	 * record its resource after a takeover (IdempotencyClaim::RecordResource()), so it fails with
	 * 4091 instead.
	 */
	public static function LeaseSeconds(string|false|null $maxExecutionTime = null, int $fetchTimeoutS = 0): int {
		$limit = (int)($maxExecutionTime ?? ini_get("max_execution_time"));
		$request = ($limit > 0 ? $limit : self::UNLIMITED_EXECUTION_S) + max(0, $fetchTimeoutS);
		return max(self::MIN_LEASE_S, $request + 60);
	}

	/** magrathea.conf `idempotency_retention_h`: 24 when absent. Below 24 → 5004. */
	public static function RetentionHours(): int {
		$hours = ExplorerConfig::GetPositiveInt("idempotency_retention_h", self::MIN_RETENTION_H);
		if($hours < self::MIN_RETENTION_H) {
			ErrorCodes::Instance()->ThrowException(5004, null, "magrathea.conf `idempotency_retention_h` (".self::MIN_RETENTION_H." at least)");
		}
		return $hours;
	}

	/**
	 * Called after auth and parameter parsing (so a refused request never owns a key), and
	 * before quotas or anything is stored (so a replay isn't refused by the quota the first
	 * request used up).
	 * @return IdempotencyClaim|IdempotencyRecord a claim: run the action (Run()). A record: replay its resource.
	 * @throws \Magrathea\Exceptions\MagratheaApiException 4221 (another request), 4091 (still running)
	 */
	public static function Begin(IdempotencyStore $store, string $credentialType, int $credentialId, string $key, string $requestHash, ?int $leaseS = null): IdempotencyClaim|IdempotencyRecord {
		$leaseS ??= self::LeaseSeconds();
		for($try = 0; $try < self::MAX_TRIES; $try++) {
			$id = $store->Insert($credentialType, $credentialId, $key, $requestHash, $leaseS);
			if($id !== null) return new IdempotencyClaim($store, $id, 1);

			$record = $store->Find($credentialType, $credentialId, $key);
			if($record === null) continue; // its owner failed and released it in between
			// first, so a different request learns nothing else about the first one
			if(!hash_equals($record->requestHash, $requestHash)) {
				ErrorCodes::Instance()->ThrowException(4221);
			}
			if($record->HasResource()) return $record; // done, or done but for the bookkeeping
			if(!$record->leaseExpired) {
				ErrorCodes::Instance()->ThrowException(4091);
			}
			if($store->TakeOver($record->id, $record->attempt, $leaseS)) {
				return new IdempotencyClaim($store, $record->id, $record->attempt + 1);
			}
			ErrorCodes::Instance()->ThrowException(4091); // another retry took it over first
		}
		ErrorCodes::Instance()->ThrowException(4091);
		throw new \LogicException("unreachable"); // ThrowException() always throws
	}

	/**
	 * Runs $action under $claim: Complete() when it returns, Release() when it throws (rethrown).
	 * No claim (no header): just $action.
	 */
	public static function Run(?IdempotencyClaim $claim, callable $action): mixed {
		if($claim === null) return $action();
		// A fatal error (memory, time limit) skips the catch below, not shutdown functions. Without
		// this, retries would get 409 until the lease ran out.
		register_shutdown_function(function() use ($claim) {
			if(!$claim->IsFinished()) $claim->Release();
		});
		try {
			$result = $action();
		} catch(\Throwable $ex) {
			$claim->Release();
			throw $ex;
		}
		$claim->Complete();
		return $result;
	}

	/** The `Idempotent-Replayed: true` header, on a replay's response. */
	public static function MarkReplayed(): void {
		if(!headers_sent()) header("Idempotent-Replayed: true");
	}

}
