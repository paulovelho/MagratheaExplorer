<?php

use Magrathea2\Admin\AdminCsrf;
use Magrathea2\Admin\AdminElements;
use Magrathea2\Admin\AdminUrls;
use Magrathea2\MagratheaHelper;

$elements = AdminElements::Instance();
$elements->Header("Browser");
$csrfField = '<input type="hidden" name="magrathea_csrf_token" value="'.AdminCsrf::Instance()->GetToken().'">';

$baseUrl = AdminUrls::Instance()->GetFeatureUrl("AdminBrowser");
$orphanUrl = AdminUrls::Instance()->GetFeatureUrl("AdminBrowser", "OrphanCheck");

?>
<div class="container">
	<div class="row">
		<div class="col-6">
			<select onchange="if(this.value) location.href='<?=$baseUrl?>&key_id='+this.value;" class="form-select">
				<option value="">-- select a key --</option>
				<? foreach($keys as $k): ?>
					<option value="<?=$k->id?>" <?=($selectedKeyId == $k->id ? "selected" : "")?>><?=htmlspecialchars($k->name)?></option>
				<? endforeach; ?>
			</select>
		</div>
		<div class="col-6 text-end">
			<a class="btn btn-warning" href="<?=$orphanUrl?>">Orphan Check</a>
		</div>
	</div>

	<? if($selectedKeyId): ?>
	<div class="row mt-3">
		<div class="col-12">
			<div class="card">
				<div class="card-header">Folders (<?=count($folders)?>)</div>
				<div class="card-body">
				<?
				$elements->Table($folders, [
					["title" => "#ID", "key" => "id"],
					["title" => "Name", "key" => "name"],
					["title" => "Parent ID", "key" => "parent_id"],
					["title" => "...", "key" => function($f) use ($selectedKeyId, $csrfField) {
						if(empty($f->parent_id)) return "(root)";
						return '<form method="post" style="display:inline" action="'.\Magrathea2\Admin\AdminUrls::Instance()->GetFeatureActionUrl("AdminBrowser", "DeleteFolder").'"'
							.' onsubmit="return confirm(\'Delete this folder? It must be empty.\');">'
							.$csrfField
							.'<input type="hidden" name="id" value="'.$f->id.'">'
							.'<input type="hidden" name="key_id" value="'.$selectedKeyId.'">'
							.'<button type="submit" class="btn btn-sm btn-danger">delete</button></form>';
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
				<div class="card-header">Files (<?=count($files)?>)</div>
				<div class="card-body">
				<?
				$elements->Table($files, [
					["title" => "#ID", "key" => "id"],
					["title" => "Name", "key" => "name"],
					["title" => "Folder ID", "key" => "folder_id"],
					["title" => "Type", "key" => "file_type"],
					["title" => "Size", "key" => function($f) { return MagratheaHelper::FormatSize((int)$f->size); }],
					["title" => "...", "key" => function($f) use ($selectedKeyId, $csrfField) {
						return '<form method="post" style="display:inline" action="'.\Magrathea2\Admin\AdminUrls::Instance()->GetFeatureActionUrl("AdminBrowser", "DeleteFile").'"'
							.' onsubmit="return confirm(\'Delete this file from storage and the database?\');">'
							.$csrfField
							.'<input type="hidden" name="id" value="'.$f->id.'">'
							.'<input type="hidden" name="key_id" value="'.$selectedKeyId.'">'
							.'<button type="submit" class="btn btn-sm btn-danger">delete</button></form>';
					}],
				]);
				?>
				</div>
			</div>
		</div>
	</div>
	<? endif; ?>
</div>
