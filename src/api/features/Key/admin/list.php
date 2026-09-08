<?php

use Magrathea2\Admin\AdminElements;
use Magrathea2\Admin\AdminUrls;
use Magrathea2\MagratheaHelper;

$elements = AdminElements::Instance();

?>
<div class="card">
	<div class="card-header">Keys</div>
	<div class="card-body">
	<?
	$elements->Table($keys, [
		["title" => "#ID", "key" => "id"],
		["title" => "Name", "key" => "name"],
		["title" => "Uses", "key" => function($k) {
			return $k->uses.($k->usage_limit !== null ? " / ".$k->usage_limit : "");
		}],
		["title" => "Storage", "key" => function($k) {
			$used = MagratheaHelper::FormatSize((int)$k->total_size);
			return $k->usage_limit_mb !== null ? $used." / ".$k->usage_limit_mb."MB" : $used;
		}],
		["title" => "Active", "key" => function($k) { return $k->active ? "yes" : "no"; }],
		["title" => "Expiration", "key" => "expiration"],
		["title" => "...", "key" => function($k) {
			$editUrl = AdminUrls::Instance()->GetFeatureUrl("AdminKey", "Form", ["id" => $k->id]);
			return "<a href='".$editUrl."'>edit</a>";
		}],
	]);
	?>
	</div>
</div>
