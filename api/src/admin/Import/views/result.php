<?php

use Magrathea2\Admin\AdminElements;
use Magrathea2\Admin\AdminUrls;
use Magrathea2\MagratheaHelper;

$elements = AdminElements::Instance();
$elements->Header("Import result");

$backUrl = AdminUrls::Instance()->GetFeatureUrl("AdminImport", null, ["key_id" => $key->id]);

?>
<div class="container">
	<div class="row">
		<div class="col-12">
			<div class="card">
				<div class="card-header">Imported "<?=htmlspecialchars(basename($realPath))?>" into key "<?=htmlspecialchars($key->name)?>"</div>
				<div class="card-body">
					<?
					$elements->Table([
						["label" => "Folders touched", "value" => number_format($stats["folders_touched"])],
						["label" => "Files imported", "value" => number_format($stats["files_imported"])],
						["label" => "Files skipped", "value" => number_format(count($stats["files_skipped"]))],
						["label" => "Total size imported", "value" => MagratheaHelper::FormatSize((int)$stats["bytes_imported"])],
					], [
						["title" => "Metric", "key" => "label"],
						["title" => "Value", "key" => "value"],
					]);
					?>
					<? if(!empty($stats["files_skipped"])): ?>
					<h6 class="mt-3">Skipped files</h6>
					<?
					$elements->Table($stats["files_skipped"], [
						["title" => "Path", "key" => "path"],
						["title" => "Reason", "key" => "reason"],
					]);
					?>
					<? endif; ?>
					<a class="btn btn-primary mt-3" href="<?=$backUrl?>">Back to Import</a>
				</div>
			</div>
		</div>
	</div>
</div>
