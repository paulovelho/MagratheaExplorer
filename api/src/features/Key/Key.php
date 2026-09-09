<?php

namespace MagratheaExplorer\Key;

use Magrathea2\DB\Database;
use MagratheaExplorer\ErrorCodes;

class Key extends \MagratheaExplorer\Key\Base\KeyBase {

	/**
	 * The framework writes every declared dbValues field on Insert() regardless of
	 * whether the PHP property was ever set -- it does NOT fall back to the column's
	 * SQL DEFAULT for an unset property. So every field with a non-null-ish default
	 * (including `active`) must be defaulted here explicitly, not just left unset.
	 */
	public function Normalize(): Key {
		if(!$this->uses) $this->uses = 0;
		if(!$this->total_size) $this->total_size = 0;
		if(empty($this->usage_limit)) $this->usage_limit = null;
		if(empty($this->usage_limit_mb)) $this->usage_limit_mb = null;
		if(empty($this->expiration)) $this->expiration = null;
		if($this->active === null || $this->active === "") $this->active = 1;
		return $this;
	}

	/**
	 * A key with a pending scheduled deletion behaves like an inactive key immediately,
	 * even before the 14 days are up -- "changed your mind" and "actually deleting" share
	 * one code path.
	 */
	public function HasPendingScheduledDeletion(): bool {
		$pk = $this->dbPk;
		$pending = \MagratheaExplorer\Key\Base\ScheduledDeletionControlBase::GetRowWhere([
			"key_id" => $this->$pk,
			"cancelled_at" => null,
		]);
		return !empty($pending);
	}

	/**
	 * Checked on every authenticated request. Reads are always allowed for a resolvable
	 * key (its files/folders can still be listed) -- this only gates whether the key is
	 * usable at all (bad uuid, wrong active flag). Upload-specific gating is AssertQuota().
	 */
	public function AssertUsable(): void {
		if(!$this->active) {
			ErrorCodes::Instance()->ThrowException(4013);
		}
		if($this->expiration != null && $this->expiration < \Magrathea2\now()) {
			ErrorCodes::Instance()->ThrowException(4012);
		}
	}

	/**
	 * Upload-time gate: expired/inactive/pending-deletion keys can't upload, and neither
	 * can a key that would cross either of its two independent caps.
	 */
	public function AssertCanUpload(int $incomingSize): void {
		$this->AssertUsable();
		if($this->HasPendingScheduledDeletion()) {
			ErrorCodes::Instance()->ThrowException(4014);
		}
		if($this->usage_limit !== null && $this->uses >= $this->usage_limit) {
			ErrorCodes::Instance()->ThrowException(4031);
		}
		if($this->usage_limit_mb !== null) {
			$limitBytes = $this->usage_limit_mb * 1024 * 1024;
			if(($this->total_size + $incomingSize) > $limitBytes) {
				ErrorCodes::Instance()->ThrowException(4032);
			}
		}
	}

	/**
	 * Atomic `uses = uses + 1` -- avoids a lost-update race under concurrent uploads.
	 * `uses` is a lifetime counter: never decremented on delete (see AdjustSize()).
	 */
	public function IncrementUses(): Key {
		$pk = $this->dbPk;
		Database::Instance()->PrepareAndExecute(
			"UPDATE `access_keys` SET `uses` = `uses` + 1 WHERE `id` = ?",
			["int"],
			[$this->$pk]
		);
		$this->uses = ($this->uses ?? 0) + 1;
		return $this;
	}

	/**
	 * Atomic `total_size = total_size + $delta` (negative delta on delete) -- unlike `uses`,
	 * this stays a live "how much am I storing right now" figure.
	 */
	public function AdjustSize(int $delta): Key {
		$pk = $this->dbPk;
		Database::Instance()->PrepareAndExecute(
			"UPDATE `access_keys` SET `total_size` = `total_size` + ? WHERE `id` = ?",
			["int", "int"],
			[$delta, $this->$pk]
		);
		$this->total_size = ($this->total_size ?? 0) + $delta;
		return $this;
	}

}
