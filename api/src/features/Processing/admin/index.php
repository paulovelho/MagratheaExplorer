<?php

use Magrathea\Admin\AdminElements;
use Magrathea\Admin\AdminUrls;

$elements = AdminElements::Instance();
$elements->Header("Processing");

?>
<div class="container">
	<div class="row">
		<div class="col-12">
			<? if($configError !== null): ?>
				<? $elements->Alert("<strong>The processor settings can't be read:</strong> ".htmlspecialchars($configError)." Image uploads end up with processing status <code>failed</code>.", "danger", false); ?>
			<? elseif($runner === null): ?>
				<? $elements->Alert("<strong>No processor configured</strong> (<code>processor_command</code> in magrathea.conf). Images get no properties and no renditions (status <code>none</code>), and queued jobs wait. See docs/deploy.md, \"Image processor\".", "warning", false); ?>
			<? else: ?>
				<? $elements->Alert("Processor: <code>".htmlspecialchars(implode(" ", $runner->command))."</code>, timeout ".$runner->timeoutS." s, memory ".$runner->memoryMb." MB. Check it with <code>php api/processor/run-fixtures.php</code>.", "light", false); ?>
			<? endif; ?>
		</div>
	</div>
	<div class="row mt-2">
		<div class="col-6">
			<div class="card">
				<div class="card-header">Jobs</div>
				<div class="card-body">
				<?
				$elements->Table(array_map(fn($status, $n) => [$status, $n], array_keys($jobCounts), $jobCounts), [
					["title" => "Status", "key" => 0],
					["title" => "Jobs", "key" => 1],
				]);
				?>
				<small class="text-muted">
					<? if($oldestQueued !== null): ?>Oldest queued job: <?=htmlspecialchars((string)$oldestQueued)?>.<? endif; ?>
					Queued jobs run from <code>cron.php --process</code> (every minute). Finished jobs are deleted after 30 days.
				</small>
				</div>
			</div>
		</div>
		<div class="col-6">
			<div class="card">
				<div class="card-header">Files</div>
				<div class="card-body">
				<?
				$elements->Table(array_map(fn($status, $n) => [$status, $n], array_keys($fileCounts), $fileCounts), [
					["title" => "Processing status", "key" => 0],
					["title" => "Files", "key" => 1],
				]);
				?>
				</div>
			</div>
		</div>
	</div>
	<div class="row mt-3">
		<div class="col-12">
			<div class="card">
				<div class="card-header">Storage deletes to retry (<?=$garbage["queued"]?>)</div>
				<div class="card-body">
				<p class="mb-2">
					Objects nothing points at any more (replaced by a rotation or a new processing run, or left by
					an upload that didn't commit) whose delete failed. <code>cron.php --process</code> retries
					<?=\MagratheaExplorer\Storage\StorageGarbage::RETRY_PER_RUN?> a run, up to
					<?=\MagratheaExplorer\Storage\StorageGarbage::MAX_ATTEMPTS?> times each.
				</p>
				<? if(count($stuckGarbage) > 0): ?>
				<? $elements->Alert("<strong>".(int)$garbage["stuck"]." object(s) failed ".\MagratheaExplorer\Storage\StorageGarbage::MAX_ATTEMPTS." times</strong> and are no longer retried. Check the storage credentials and permissions, delete the objects by hand, then the rows from <code>storage_garbage</code>.", "warning", false); ?>
				<?
				$elements->Table($stuckGarbage, [
					["title" => "Path", "key" => "storage_path"],
					["title" => "Attempts", "key" => "attempts"],
					["title" => "Last error", "key" => "last_error"],
					["title" => "Queued", "key" => "created_at"],
					["title" => "Last try", "key" => "updated_at"],
				]);
				?>
				<? endif; ?>
				</div>
			</div>
		</div>
	</div>
	<div class="row mt-3">
		<div class="col-12">
			<div class="card">
				<div class="card-header">Last failed jobs (<?=count($failures)?>)</div>
				<div class="card-body">
				<? if(count($failures) === 0): ?>
					<p>No failures.</p>
				<? else: ?>
				<?
				$elements->Table($failures, [
					["title" => "Job", "key" => "id"],
					["title" => "File", "key" => function($row) {
						$url = AdminUrls::Instance()->GetFeatureUrl("AdminBrowser", null, ["key_id" => (int)$row["key_id"]]);
						return "#".(int)$row["file_id"]." <a href='".htmlspecialchars($url, ENT_QUOTES)."'>".htmlspecialchars((string)$row["file_name"])."</a>";
					}],
					["title" => "File now", "key" => "processing_status"],
					["title" => "Attempts", "key" => "attempts"],
					["title" => "Error", "key" => "error"],
					["title" => "When", "key" => "updated_at"],
				]);
				?>
				<? endif; ?>
				</div>
			</div>
		</div>
	</div>
</div>
