<?php

namespace MagratheaExplorer\Key;

use MagratheaExplorer\ErrorCodes;

class ScheduledDeletionControl extends \MagratheaExplorer\Key\Base\ScheduledDeletionControlBase {

	const DAYS_UNTIL_DELETION = 14;

	public static function GetPendingFor(Key $key): ?ScheduledDeletion {
		return self::GetRowWhere([
			"key_id" => $key->id,
			"cancelled_at" => null,
		]);
	}

	public static function Schedule(Key $key): ScheduledDeletion {
		if(self::GetPendingFor($key) !== null) {
			ErrorCodes::Instance()->ThrowException(4006);
		}
		$deletion = new ScheduledDeletion();
		$deletion->key_id = $key->id;
		$deletion->requested_at = \Magrathea2\now();
		$deletion->execute_at = date("Y-m-d H:i:s", strtotime(\Magrathea2\now()." + ".self::DAYS_UNTIL_DELETION." days"));
		$deletion->Insert();
		return $deletion;
	}

	public static function Cancel(Key $key): ScheduledDeletion {
		$deletion = self::GetPendingFor($key);
		if($deletion === null) {
			ErrorCodes::Instance()->ThrowException(4007);
		}
		$deletion->cancelled_at = \Magrathea2\now();
		$deletion->Update();
		return $deletion;
	}

	/** @return ScheduledDeletion[] every pending row past its `execute_at` */
	public static function GetDue(): array {
		return self::GetSimpleWhere("`execute_at` <= '".\Magrathea2\now()."' AND `cancelled_at` IS NULL");
	}

	/**
	 * Sweeps every pending row past its `execute_at` and runs the real cascading delete.
	 * One try/catch per row so one bad key doesn't kill the whole cron run.
	 * @return array{deleted: int[], failed: array<int, string>} key ids deleted, and id => error message for failures
	 */
	public static function RunDueDeletions(): array {
		$due = self::GetDue();
		$deleted = [];
		$failed = [];
		foreach($due as $deletion) {
			try {
				$key = new Key($deletion->key_id);
				KeyControl::DeleteKeyNow($key);
				$deleted[] = $deletion->key_id;
			} catch(\Throwable $ex) {
				$failed[$deletion->key_id] = $ex->getMessage();
			}
		}
		return ["deleted" => $deleted, "failed" => $failed];
	}

}
