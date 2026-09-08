<?php

namespace MagratheaExplorer;

use Magrathea2\ConfigApp;
use Magrathea2\MagratheaApiControl;
use Magrathea2\MagratheaHelper;
use Magrathea2\MagratheaPHP;
use MagratheaExplorer\File\UploadPipeline;

class SystemApi extends MagratheaApiControl {

	public function GetSettings(): array {
		$maxUpload = ConfigApp::Instance()->Get("max_upload_size") ?? UploadPipeline::DEFAULT_MAX_UPLOAD_SIZE;
		$thumbSize = ConfigApp::Instance()->Get("thumbnail_size") ?? UploadPipeline::DEFAULT_THUMBNAIL_SIZE;
		return [
			"max_upload_size" => (string)$maxUpload,
			"max_upload_size_formatted" => MagratheaHelper::FormatSize((int)$maxUpload),
			"thumbnail_size" => (int)$thumbSize,
		];
	}

	public function GetChangelog(): array {
		$this->Cache("changelog");
		$filepath = MagratheaHelper::EnsureTrailingSlash(MagratheaPHP::Instance()->magRoot)."changelog.md";
		if(!file_exists($filepath)) return [];
		$content = file_get_contents($filepath);
		$lines = preg_split('/\r?\n/', $content);
		$changelog = [];
		$current = null;
		foreach($lines as $line) {
			$line = trim($line);
			if(preg_match('/^## v\.([0-9.]+)/i', $line, $m) || preg_match('/^## ([0-9.]+)/i', $line, $m)) {
				if($current) $changelog[] = $current;
				$current = ["version" => $m[1], "date" => null, "changes" => []];
			} elseif(preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2})/', $line, $m)) {
				if($current) $current["date"] = $m[1];
			} elseif(preg_match('/^- \*\*(new|fix|improvement|improvements):?\*\*\s*(.+)$/i', $line, $m)) {
				if($current) $current["changes"][] = ["type" => strtolower($m[1]), "description" => $m[2]];
			}
		}
		if($current) $changelog[] = $current;
		return array_slice($changelog, 0, 5);
	}

	public function GetErrorCodes(): array {
		$this->Cache("error_codes");
		return ErrorCodes::Instance()->GetAll();
	}

}
