<?php

namespace MagratheaExplorer\Key;

use MagratheaExplorer\ApiTime;
use MagratheaExplorer\ExplorerApiControl;

class KeyApi extends ExplorerApiControl {

	/** GET /key -- self-service view of the caller's own key. Never the secret: it isn't stored. */
	public function GetSelf(): array {
		$key = $this->GetRequestKey();
		return self::View($key);
	}

	/**
	 * POST /key/rotate-secret -- a new secret for the calling key, returned once. The secret this
	 * request was made with fails from the next request on. If this response is lost, the key is
	 * locked out until an operator rotates it in the admin: store the new secret before
	 * discarding the old one.
	 */
	public function RotateSecret(): array {
		$key = $this->GetRequestKey();
		$secret = KeyControl::RotateSecret($key);
		KeyLog::Write("secret rotated", $key);
		return ["secret" => $secret];
	}

	/** GET /key/usage -- reads `total_size`/`uses` directly, no live SUM(). Originals only: renditions never count. */
	public function GetUsage(): array {
		$key = $this->GetRequestKey();
		return [
			"uses" => (int)$key->uses,
			"usage_limit" => $key->usage_limit !== null ? (int)$key->usage_limit : null,
			"total_size" => (int)$key->total_size,
			"usage_limit_mb" => $key->usage_limit_mb !== null ? (int)$key->usage_limit_mb : null,
		];
	}

	/** DELETE /key -- schedules deletion 14 days out; does not delete anything immediately. */
	public function Delete($params = false): array {
		$key = $this->GetRequestKey();
		$deletion = ScheduledDeletionControl::Schedule($key);
		return [
			"scheduled" => true,
			"execute_at" => ApiTime::Utc($deletion->execute_at),
		];
	}

	/** POST /key/cancel-deletion */
	public function CancelDeletion(): array {
		$key = $this->GetRequestKey();
		ScheduledDeletionControl::Cancel($key);
		return ["cancelled" => true];
	}

	private static function RenditionProfileView(Key $key): ?array {
		try {
			return $key->RenditionSpecs();
		} catch(\InvalidArgumentException $ex) {
			return null;
		}
	}

	/** A key as GET /key shows it (POST /provision/keys too, plus `secret`). */
	public static function View(Key $key): array {
		return [
			"name" => $key->name,
			"uses" => (int)$key->uses,
			"usage_limit" => $key->usage_limit !== null ? (int)$key->usage_limit : null,
			"total_size" => (int)$key->total_size,
			"usage_limit_mb" => $key->usage_limit_mb !== null ? (int)$key->usage_limit_mb : null,
			// effective: what an upload without `verbatim` does (the key's setting, else the instance's)
			"verbatim_default" => $key->VerbatimByDefault(),
			// effective, in bytes: the key's limit, else the instance's, capped by PHP's
			"max_upload_size" => $key->EffectiveMaxUploadSize(),
			// effective: the specs this key's image uploads get (its own profile, else the
			// instance default). null when the stored profile doesn't validate: uploads then
			// end up with processing status `failed`, which names the problem.
			"rendition_profile" => self::RenditionProfileView($key),
			"expiration" => ApiTime::Utc($key->expiration),
			"active" => (bool)$key->active,
			"created_at" => ApiTime::Utc($key->created_at),
		];
	}

}
