<?php

namespace MagratheaExplorer;

use Magrathea\Logger;

/**
 * This request owns an Idempotency-Key (Idempotency::Begin()): it runs the action, records what
 * it created, then completes or releases the key. Idempotency::Run() does the last two.
 */
final class IdempotencyClaim {

	private bool $recorded = false;
	private bool $finished = false;

	public function __construct(
		private IdempotencyStore $store,
		public readonly int $id,
		public readonly int $attempt,
	) {}

	/**
	 * Records what the request created. Call it inside the transaction that creates the resource,
	 * right after its insert: the two commit together or not at all, so no crash can leave a
	 * resource the key doesn't point at (a retry would make a second one).
	 * 4091 when a retry took the key over meanwhile (this request outlived its lease): the throw
	 * rolls the transaction back, so the retry's resource is the only one.
	 */
	public function RecordResource(string $type, int $resourceId): void {
		if(!$this->store->RecordResource($this->id, $this->attempt, $type, $resourceId)) {
			ErrorCodes::Instance()->ThrowException(4091, null, "a retry took this key over (this request outlived its lease)");
		}
		$this->recorded = true;
	}

	/** RecordResource() for a model, as the callback UploadOptions and ProvisioningControl::CreateKey() take. */
	public function Recorder(string $type): \Closure {
		return function(object $resource) use ($type): void {
			$this->RecordResource($type, (int)$resource->id);
		};
	}

	/**
	 * The action succeeded: retries replay its resource from now on. An action that recorded
	 * nothing has nothing to replay, so its key is released instead. Never throws: the resource
	 * is recorded already, and a record with a resource replays whatever its status.
	 */
	public function Complete(): void {
		if(!$this->recorded) {
			$this->Release();
			return;
		}
		$this->finished = true;
		try {
			$this->store->Complete($this->id, $this->attempt);
		} catch(\Throwable $ex) {
			Logger::Instance()->LogError($ex);
		}
	}

	/**
	 * The action failed: the key is free again, unless a resource was recorded (a retry replays
	 * that). Errors aren't stored, so a retry runs again. Never throws: if the delete fails, the
	 * lease runs out and a retry takes the key over.
	 */
	public function Release(): void {
		$this->finished = true;
		try {
			$this->store->Release($this->id, $this->attempt);
		} catch(\Throwable $ex) {
			Logger::Instance()->LogError($ex);
		}
	}

	public function IsFinished(): bool {
		return $this->finished;
	}

}
