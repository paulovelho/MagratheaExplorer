<?php

use Magrathea2\Admin\AdminCsrf;
use Magrathea2\Admin\AdminElements;
use Magrathea2\Admin\AdminUrls;

$elements = AdminElements::Instance();
$csrfField = '<input type="hidden" name="magrathea_csrf_token" value="'.AdminCsrf::Instance()->GetToken().'">';

if($key === null) {
	echo $elements->ErrorCard("Key not found");
	return;
}

?>
<div class="card">
	<div class="card-header"><?=($key->id ? "Edit Key" : "New Key")?></div>
	<div class="card-body">
		<form method="post" action="<?=AdminUrls::Instance()->GetFeatureActionUrl("AdminKey", "Save")?>">
			<?=$csrfField?>
			<div class="row">
				<? if($key->id): ?>
					<div class="col-2"><? $elements->Input("disabled", "id", "#ID", $key->id); ?></div>
					<div class="col-3"><? $elements->Input("disabled", "uuid_display", "UUID", $key->uuid); ?></div>
					<input type="hidden" name="id" value="<?=$key->id?>">
				<? endif; ?>
				<div class="col-3"><? $elements->Input("text", "name", "Name", $key->name); ?></div>
				<div class="col-2"><? $elements->Input("text", "usage_limit", "Upload Limit", $key->usage_limit); ?></div>
				<div class="col-2"><? $elements->Input("text", "usage_limit_mb", "Size Limit (MB)", $key->usage_limit_mb); ?></div>
			</div>
			<div class="row mt-2">
				<div class="col-3"><? $elements->Input("date", "expiration", "Expiration", $key->expiration); ?></div>
				<div class="col-2">
					<input type="hidden" name="active" value="0">
					<? $elements->Checkbox("active", "Active", 1, ($key->id ? (bool)$key->active : true)); ?>
				</div>
			</div>
			<div class="row mt-3">
				<div class="col-2"><? $elements->Button("Save", "", "btn-success w-100", "", true); ?></div>
			</div>
		</form>
		<? if($key->id): ?>
		<form method="post" action="<?=AdminUrls::Instance()->GetFeatureActionUrl("AdminKey", "ForceDeleteNow")?>"
			onsubmit="return confirm('This deletes the key and every file it owns immediately, bypassing the 14-day wait. Are you sure?');" class="mt-4">
			<?=$csrfField?>
			<input type="hidden" name="id" value="<?=$key->id?>">
			<? $elements->Button("Force Delete Now", "", "btn-danger", "", true); ?>
		</form>
		<? endif; ?>
	</div>
</div>
