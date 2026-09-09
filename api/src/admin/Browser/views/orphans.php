<?php

use Magrathea2\Admin\AdminCsrf;
use Magrathea2\Admin\AdminElements;
use Magrathea2\Admin\AdminUrls;

$elements = AdminElements::Instance();
$elements->Header("Orphan Check");
$csrfField = '<input type="hidden" name="magrathea_csrf_token" value="'.AdminCsrf::Instance()->GetToken().'">';

?>
<div class="container">
	<div class="row">
		<div class="col-12">
			<div class="card">
				<div class="card-header">
					Files whose DB row points at a missing storage object (<?=count($missing)?>)
				</div>
				<div class="card-body">
				<? if(count($missing) === 0): ?>
					<p>No orphans found.</p>
				<? else: ?>
				<?
				$elements->Table($missing, [
					["title" => "#ID", "key" => "id"],
					["title" => "Name", "key" => "name"],
					["title" => "Key ID", "key" => "key_id"],
					["title" => "storage_path", "key" => "storage_path"],
					["title" => "...", "key" => function($f) use ($csrfField) {
						return '<form method="post" style="display:inline" action="'.AdminUrls::Instance()->GetFeatureActionUrl("AdminBrowser", "PurgeOrphan").'"'
							.' onsubmit="return confirm(\'Delete this DB row? The storage object is already gone.\');">'
							.$csrfField
							.'<input type="hidden" name="id" value="'.$f->id.'">'
							.'<button type="submit" class="btn btn-sm btn-danger">purge row</button></form>';
					}],
				]);
				?>
				<? endif; ?>
				</div>
			</div>
		</div>
	</div>
</div>
