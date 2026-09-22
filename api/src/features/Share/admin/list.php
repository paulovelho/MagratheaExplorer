<?php

use Magrathea2\Admin\AdminCsrf;
use Magrathea2\Admin\AdminElements;
use Magrathea2\Admin\AdminUrls;
use MagratheaExplorer\Share\ShareApi;

$elements = AdminElements::Instance();
$elements->Header("Shares");

// The delete button is a raw <form method="post">, so it needs the token explicitly --
// without it the POST is rejected before it ever reaches ShareAdmin::Delete().
$csrfField = '<input type="hidden" name="magrathea_csrf_token" value="'.AdminCsrf::Instance()->GetToken().'">';

?>
<div class="container">
	<div class="row">
		<div class="col-12">
			<div class="card">
				<div class="card-header">Share links (<?=count($shares)?>)</div>
				<div class="card-body">
				<?
				$elements->Table($shares, [
					["title" => "Key", "key" => function($s) {
						$key = $s->GetKey();
						return $key !== null ? htmlspecialchars($key->name) : "—";
					}],
					["title" => "Type", "key" => function($s) { return $s->TargetType(); }],
					["title" => "Target", "key" => function($s) { return htmlspecialchars($s->TargetName()); }],
					["title" => "Link", "key" => function($s) {
						$url = ShareApi::ShareUrl($s->uuid);
						return "<a href='".htmlspecialchars($url)."' target='_blank' rel='noopener'>".htmlspecialchars($s->uuid)."</a>";
					}],
					["title" => "Views", "key" => "views"],
					["title" => "Last Viewed", "key" => function($s) { return $s->last_viewed_at ?: "never"; }],
					["title" => "Created", "key" => "created_at"],
					["title" => "...", "key" => function($s) use ($csrfField) {
						return '<form method="post" style="display:inline" action="'.AdminUrls::Instance()->GetFeatureActionUrl("AdminShare", "Delete").'"'
							.' onsubmit="return confirm(\'Delete this share link? The share page stops working; direct file URLs already copied keep working.\');">'
							.$csrfField
							.'<input type="hidden" name="id" value="'.$s->id.'"><button type="submit" class="btn btn-sm btn-danger">delete</button></form>';
					}],
				]);
				?>
				</div>
			</div>
		</div>
	</div>
</div>
