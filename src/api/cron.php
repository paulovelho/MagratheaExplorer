<?php
// CLI-only entrypoint. Bootstrap shape mirrors Images3's backup.php (chdir + require
// _inc.php + getopt), but the flags are this script's own -- it sweeps
// scheduled_deletions, a different job from backup.php's push/reconcile.
chdir(__DIR__);
require "_inc.php";

use Magrathea2\MagratheaPHP;
use MagratheaExplorer\Key\ScheduledDeletionControl;

$options = getopt("", ["run", "dry-run", "verbose"]);
$run = isset($options["run"]);
$dryRun = isset($options["dry-run"]);
$verbose = isset($options["verbose"]);

if(!$run && !$dryRun) {
	fwrite(STDERR, "Usage: php cron.php --run [--verbose]  |  php cron.php --dry-run [--verbose]\n");
	exit(1);
}

MagratheaPHP::Instance()->StartDb();

if($dryRun) {
	$due = ScheduledDeletionControl::GetDue();
	echo count($due)." key(s) due for deletion.\n";
	if($verbose) {
		foreach($due as $deletion) {
			echo "  key_id=".$deletion->key_id." execute_at=".$deletion->execute_at."\n";
		}
	}
	exit(0);
}

$result = ScheduledDeletionControl::RunDueDeletions();
echo count($result["deleted"])." key(s) deleted, ".count($result["failed"])." failure(s).\n";
if($verbose) {
	foreach($result["deleted"] as $keyId) {
		echo "  deleted key_id=".$keyId."\n";
	}
	foreach($result["failed"] as $keyId => $message) {
		echo "  FAILED key_id=".$keyId.": ".$message."\n";
	}
}
exit(count($result["failed"]) > 0 ? 1 : 0);
