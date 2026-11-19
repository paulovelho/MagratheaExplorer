<?php

namespace MagratheaExplorer;

/**
 * Where Idempotency keeps its records: `idempotency_keys` (IdempotencyDbStore), or a fake in the
 * tests. Every write that names an attempt applies only while that attempt still owns the row,
 * so a request that a retry took over can no longer change it.
 */
interface IdempotencyStore {

	/**
	 * A new row: in_progress, attempt 1, leased for $leaseS seconds.
	 * @return ?int its id; null when (credential, key) already has a row
	 */
	public function Insert(string $credentialType, int $credentialId, string $key, string $requestHash, int $leaseS): ?int;

	/** The row of (credential, key). null: there's none (released, or purged, since). */
	public function Find(string $credentialType, int $credentialId, string $key): ?IdempotencyRecord;

	/**
	 * Gives row $id to a new attempt ($attempt + 1) with a new lease, if it's still in_progress at
	 * $attempt, its lease is over and it has no resource. false: it wasn't (another retry got there
	 * first, or the owner finished).
	 */
	public function TakeOver(int $id, int $attempt, int $leaseS): bool;

	/** Sets the resource, if $attempt still owns row $id and none is set. false: it doesn't. */
	public function RecordResource(int $id, int $attempt, string $type, int $resourceId): bool;

	/** in_progress → done, if $attempt still owns row $id. */
	public function Complete(int $id, int $attempt): void;

	/** Deletes row $id, if $attempt still owns it and it has no resource: the key is free again. */
	public function Release(int $id, int $attempt): void;

}
