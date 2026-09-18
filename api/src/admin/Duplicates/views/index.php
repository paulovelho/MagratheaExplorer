<?php

use Magrathea2\Admin\AdminCsrf;
use Magrathea2\Admin\AdminElements;
use Magrathea2\Admin\AdminUrls;
use Magrathea2\MagratheaHelper;

$elements = AdminElements::Instance();
$elements->Header("Duplicates");
$csrfField = '<input type="hidden" name="magrathea_csrf_token" value="'.AdminCsrf::Instance()->GetToken().'">';
$deleteUrl = AdminUrls::Instance()->GetFeatureActionUrl("AdminDuplicates", "DeleteSelected");

?>
<div class="container">
	<div class="row">
		<div class="col-12">
			<div class="card">
				<div class="card-header">Duplicate groups (same key, folder and file name) (<?=count($groups)?>)</div>
				<div class="card-body">
				<? if(count($groups) === 0): ?>
					<p>No duplicates found.</p>
				<? else: ?>
					<form method="post" action="<?=$deleteUrl?>" onsubmit="return confirm('Delete the selected duplicates? Only the newest file in each selected group is kept.');">
						<?=$csrfField?>
						<div class="mb-3">
							<label class="form-check">
								<input type="checkbox" class="form-check-input" onclick="document.querySelectorAll('.dup-group-checkbox').forEach(function(cb){cb.checked=this.checked;}, this);">
								<span class="form-check-label">Select all groups</span>
							</label>
						</div>

						<? foreach($groups as $group): ?>
						<div class="card mb-3">
							<div class="card-header">
								<label class="form-check d-inline-block me-3">
									<input type="checkbox" class="form-check-input dup-group-checkbox" name="groups[]" value="<?=htmlspecialchars($group["token"])?>">
									<span class="form-check-label"></span>
								</label>
								<strong><?=htmlspecialchars($group["name"])?></strong>
								-- key "<?=htmlspecialchars($group["key"]->name)?>",
								folder "<?=htmlspecialchars($group["folder"]->name)?>" (#<?=$group["folder"]->id?>)
								-- <?=count($group["files"])?> copies
							</div>
							<div class="card-body table-responsive">
								<table class="table table-sm table-bordered mb-0">
									<tbody>
										<tr>
											<th>&nbsp;</th>
											<? foreach($group["files"] as $i => $f): ?>
												<th><?=($i === 0 ? '<span class="badge bg-success">newest -- kept</span>' : '<span class="badge bg-danger">will delete</span>')?></th>
											<? endforeach; ?>
										</tr>
										<tr>
											<th>#ID</th>
											<? foreach($group["files"] as $f): ?><td><?=$f->id?></td><? endforeach; ?>
										</tr>
										<tr>
											<th>Size</th>
											<? foreach($group["files"] as $f): ?><td><?=MagratheaHelper::FormatSize((int)$f->size)?></td><? endforeach; ?>
										</tr>
										<tr>
											<th>Created at</th>
											<? foreach($group["files"] as $f): ?><td><?=htmlspecialchars($f->created_at)?></td><? endforeach; ?>
										</tr>
										<tr>
											<th>Extension</th>
											<? foreach($group["files"] as $f): ?><td><?=htmlspecialchars((string)$f->extension)?></td><? endforeach; ?>
										</tr>
										<tr>
											<th>Mime type</th>
											<? foreach($group["files"] as $f): ?><td><?=htmlspecialchars($f->mime_type)?></td><? endforeach; ?>
										</tr>
										<tr>
											<th>Token</th>
											<? foreach($group["files"] as $f): ?><td><code><?=htmlspecialchars($f->token)?></code></td><? endforeach; ?>
										</tr>
									</tbody>
								</table>
							</div>
						</div>
						<? endforeach; ?>

						<button type="submit" class="btn btn-danger">Delete selected duplicates (keeps the newest copy in each group)</button>
					</form>
				<? endif; ?>
				</div>
			</div>
		</div>
	</div>
</div>
