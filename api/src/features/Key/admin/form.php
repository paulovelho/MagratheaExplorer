<?php

use Magrathea\Admin\AdminCsrf;
use Magrathea\Admin\AdminElements;
use Magrathea\Admin\AdminUrls;

$elements = AdminElements::Instance();
$elements->Header($key !== null && $key->id ? "Edit Key" : "New Key");
$csrfField = '<input type="hidden" name="magrathea_csrf_token" value="'.AdminCsrf::Instance()->GetToken().'">';

if($key === null) {
	echo $elements->ErrorCard("Key not found");
	return;
}

// a refused name comes back as typed; everything else shows the stored values
$nameValue = $formError !== null && $formError["field"] === "name" && $formError["value"] !== null ? $formError["value"] : $key->name;
// the date input takes YYYY-MM-DD only: given the stored "YYYY-MM-DD HH:MM:SS" it showed nothing,
// and saving the form cleared the expiration
$expirationValue = $key->expiration !== null ? substr((string)$key->expiration, 0, 10) : "";
$settingsOf = $key;

?>
<div class="container">
	<div class="row">
		<div class="col-12">
			<div class="card">
				<div class="card-header"><?=($key->id ? "Edit Key" : "New Key")?></div>
				<div class="card-body">
					<? if($secret !== null): ?>
						<? $elements->Alert(
							"<strong>This key's secret:</strong> <code class='user-select-all'>".htmlspecialchars($secret)."</code><br>"
							."Copy it now: it's shown only this once. Only its hash is stored, so it can't be read again, here or anywhere. "
							."If it's lost, rotate it below and give the integration the new one.",
							"success", false); ?>
					<? endif; ?>
					<? if($formError !== null): ?>
						<? $elements->Alert("<strong>Nothing was saved.</strong> ".htmlspecialchars((string)$formError["message"]), "danger", false); ?>
					<? endif; ?>
					<form method="post" action="<?=AdminUrls::Instance()->GetFeatureActionUrl("AdminKey", "Save")?>">
						<?=$csrfField?>
						<div class="row">
							<? if($key->id): ?>
								<div class="col-1"><? $elements->Input("disabled", "id", "#ID", $key->id); ?></div>
								<div class="col-3"><? $elements->Input("disabled", "storage_uuid_display", "Storage dir (public)", $key->storage_uuid); ?></div>
								<div class="col-2"><? $elements->Input("disabled", "provisioned_display", "Created by", $key->provisioned ? "provisioning" : "admin"); ?></div>
								<input type="hidden" name="id" value="<?=(int)$key->id?>">
							<? endif; ?>
							<div class="col-3"><? $elements->Input("text", "name", "Name", $nameValue); ?></div>
							<div class="col-2"><? $elements->Input("date", "expiration", "Expiration", $expirationValue); ?></div>
							<div class="col-1">
								<input type="hidden" name="active" value="0">
								<? $elements->Checkbox("active", "Active", 1, ($key->id ? (bool)$key->active : true)); ?>
							</div>
						</div>
						<? include(__DIR__."/settings-fields.php"); ?>
						<div class="row mt-3">
							<div class="col-2"><? $elements->Button("Save", "", "btn-success w-100", "", true); ?></div>
						</div>
					</form>
					<? if($key->id): ?>
					<div class="d-flex gap-2 mt-4">
						<form method="post" action="<?=AdminUrls::Instance()->GetFeatureActionUrl("AdminKey", "RotateSecret")?>"
							onsubmit="return confirm('The current secret stops working immediately: every integration using this key fails until it gets the new one. Rotate?');">
							<?=$csrfField?>
							<input type="hidden" name="id" value="<?=(int)$key->id?>">
							<? $elements->Button("Rotate secret", "", "btn-warning", "", true); ?>
						</form>
						<form method="post" action="<?=AdminUrls::Instance()->GetFeatureActionUrl("AdminKey", "ForceDeleteNow")?>"
							onsubmit="return confirm('This deletes the key and every file it owns immediately, bypassing the 14-day wait. Are you sure?');">
							<?=$csrfField?>
							<input type="hidden" name="id" value="<?=(int)$key->id?>">
							<? $elements->Button("Force Delete Now", "", "btn-danger", "", true); ?>
						</form>
					</div>
					<? endif; ?>
				</div>
			</div>
		</div>
	</div>
</div>
