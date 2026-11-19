<?php

namespace MagratheaExplorer\Tests\Support;

use MagratheaExplorer\Idempotency;
use MagratheaExplorer\IdempotencyRecord;
use MagratheaExplorer\IdempotencyStore;

/**
 * IdempotencyStore in memory, with the same conditions as IdempotencyDbStore's statements, and a
 * clock the test moves ($now). $beforeFind and $beforeTakeOver run once, before the next Find()
 * or TakeOver(): a test uses them to change the row in between, as a concurrent request would.
 */
final class FakeIdempotencyStore implements IdempotencyStore {

	public int $now = 1795003200;
	/** @var array<int, array> */
	public array $rows = [];
	public ?\Closure $beforeFind = null;
	public ?\Closure $beforeTakeOver = null;
	private int $nextId = 1;

	public function Insert(string $credentialType, int $credentialId, string $key, string $requestHash, int $leaseS): ?int {
		if($this->IdOf($credentialType, $credentialId, $key) !== null) return null;
		$id = $this->nextId++;
		$this->rows[$id] = [
			"credential" => $credentialType."|".$credentialId."|".$key,
			"attempt" => 1,
			"request_hash" => $requestHash,
			"status" => Idempotency::STATUS_IN_PROGRESS,
			"lease_until" => $this->now + $leaseS,
			"resource_type" => null,
			"resource_id" => null,
		];
		return $id;
	}

	public function Find(string $credentialType, int $credentialId, string $key): ?IdempotencyRecord {
		if($this->beforeFind !== null) {
			$hook = $this->beforeFind;
			$this->beforeFind = null;
			$hook($this);
		}
		$id = $this->IdOf($credentialType, $credentialId, $key);
		if($id === null) return null;
		$row = $this->rows[$id];
		return new IdempotencyRecord($id, $row["attempt"], $row["request_hash"], $row["status"],
			$row["lease_until"] < $this->now, $row["resource_type"], $row["resource_id"]);
	}

	public function TakeOver(int $id, int $attempt, int $leaseS): bool {
		if($this->beforeTakeOver !== null) {
			$hook = $this->beforeTakeOver;
			$this->beforeTakeOver = null;
			$hook($this);
		}
		$row = $this->rows[$id] ?? null;
		if($row === null || $row["attempt"] !== $attempt || $row["status"] !== Idempotency::STATUS_IN_PROGRESS
			|| $row["resource_id"] !== null || $row["lease_until"] >= $this->now) return false;
		$this->rows[$id]["attempt"]++;
		$this->rows[$id]["lease_until"] = $this->now + $leaseS;
		return true;
	}

	public function RecordResource(int $id, int $attempt, string $type, int $resourceId): bool {
		$row = $this->rows[$id] ?? null;
		if($row === null || $row["attempt"] !== $attempt || $row["resource_id"] !== null) return false;
		$this->rows[$id]["resource_type"] = $type;
		$this->rows[$id]["resource_id"] = $resourceId;
		return true;
	}

	public function Complete(int $id, int $attempt): void {
		if(($this->rows[$id]["attempt"] ?? null) === $attempt) $this->rows[$id]["status"] = Idempotency::STATUS_DONE;
	}

	public function Release(int $id, int $attempt): void {
		$row = $this->rows[$id] ?? null;
		if($row !== null && $row["attempt"] === $attempt && $row["resource_id"] === null) unset($this->rows[$id]);
	}

	private function IdOf(string $credentialType, int $credentialId, string $key): ?int {
		foreach($this->rows as $id => $row) {
			if($row["credential"] === $credentialType."|".$credentialId."|".$key) return $id;
		}
		return null;
	}

}
