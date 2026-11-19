<?php

namespace MagratheaExplorer\Provisioning;

use Magrathea\MagratheaModel;

/**
 * The instance's provisioning settings: one row (id 1), edited in admin → Provisioning.
 * - `secret_hash`: sha256 of the secret POST /provision/keys takes (Secret::Hash()). null = off.
 * - `max_keys`: how many provisioned keys may exist at once, pending deletions included (they
 *   still hold files). null = no limit.
 * - The settings every provisioned key copies when it's created (KeySettings::FIELDS, same
 *   meaning as on a key: null = the instance default). Changing them later doesn't touch
 *   existing keys: those are edited one by one in admin → Keys.
 */
class Provisioning extends MagratheaModel {

	protected $dbTable = "provisioning";
	protected $dbPk = "id";
	protected $dbValues = [
		"id" => "int",
		"secret_hash" => "string",
		"max_keys" => "int",
		"usage_limit" => "int",
		"usage_limit_mb" => "int",
		"verbatim_default" => "boolean",
		"max_upload_size" => "int",
		"rendition_profile" => "text",
		"last_used_at" => "datetime",
	];

	public $id, $secret_hash, $max_keys, $usage_limit, $usage_limit_mb, $verbatim_default, $max_upload_size, $rendition_profile, $last_used_at;

	public function IsOn(): bool {
		return $this->secret_hash !== null && $this->secret_hash !== "";
	}

	/**
	 * The most storage provisioning can ever commit, in MB: `max_keys` × the `usage_limit_mb`
	 * each key gets. null = unbounded (either one is unset).
	 */
	public function WorstCaseMb(): ?int {
		if($this->max_keys === null || $this->usage_limit_mb === null) return null;
		return (int)$this->max_keys * (int)$this->usage_limit_mb;
	}

	public function HasOwnRenditionProfile(): bool {
		return $this->rendition_profile !== null && trim((string)$this->rendition_profile) !== "";
	}

}
