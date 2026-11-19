<?php

namespace MagratheaExplorer\Key;

use Magrathea\ConfigApp;
use Magrathea\DB\Database;
use Magrathea\MagratheaModel;
use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\ExplorerConfig;
use MagratheaExplorer\Processing\RenditionProfile;

class Key extends MagratheaModel {

	/** ConfigApp `max_upload_size` when it's empty or unreadable. */
	const DEFAULT_MAX_UPLOAD_SIZE = 5 * 1024 * 1024;

	protected $dbTable = "access_keys";
	protected $dbPk = "id";
	protected $dbValues = [
		"id" => "int",
		// sha256 of the bearer secret (Secret::Hash()). The secret itself is never stored: it's
		// shown once, by KeyControl::Create() and RotateSecret(), and can't be read again.
		"secret_hash" => "string",
		// "uuid", so Insert() mints it: the public, non-secret storage directory name, in every
		// file URL.
		"storage_uuid" => "uuid",
		"name" => "string",
		// created through POST /provision/keys (provisioning's `max_keys` counts these)
		"provisioned" => "boolean",
		"uses" => "int",
		"usage_limit" => "int",
		"total_size" => "int",
		"usage_limit_mb" => "int",
		// what an upload that doesn't send `verbatim` does: null = the instance default
		// (see VerbatimByDefault())
		"verbatim_default" => "boolean",
		// bytes; null = the instance default (see EffectiveMaxUploadSize())
		"max_upload_size" => "int",
		// JSON list of rendition specs (RenditionProfile); null = the instance default (see RenditionSpecs())
		"rendition_profile" => "text",
		"expiration" => "datetime",
		"active" => "boolean",
	];
	protected $relations = [
		"properties" => ["Folders" => null, "Files" => null, "ScheduledDeletions" => null],
		"methods" => ["Folders" => "GetFolders", "Files" => "GetFiles", "ScheduledDeletions" => "GetScheduledDeletions"],
		"lazyload" => ["Folders" => true, "Files" => true, "ScheduledDeletions" => true],
	];

	public $id, $secret_hash, $storage_uuid, $name, $provisioned, $uses, $usage_limit, $total_size, $usage_limit_mb, $verbatim_default, $max_upload_size, $rendition_profile, $expiration, $active;

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
	 * applies: `usage_limit`/`usage_limit_mb`/`expiration` are nullable columns.) A limit of 0
	 * stays 0 (no uploads), "" becomes null (no limit). `storage_uuid` needs no line here: it's
	 * declared "uuid", so Insert() mints it. `secret_hash` is KeyControl::Create()'s.
	 */
	public function Normalize(): Key {
		if(!$this->uses) $this->uses = 0;
		if(!$this->total_size) $this->total_size = 0;
		if($this->usage_limit === "") $this->usage_limit = null;
		if($this->usage_limit_mb === "") $this->usage_limit_mb = null;
		if(empty($this->expiration)) $this->expiration = null;
		if($this->provisioned === null || $this->provisioned === "") $this->provisioned = false;
		if($this->active === null || $this->active === "") $this->active = 1;
		return $this;
	}

	/**
	 * Whether an upload that doesn't send `verbatim` is stored verbatim: the key's own
	 * setting, else the instance's. An explicit `verbatim` in the request always wins.
	 */
	public function VerbatimByDefault(): bool {
		return $this->verbatim_default !== null ? (bool)$this->verbatim_default : self::InstanceVerbatimDefault();
	}

	/** magrathea.conf `verbatim_default`. Absent = false: images are stripped and converted, as before 1.5.0. */
	public static function InstanceVerbatimDefault(): bool {
		return ExplorerConfig::GetBool("verbatim_default", false);
	}

	/**
	 * Largest upload this key accepts, in bytes: its own limit, else the instance's, never
	 * above what PHP lets through. Every upload source checks against this, and `GET /key`
	 * shows it: a limit PHP can't honor would be a lie.
	 */
	public function EffectiveMaxUploadSize(): int {
		return min($this->ConfiguredMaxUploadSize(), self::PhpMaxUploadSize());
	}

	/** The key's limit, else the instance's, before PHP's limits apply. */
	public function ConfiguredMaxUploadSize(): int {
		return $this->max_upload_size !== null ? (int)$this->max_upload_size : self::InstanceMaxUploadSize();
	}

	/** ConfigApp `max_upload_size` (admin → App Config): a byte count or "5M". Empty or unreadable = 5 MB, as before 1.5.0. */
	public static function InstanceMaxUploadSize(): int {
		$raw = ConfigApp::Instance()->Get("max_upload_size");
		if(empty($raw)) return self::DEFAULT_MAX_UPLOAD_SIZE;
		return ExplorerConfig::ParseSize($raw) ?? self::DEFAULT_MAX_UPLOAD_SIZE;
	}

	/** The smaller of PHP's `post_max_size` and `upload_max_filesize`. PHP_INT_MAX when neither limits anything. */
	public static function PhpMaxUploadSize(): int {
		return min(self::IniSizeToBytes(ini_get("post_max_size")), self::IniSizeToBytes(ini_get("upload_max_filesize")));
	}

	/** A PHP size setting in bytes. 0 (or below) is PHP's "no limit", and so is a setting that isn't there. */
	public static function IniSizeToBytes(string|false $value): int {
		if($value === false) return PHP_INT_MAX;
		$bytes = ExplorerConfig::ParseSize($value);
		return ($bytes === null || $bytes <= 0) ? PHP_INT_MAX : $bytes;
	}

	/**
	 * The renditions this key's images get: its own profile, else the instance default (one
	 * `thumb`, see RenditionProfile::Default()). An empty list means none.
	 * @throws \InvalidArgumentException the stored profile doesn't validate (edited by SQL?)
	 */
	public function RenditionSpecs(): array {
		return $this->HasOwnRenditionProfile()
			? RenditionProfile::Parse((string)$this->rendition_profile)
			: RenditionProfile::Default();
	}

	public function HasOwnRenditionProfile(): bool {
		return $this->rendition_profile !== null && trim((string)$this->rendition_profile) !== "";
	}

	/** Public, non-secret directory this key's objects live under. */
	public function StorageDir(): string {
		return $this->storage_uuid;
	}

	/** The one place a storage path is assembled, for both a file and its renditions. */
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
	 * usable at all (inactive, expired). Upload-specific gating is AssertCanUpload().
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
