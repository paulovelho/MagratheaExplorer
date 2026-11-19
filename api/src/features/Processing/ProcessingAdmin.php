<?php

namespace MagratheaExplorer\Processing;

use Magrathea\Admin\AdminFeature;
use Magrathea\Admin\iAdminFeature;
use Magrathea\DB\Database;

/**
 * The processing queue at a glance: jobs and files by status, the latest failures, and whether
 * the processor is configured at all. Read-only: a file is reprocessed from the Browser
 * (Regenerate) or the API.
 */
class ProcessingAdmin extends AdminFeature implements iAdminFeature {

	public string $featureName = "Processing";
	public string $featureId = "AdminProcessing";

	public function __construct() {
		parent::__construct();
		$this->SetClassPath(__DIR__);
	}

	public function Index() {
		$configError = null;
		try {
			$runner = ProcessorRunner::FromConfig();
		} catch(\Throwable $ex) {
			$runner = null;
			$configError = $ex->getMessage();
		}
		$jobCounts = ProcessingJobControl::CountsByStatus();
		$fileCounts = array_fill_keys(["none", "pending", "ready", "failed"], 0);
		foreach(Database::Instance()->QueryAll("SELECT `processing_status`, COUNT(*) AS `n` FROM `files` GROUP BY `processing_status`") as $row) {
			$fileCounts[$row["processing_status"]] = (int)$row["n"];
		}
		$oldestQueued = Database::Instance()->QueryOne("SELECT MIN(`created_at`) FROM `processing_jobs` WHERE `status` = 'queued'");
		$failures = ProcessingJobControl::RecentFailures(20);
		$garbage = \MagratheaExplorer\Storage\StorageGarbage::Counts();
		$stuckGarbage = $garbage["stuck"] > 0 ? \MagratheaExplorer\Storage\StorageGarbage::Stuck(20) : [];
		include(__DIR__."/admin/index.php");
	}

}
