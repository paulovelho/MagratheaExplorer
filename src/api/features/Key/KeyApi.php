<?php

namespace MagratheaExplorer\Key;

use MagratheaExplorer\ExplorerApiControl;

class KeyApi extends ExplorerApiControl {

	/** GET /key -- self-service view of the caller's own key. Never echoes the uuid back. */
	public function GetSelf(): array {
		$key = $this->GetRequestKey();
		return $this->KeyView($key);
	}

	/** GET /key/usage -- reads `total_size`/`uses` directly, no live SUM(). */
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
			"execute_at" => $deletion->execute_at,
		];
	}

	/** POST /key/cancel-deletion */
	public function CancelDeletion(): array {
		$key = $this->GetRequestKey();
		ScheduledDeletionControl::Cancel($key);
		return ["cancelled" => true];
	}

	private function KeyView(Key $key): array {
		return [
			"name" => $key->name,
			"uses" => (int)$key->uses,
			"usage_limit" => $key->usage_limit !== null ? (int)$key->usage_limit : null,
			"total_size" => (int)$key->total_size,
			"usage_limit_mb" => $key->usage_limit_mb !== null ? (int)$key->usage_limit_mb : null,
			"expiration" => $key->expiration,
			"active" => (bool)$key->active,
			"created_at" => $key->created_at,
		];
	}

}
