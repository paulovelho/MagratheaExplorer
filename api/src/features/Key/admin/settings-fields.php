<?php
// The settings fields a key and the provisioning row share (KeySettings), for both admin forms.
// Expects: $elements (AdminElements), $settingsOf (a Key or the Provisioning row: its current
// values), $formError ({field, message, value} of a refused submit, or null). Field names are
// the ones KeySettings::FromForm() reads.

use Magrathea\MagratheaHelper;
use MagratheaExplorer\Key\Key;
use MagratheaExplorer\Key\KeyControl;
use MagratheaExplorer\Processing\RenditionProfile;

// Upload size: what the operator set, and what PHP (this same SAPI and .user.ini serve the
// API) actually lets through. A limit above PHP's would be silently cut: say so here.
$instanceMaxUpload = Key::InstanceMaxUploadSize();
$phpMaxUpload = Key::PhpMaxUploadSize();
$phpLimitsText = "upload_max_filesize = ".htmlspecialchars((string)ini_get("upload_max_filesize"))
	.", post_max_size = ".htmlspecialchars((string)ini_get("post_max_size"));
$configuredMaxUpload = $settingsOf->max_upload_size !== null ? (int)$settingsOf->max_upload_size : $instanceMaxUpload;

?>
<? if($configuredMaxUpload > $phpMaxUpload): ?>
	<? $elements->Alert(
		"<strong>PHP caps uploads at ".MagratheaHelper::FormatSize($phpMaxUpload).",</strong> below the "
		.MagratheaHelper::FormatSize($configuredMaxUpload)." limit here (".$phpLimitsText."). Raise them as in docs/deploy.md, "
		."\"Large uploads\". <code>GET /key</code> reports the lower one.",
		"warning", false); ?>
<? endif; ?>
<div class="row mt-2">
	<div class="col-2"><? $elements->Input("text", "usage_limit", "Upload Limit", $settingsOf->usage_limit, "", "", "no limit"); ?></div>
	<div class="col-2"><? $elements->Input("text", "usage_limit_mb", "Size Limit (MB)", $settingsOf->usage_limit_mb, "", "", "no limit"); ?></div>
	<div class="col-4">
		<?
		$verbatimOptions = [
			"" => "Instance default (".(Key::InstanceVerbatimDefault() ? "verbatim" : "stripped + converted").")",
			"1" => "Verbatim (bytes as sent, EXIF/GPS kept)",
			"0" => "Stripped + converted",
		];
		$verbatimValue = $settingsOf->verbatim_default === null ? "" : ($settingsOf->verbatim_default ? "1" : "0");
		$elements->Select("verbatim_default", "Images without verbatim flag", $verbatimOptions, $verbatimValue);
		?>
	</div>
	<div class="col-3">
		<?
		$elements->Input("number", "max_upload_size_mb", "Max upload (MB)", KeyControl::MaxUploadMbForForm($settingsOf->max_upload_size !== null ? (int)$settingsOf->max_upload_size : null),
			"", "", "instance default: ".MagratheaHelper::FormatSize($instanceMaxUpload), ["step" => "any"]);
		?>
		<small class="text-muted">PHP allows <?=($phpMaxUpload === PHP_INT_MAX ? "any size" : MagratheaHelper::FormatSize($phpMaxUpload))?> (<?=$phpLimitsText?>)</small>
	</div>
</div>
<div class="row mt-3">
	<div class="col-12">
		<?
		// What the textarea shows: what was just refused, else the own profile (one spec per line),
		// else nothing (= the instance default, the placeholder).
		$storedProfileError = null;
		if($formError !== null && $formError["field"] === "rendition_profile") {
			$profileValue = (string)$formError["value"];
		} elseif($settingsOf->rendition_profile !== null && trim((string)$settingsOf->rendition_profile) !== "") {
			try {
				$profileValue = RenditionProfile::Pretty(RenditionProfile::Parse((string)$settingsOf->rendition_profile));
			} catch(\InvalidArgumentException $ex) {
				$profileValue = (string)$settingsOf->rendition_profile;
				$storedProfileError = $ex->getMessage();
			}
		} else {
			$profileValue = "";
		}
		if($storedProfileError !== null) {
			$elements->Alert("<strong>The stored rendition profile is invalid:</strong> ".htmlspecialchars($storedProfileError)
				." Image uploads get processing status <code>failed</code> until it's fixed.", "warning", false);
		}
		$elements->Textarea("rendition_profile", "Renditions (JSON, empty = the instance default)", $profileValue, "font-monospace", "",
			htmlspecialchars(RenditionProfile::Pretty(RenditionProfile::Default())), ["rows" => 5, "spellcheck" => "false"]);
		?>
		<small class="text-muted">
			Up to <?=RenditionProfile::MAX_SPECS?> specs: <code>name</code> (a-z, 0-9, _ and -; <code>thumb</code> is
			<code>thumbnail_url</code>), <code>width</code>/<code>height</code> (1-<?=RenditionProfile::MAX_SIDE?>, never upscaled),
			<code>fit</code> (<code>contain</code> or <code>cover</code>), <code>format</code> (<code>webp</code>, <code>png</code>,
			<code>jpg</code> or <code>legacy</code>: png for a png, jpg otherwise), <code>quality</code> (1-100, default 85),
			<code>background</code> (<code>#rrggbb</code>, jpg only). <code>[]</code> = no renditions. A change applies to the next
			uploads; existing files keep theirs until regenerated.
		</small>
		<div class="mt-1">
			<button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('rendition_profile').value = explorerProfilePreset;">Preset: thumb 300 + preview 1200 (webp, contain)</button>
			<button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('rendition_profile').value = '';">Instance default</button>
		</div>
		<script>var explorerProfilePreset = <?=json_encode(RenditionProfile::Pretty(RenditionProfile::PRESET_THUMB_PREVIEW), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;</script>
	</div>
</div>
