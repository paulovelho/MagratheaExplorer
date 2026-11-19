<?php

use Magrathea\Admin\AdminElements;
use Magrathea\Admin\AdminUrls;
use Magrathea\MagratheaHelper;
use MagratheaExplorer\Key\KeyAdmin;
use MagratheaExplorer\Processing\RenditionProfile;

$elements = AdminElements::Instance();
$urls = AdminUrls::Instance();

// $keys: a MagratheaPagination of KeyAdmin::PAGE_SIZE, with its total; $origin: the filter
$pageCount = max(1, (int)ceil(((int)$keys->total) / KeyAdmin::PAGE_SIZE));
$pageUrl = fn(int $page) => $urls->GetFeatureUrl("AdminKey", null, array_filter(["origin" => $origin !== "all" ? $origin : null, "page" => $page > 0 ? $page : null]));
$originLabels = ["all" => "All", "admin" => "Made in the admin", "provisioned" => "Provisioned"];

?>
<div class="card">
	<div class="card-header d-flex justify-content-between align-items-center">
		<span>Keys (<?=(int)$keys->total?>)</span>
		<span>
			<? foreach($originLabels as $value => $label): ?>
				<a class="btn btn-sm <?=($origin === $value ? "btn-secondary" : "btn-outline-secondary")?>"
					href="<?=$urls->GetFeatureUrl("AdminKey", null, $value !== "all" ? ["origin" => $value] : [])?>"><?=$label?></a>
			<? endforeach; ?>
		</span>
	</div>
	<div class="card-body">
	<?
	$elements->Table($keys->data, [
		["title" => "#ID", "key" => "id"],
		["title" => "Name", "key" => function($k) {
			return htmlspecialchars((string)$k->name).($k->provisioned ? " <span class='badge bg-info'>provisioned</span>" : "");
		}],
		["title" => "Uses", "key" => function($k) {
			return $k->uses.($k->usage_limit !== null ? " / ".$k->usage_limit : "");
		}],
		["title" => "Storage", "key" => function($k) {
			$used = MagratheaHelper::FormatSize((int)$k->total_size);
			return $k->usage_limit_mb !== null ? $used." / ".$k->usage_limit_mb."MB" : $used;
		}],
		["title" => "Max upload", "key" => function($k) {
			// effective; flagged when PHP's limits cut the configured one
			$effective = MagratheaHelper::FormatSize($k->EffectiveMaxUploadSize());
			$text = $k->max_upload_size !== null ? $effective : $effective." (default)";
			return $k->ConfiguredMaxUploadSize() > $k->EffectiveMaxUploadSize() ? $text." <span class='text-danger'>capped by PHP</span>" : $text;
		}],
		["title" => "Renditions", "key" => function($k) {
			try {
				$text = htmlspecialchars(RenditionProfile::Describe($k->RenditionSpecs()));
			} catch(\InvalidArgumentException $ex) {
				return "<span class='text-danger'>invalid profile</span>";
			}
			return $k->HasOwnRenditionProfile() ? $text : $text." (default)";
		}],
		["title" => "Active", "key" => function($k) { return $k->active ? "yes" : "no"; }],
		["title" => "Expiration", "key" => "expiration"],
		["title" => "...", "key" => function($k) use ($urls) {
			$editUrl = $urls->GetFeatureUrl("AdminKey", "Form", ["id" => $k->id]);
			return "<a href='".$editUrl."'>edit</a>";
		}],
	]);
	?>
	<? if($pageCount > 1): ?>
		<nav class="mt-3 d-flex align-items-center gap-2">
			<? if($keys->page > 0): ?>
				<a class="btn btn-sm btn-outline-secondary" href="<?=$pageUrl($keys->page - 1)?>">&larr; newer</a>
			<? endif; ?>
			<span class="text-muted">page <?=($keys->page + 1)?> of <?=$pageCount?></span>
			<? if($keys->hasMore): ?>
				<a class="btn btn-sm btn-outline-secondary" href="<?=$pageUrl($keys->page + 1)?>">older &rarr;</a>
			<? endif; ?>
		</nav>
	<? endif; ?>
	</div>
</div>
