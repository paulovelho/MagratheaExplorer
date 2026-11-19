<?php

use Magrathea\Admin\AdminCsrf;
use Magrathea\Admin\AdminElements;
use Magrathea\Admin\AdminUrls;
use Magrathea\MagratheaHelper;

$elements = AdminElements::Instance();
$urls = AdminUrls::Instance();
$elements->Header("Provisioning");
$csrfField = '<input type="hidden" name="magrathea_csrf_token" value="'.AdminCsrf::Instance()->GetToken().'">';

// $provisioning, $keyCount, $pendingCount, $formError, $secret: ProvisioningAdmin::Index()
$settingsOf = $provisioning;
$worstCase = $provisioning->WorstCaseMb();
$maxKeysValue = $formError !== null && $formError["field"] === "max_keys" && $formError["value"] !== null
	? $formError["value"]
	: $provisioning->max_keys;

?>
<div class="container">
	<div class="row">
		<div class="col-12">
			<p class="text-muted">
				A consuming project (Turbopod) creates keys itself with <code>POST /api/v1/provision/keys</code> and the secret below,
				one key per tenant. That's all the secret can do: it can't read, change or delete any key, existing ones included.
				Each key it creates is then used and offboarded with that key's own secret, and its limits are changed by hand, in
				<a href="<?=$urls->GetFeatureUrl("AdminKey", null, ["origin" => "provisioned"])?>">Keys</a>.
			</p>
		</div>
	</div>

	<div class="row">
		<div class="col-12">
			<div class="card">
				<div class="card-header">Secret</div>
				<div class="card-body">
					<? if($secret !== null): ?>
						<? $elements->Alert(
							"<strong>The provisioning secret:</strong> <code class='user-select-all'>".htmlspecialchars($secret)."</code><br>"
							."Copy it now: it's shown only this once, and only its hash is stored. It goes in the consuming project's "
							."server-side config, never in a browser. If it's lost, generate a new one.",
							"success", false); ?>
					<? endif; ?>
					<? if($provisioning->IsOn()): ?>
						<p>
							<span class="badge bg-success">on</span>
							Last used: <?=($provisioning->last_used_at !== null ? htmlspecialchars((string)$provisioning->last_used_at) : "never")?>
							<small class="text-muted">(to the minute)</small>
						</p>
					<? else: ?>
						<p><span class="badge bg-secondary">off</span> No secret: <code>POST /provision/keys</code> refuses every request.</p>
					<? endif; ?>
					<div class="d-flex gap-2">
						<form method="post" action="<?=$urls->GetFeatureActionUrl("AdminProvisioning", "GenerateSecret")?>"
							<?=($provisioning->IsOn() ? "onsubmit=\"return confirm('The current secret stops working immediately: the consuming project cannot create keys until it gets the new one. Keys it already made keep working. Generate a new secret?');\"" : "")?>>
							<?=$csrfField?>
							<? $elements->Button($provisioning->IsOn() ? "Rotate secret" : "Generate secret", "", $provisioning->IsOn() ? "btn-warning" : "btn-success", "", true); ?>
						</form>
						<? if($provisioning->IsOn()): ?>
						<form method="post" action="<?=$urls->GetFeatureActionUrl("AdminProvisioning", "TurnOff")?>"
							onsubmit="return confirm('Turn provisioning off? The secret stops working, no keys can be provisioned until a new one is generated. Keys already made keep working.');">
							<?=$csrfField?>
							<? $elements->Button("Turn off", "", "btn-outline-danger", "", true); ?>
						</form>
						<? endif; ?>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row mt-3">
		<div class="col-12">
			<div class="card">
				<div class="card-header">Limits, and what every provisioned key gets</div>
				<div class="card-body">
					<p>
						Provisioned keys: <strong><?=$keyCount?></strong><?=($provisioning->max_keys !== null ? " of ".(int)$provisioning->max_keys : "")?>
						<?=($pendingCount > 0 ? " (".$pendingCount." pending deletion: they count until the cron deletes them)" : "")?>.
						Most storage provisioning can commit (max keys &times; size limit):
						<strong><?=($worstCase !== null ? MagratheaHelper::FormatSize($worstCase * 1048576) : "unbounded")?></strong>.
					</p>
					<? if($formError !== null): ?>
						<? $elements->Alert("<strong>Nothing was saved.</strong> ".htmlspecialchars((string)$formError["message"]), "danger", false); ?>
					<? endif; ?>
					<? if($worstCase === null): ?>
						<? $elements->Alert("With no max keys or no size limit, a leaked secret can commit unlimited storage. Set both.", "warning", false); ?>
					<? endif; ?>
					<form method="post" action="<?=$urls->GetFeatureActionUrl("AdminProvisioning", "Save")?>">
						<?=$csrfField?>
						<div class="row">
							<div class="col-2"><? $elements->Input("text", "max_keys", "Max keys", $maxKeysValue, "", "", "no limit"); ?></div>
							<div class="col-10 pt-4">
								<small class="text-muted">
									The settings below are copied onto each key when it's created, so changing them doesn't touch existing
									keys: edit those in Keys. Empty fields mean the instance default, as on a key.
								</small>
							</div>
						</div>
						<? include(__DIR__."/../../Key/admin/settings-fields.php"); ?>
						<div class="row mt-3">
							<div class="col-2"><? $elements->Button("Save", "", "btn-success w-100", "", true); ?></div>
						</div>
					</form>
				</div>
			</div>
		</div>
	</div>
</div>
