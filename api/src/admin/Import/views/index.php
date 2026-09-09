<?php

use Magrathea2\Admin\AdminCsrf;
use Magrathea2\Admin\AdminElements;
use Magrathea2\Admin\AdminUrls;

$elements = AdminElements::Instance();
$elements->Header("Import");
$csrfField = '<input type="hidden" name="magrathea_csrf_token" value="'.AdminCsrf::Instance()->GetToken().'">';

$baseUrl = AdminUrls::Instance()->GetFeatureUrl("AdminImport");

?>
<div class="container">
	<? if(!empty($error)): ?>
	<div class="row mb-3">
		<div class="col-12"><? $elements->Alert(htmlspecialchars($error), "danger", false); ?></div>
	</div>
	<? endif; ?>

	<div class="row">
		<div class="col-6">
			<select onchange="if(this.value) location.href='<?=$baseUrl?>&key_id='+this.value; else location.href='<?=$baseUrl?>';" class="form-select">
				<option value="">-- select a key --</option>
				<? foreach($keys as $k): ?>
					<option value="<?=$k->id?>" <?=($selectedKeyId == $k->id ? "selected" : "")?>><?=htmlspecialchars($k->name)?></option>
				<? endforeach; ?>
			</select>
		</div>
	</div>

	<? if($selectedKeyId): ?>
	<div class="row mt-3">
		<div class="col-12">
			<div class="card">
				<div class="card-header">Import a folder from the server's filesystem</div>
				<div class="card-body">
					<form method="post" action="<?=AdminUrls::Instance()->GetFeatureActionUrl("AdminImport", "RunImport")?>">
						<?=$csrfField?>
						<input type="hidden" name="key_id" value="<?=$selectedKeyId?>">
						<div class="row">
							<div class="col-4">
								<select name="folder_id" class="form-select">
									<option value="">(root)</option>
									<? foreach($folders as $f): ?>
										<? if(empty($f->parent_id)) continue; ?>
										<option value="<?=$f->id?>"><?=htmlspecialchars($f->name)?> (#<?=$f->id?>)</option>
									<? endforeach; ?>
								</select>
								<small class="text-muted">Destination folder (defaults to root)</small>
							</div>
							<div class="col-8">
								<? $elements->Input("text", "path", "Server-side source folder path", "", "", "", "/absolute/path/on/this/server"); ?>
							</div>
						</div>
						<p class="text-muted mt-3 mb-2">
							This path is read directly on the server -- it is <strong>not</strong> a browser upload, and
							must already exist and be readable by the web server process. The source folder itself
							becomes a new folder (or is reused if one with that name already exists) inside the
							destination above, with its whole subtree mirrored underneath it. Every file goes through
							the same processing as a normal upload (MIME sniffing, image re-encode/thumbnail/metadata
							strip). Symlinks are skipped. This key's usage limits are not enforced for imports.
						</p>
						<div class="row mt-2">
							<div class="col-2"><? $elements->Button("Import", "", "btn-success w-100", "", true); ?></div>
						</div>
					</form>
				</div>
			</div>
		</div>
	</div>
	<? endif; ?>
</div>
