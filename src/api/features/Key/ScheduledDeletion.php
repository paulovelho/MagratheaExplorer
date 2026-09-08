<?php

namespace MagratheaExplorer\Key;

class ScheduledDeletion extends \MagratheaExplorer\Key\Base\ScheduledDeletionBase {

	public function IsPending(): bool {
		return empty($this->cancelled_at);
	}

	public function IsDue(): bool {
		return $this->IsPending() && $this->execute_at <= \Magrathea2\now();
	}

}
