<?php

use Magrathea2\Admin\AdminCsrf;
use Magrathea2\Admin\AdminElements;
use Magrathea2\Admin\AdminUrls;
use MagratheaExplorer\Key\ScheduledDeletionControl;

$elements = AdminElements::Instance();
$elements->Header("Scheduled Deletions");
$csrfField = '<input type="hidden" name="magrathea_csrf_token" value="'.AdminCsrf::Instance()->GetToken().'">';

$pending = ScheduledDeletionControl::GetSimpleWhere("`cancelled_at` IS NULL ORDER BY `execute_at` ASC");
$cancelled = ScheduledDeletionControl::GetSimpleWhere("`cancelled_at` IS NOT NULL ORDER BY `cancelled_at` DESC LIMIT 20");

?>
<div class="container">
	<div class="row">
		<div class="col-12">
			<div class="card">
				<div class="card-header">Pending (<?=count($pending)?>)</div>
				<div class="card-body">
				<?
				$elements->Table($pending, [
					["title" => "Key", "key" => function($d) { return $d->GetKey()->name; }],
					["title" => "Requested At", "key" => "requested_at"],
					["title" => "Execute At", "key" => "execute_at"],
					["title" => "...", "key" => function($d) use ($csrfField) {
						$cancelForm = '<form method="post" style="display:inline" action="'.AdminUrls::Instance()->GetFeatureActionUrl("AdminScheduledDeletion", "Cancel").'">'
							.$csrfField
							.'<input type="hidden" name="id" value="'.$d->id.'"><button type="submit" class="btn btn-sm btn-secondary">cancel</button></form>';
						$forceForm = '<form method="post" style="display:inline" action="'.AdminUrls::Instance()->GetFeatureActionUrl("AdminScheduledDeletion", "ForceExecute").'"'
							.' onsubmit="return confirm(\'Delete this key and every file it owns right now?\');">'
							.$csrfField
							.'<input type="hidden" name="id" value="'.$d->id.'"><button type="submit" class="btn btn-sm btn-danger">force execute</button></form>';
						return $cancelForm." ".$forceForm;
					}],
				]);
				?>
				</div>
			</div>
		</div>
	</div>
	<div class="row mt-3">
		<div class="col-12">
			<div class="card">
				<div class="card-header">Recently Cancelled</div>
				<div class="card-body">
				<?
				$elements->Table($cancelled, [
					["title" => "Key", "key" => function($d) { return $d->GetKey()->name; }],
					["title" => "Requested At", "key" => "requested_at"],
					["title" => "Cancelled At", "key" => "cancelled_at"],
				]);
				?>
				</div>
			</div>
		</div>
	</div>
</div>
