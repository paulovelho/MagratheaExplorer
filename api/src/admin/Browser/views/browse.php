<?php

use Magrathea\Admin\AdminCsrf;
use Magrathea\Admin\AdminElements;
use Magrathea\Admin\AdminUrls;
use Magrathea\MagratheaHelper;

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

	<? if($selectedKeyId && !empty($_GET["regenerate_failed"])): ?>
	<div class="row mt-3"><div class="col-12">
		<? $elements->Alert("<strong>Couldn't regenerate.</strong> This instance has no processor (<code>processor_command</code> in magrathea.conf), or its settings can't be read: see docs/deploy.md, \"Image processor\".", "danger", false); ?>
	</div></div>
	<? endif; ?>

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
						// a recursive delete marked it: the cron's sweep removes it (FolderDeletion)
						if($f->IsBeingDeleted()) return "<span class='badge bg-warning text-dark'>deleting</span>";
						return '<form method="post" style="display:inline" action="'.\Magrathea\Admin\AdminUrls::Instance()->GetFeatureActionUrl("AdminBrowser", "DeleteFolder").'"'
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
					["title" => "Processing", "key" => function($f) use ($renditions) {
						$status = \MagratheaExplorer\File\FileResponse::Status($f);
						$class = ["ready" => "text-success", "failed" => "text-danger", "pending" => "text-warning"][$status] ?? "text-muted";
						$html = "<span class='".$class."'>".$status."</span>";
						$names = array_keys($renditions[(int)$f->id] ?? []);
						if($names !== []) $html .= " <small class='text-muted'>(".htmlspecialchars(implode(", ", $names)).")</small>";
						if(!empty($f->processing_error)) $html .= "<br><small class='text-danger'>".htmlspecialchars((string)$f->processing_error)."</small>";
						return $html;
					}],
					["title" => "...", "key" => function($f) use ($selectedKeyId, $csrfField) {
						$regenerate = $f->file_type === "image"
							? '<form method="post" style="display:inline" class="me-1" action="'.\Magrathea\Admin\AdminUrls::Instance()->GetFeatureActionUrl("AdminBrowser", "RegenerateFile").'">'
								.$csrfField
								.'<input type="hidden" name="id" value="'.$f->id.'">'
								.'<input type="hidden" name="key_id" value="'.$selectedKeyId.'">'
								.'<button type="submit" class="btn btn-sm btn-outline-secondary" title="New renditions from the key\'s current profile (new URLs), made by cron.php --process">regenerate</button></form>'
							: '';
						return $regenerate.'<form method="post" style="display:inline" action="'.\Magrathea\Admin\AdminUrls::Instance()->GetFeatureActionUrl("AdminBrowser", "DeleteFile").'"'
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
