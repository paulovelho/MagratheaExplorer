<?php

namespace MagratheaExplorer;

/**
 * One `idempotency_keys` row, as Idempotency::Begin() reads it after its insert hit an existing
 * key. When Begin() returns one, the request is a replay: answer with its resource.
 */
final class IdempotencyRecord {

	public function __construct(
		public readonly int $id,
		/** 1, plus one per takeover. Only the request holding the current attempt can record a resource. */
		public readonly int $attempt,
		public readonly string $requestHash,
		/** Idempotency::STATUS_IN_PROGRESS or STATUS_DONE */
		public readonly string $status,
		/** The lease is over: an in_progress row without a resource can be taken over. */
		public readonly bool $leaseExpired,
		/** Idempotency::RESOURCE_FILE or RESOURCE_KEY; null until the request created it */
		public readonly ?string $resourceType = null,
		public readonly ?int $resourceId = null,
	) {}

	/** The request created its resource. Whatever the status says, a retry replays it. */
	public function HasResource(): bool {
		return $this->resourceType !== null && $this->resourceId !== null;
	}

}
