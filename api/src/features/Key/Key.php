<?php

namespace MagratheaExplorer\Key;

use Magrathea\DB\Database;
use Magrathea\MagratheaModel;
use MagratheaExplorer\ErrorCodes;

class Key extends MagratheaModel {

	protected $dbTable = "access_keys";
	protected $dbPk = "id";
	protected $dbValues = [
		"id" => "int",
		// the bearer credential: never in a URL, never echoed back by the API
		"uuid" => "uuid",
		// "uuid" too, so Insert() mints it. Separate from `uuid` above: this one is the
		// public, non-secret storage directory name and goes into URLs freely.
		"storage_uuid" => "uuid",
		"name" => "string",
		"uses" => "int",
		"usage_limit" => "int",
		"total_size" => "int",
		"usage_limit_mb" => "int",
		"expiration" => "datetime",
		"active" => "boolean",
	];
	protected $relations = [
		"properties" => ["Folders" => null, "Files" => null, "ScheduledDeletions" => null],
		"methods" => ["Folders" => "GetFolders", "Files" => "GetFiles", "ScheduledDeletions" => "GetScheduledDeletions"],
		"lazyload" => ["Folders" => true, "Files" => true, "ScheduledDeletions" => true],
	];

	public $id, $uuid, $storage_uuid, $name, $uses, $usage_limit, $total_size, $usage_limit_mb, $expiration, $active;

	public function GetFolders(): array {
		return $this->relations["properties"]["Folders"] ??= \MagratheaExplorer\Folder\FolderControl::GetWhere(["key_id" => $this->id]);
	}

	public function GetFiles(): array {
		return $this->relations["properties"]["Files"] ??= \MagratheaExplorer\File\FileControl::GetWhere(["key_id" => $this->id]);
	}

	public function GetScheduledDeletions(): array {
		return $this->relations["properties"]["ScheduledDeletions"] ??= ScheduledDeletionControl::GetWhere(["key_id" => $this->id]);
	}

	/**
	 * Sets every field a new key needs, so the row doesn't depend on the columns' SQL
	 * defaults. (Insert() leaves a null field out of the INSERT, so its column DEFAULT
	 * applies: `usage_limit`/`usage_limit_mb`/`expiration` are nullable columns.)
	 * `uuid`/`storage_uuid` need no line here -- both are declared "uuid", so Insert()
	 * mints them itself when left unset.
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

	/** Public, non-secret directory this key's objects live under -- never `uuid`, which is the bearer credential. */
	public function StorageDir(): string {
		return $this->storage_uuid;
	}

	/** The one place a storage path is assembled, for both a file and its thumbnail. */
	public function StoragePathFor(string $token, string $extension): string {
		return $this->storage_uuid."/".$token.".".$extension;
	}

	/**
	 * A key with a pending scheduled deletion behaves like an inactive key immediately,
	 * even before the 14 days are up -- "changed your mind" and "actually deleting" share
	 * one code path.
	 */
	public function HasPendingScheduledDeletion(): bool {
		$pending = ScheduledDeletionControl::GetRowWhere([
			"key_id" => $this->id,
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
		if($this->expiration != null && $this->expiration < \Magrathea\now()) {
			ErrorCodes::Instance()->ThrowException(4012);
		}
	}

	/**
	 * Upload-time gate: expired/inactive/pending-deletion keys can't upload, and neither
	 * can a key that would cross either of its two independent caps.
	 */
	public function AssertCanUpload(int $incomingSize): void {
		$this->AssertCanImport($incomingSize, true);
	}

	/**
	 * Same validity gate as AssertCanUpload() (active/expiration/pending-deletion always
	 * apply), but the two numeric caps (usage_limit/usage_limit_mb) are only checked when
	 * $enforceQuota is true. Used by bulk import, which deliberately runs past a key's
	 * configured caps rather than stopping partway through a directory tree.
	 */
	public function AssertCanImport(int $incomingSize, bool $enforceQuota): void {
		$this->AssertUsable();
		if($this->HasPendingScheduledDeletion()) {
			ErrorCodes::Instance()->ThrowException(4014);
		}
		if(!$enforceQuota) return;
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
