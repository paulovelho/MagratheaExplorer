<?php

use Magrathea2\Admin\AdminElements;
use Magrathea2\MagratheaHelper;

$elements = AdminElements::Instance();
$elements->Header("Storage");

?>
<div class="container">
	<div class="row">
		<div class="col-12 col-lg-6">
			<div class="card">
				<div class="card-header">Active backend</div>
				<div class="card-body">
					<h4 class="mb-3">
						<?=htmlspecialchars($driverLabel)?>
						<span class="badge bg-<?=($connectionError ? "danger" : "success")?>">
							<?=($connectionError ? "not reachable" : "configured")?>
						</span>
					</h4>
					<? if($connectionError): ?>
						<? $elements->Alert("<strong>Storage adapter could not be initialized:</strong> ".htmlspecialchars($connectionError), "danger", false); ?>
					<? endif; ?>
					<?
					$elements->Table($configRows, [
						["title" => "Setting", "key" => "label"],
						["title" => "Value", "key" => "value"],
					]);
					?>
				</div>
			</div>
		</div>

		<div class="col-12 col-lg-6">
			<div class="card">
				<div class="card-header">Usage</div>
				<div class="card-body">
					<?
					$elements->Table([
						["label" => "Access keys", "value" => number_format($keyCount)],
						["label" => "Files", "value" => number_format($fileCount)],
						["label" => "Total stored size", "value" => MagratheaHelper::FormatSize($totalSize)],
					], [
						["title" => "Metric", "key" => "label"],
						["title" => "Value", "key" => "value"],
					]);
					?>
					<p class="text-muted mb-0"><small>Size is the sum of each key's cached <code>total_size</code> (access_keys.total_size), not a live scan of the backend.</small></p>
				</div>
			</div>
		</div>
	</div>

	<div class="row mt-3">
		<div class="col-12">
			<div class="card">
				<div class="card-header">About this storage system</div>
				<div class="card-body">
					<p>
						Every uploaded file is written to exactly one backend for the whole instance, picked by the
						<code>driver</code> setting in <code>config/storage.conf</code>: either <strong>local</strong>
						(files live on this server's disk and are served directly by the webserver) or
						<strong>s3</strong> (an S3-compatible bucket -- this covers both real AWS S3 and Cloudflare R2,
						since R2 speaks the S3 API; R2 just uses a Cloudflare endpoint and region <code>auto</code>).
						Reads never go through PHP in either case: the file's <code>url()</code> points straight at the
						vhost (local) or the bucket/CDN (s3).
					</p>
					<p>
						The database only stores each file's <code>storage_path</code> (its relative key/path) --
						the bytes themselves live wherever the active driver put them. Switching drivers does not
						move existing files, it only changes where <em>new</em> files go and where reads for
						existing <code>storage_path</code>s are expected to be found.
					</p>
					<h6 class="mt-3">How to change it</h6>
					<ol class="mb-0">
						<li>Edit <code>config/storage.conf</code> (copy from <code>storage.conf.sample</code> if it doesn't exist yet), under the section for the environment you're deploying (<code>[dev]</code> / <code>[production]</code>).</li>
						<li>Set <code>driver</code> to <code>local</code> or <code>s3</code>, and fill in the matching block below it (<code>local_path</code>/<code>local_url</code>, or <code>s3_endpoint</code>/<code>s3_region</code>/<code>s3_bucket</code>/<code>s3_access_key</code>/<code>s3_secret_key</code>/<code>s3_path_style</code>/<code>s3_public_url</code>).</li>
						<li>Prefer <code>$=ENV_VAR_NAME</code> for <code>s3_access_key</code>/<code>s3_secret_key</code> instead of hardcoding secrets in the file.</li>
						<li>Deploy/restart the app -- this is a file read at request time, not a live-editable setting in this admin panel, since a storage backend change is a deploy-time decision, not a runtime toggle.</li>
					</ol>
				</div>
			</div>
		</div>
	</div>
</div>
