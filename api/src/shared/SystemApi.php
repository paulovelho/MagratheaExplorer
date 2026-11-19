<?php

namespace MagratheaExplorer;

use Magrathea\ConfigApp;
use Magrathea\Exceptions\MagratheaApiException;
use Magrathea\MagratheaApiControl;
use Magrathea\MagratheaHelper;
use Magrathea\MagratheaPHP;
use MagratheaExplorer\File\SafeFetcher;
use MagratheaExplorer\Key\Key;
use MagratheaExplorer\Processing\RenditionProfile;

class SystemApi extends MagratheaApiControl {

	const DEFAULT_APP_NAME = "Magrathea Explorer";

	/**
	 * Display name shared by the explorer app and the admin, from ConfigApp's `app_name`.
	 */
	public static function AppName(): string {
		return ConfigApp::Instance()->Get("app_name") ?: self::DEFAULT_APP_NAME;
	}

	public function GetSettings(): array {
		// the instance default, raw (keys may have their own: GET /key has the effective one)
		$maxUpload = ConfigApp::Instance()->Get("max_upload_size") ?? Key::DEFAULT_MAX_UPLOAD_SIZE;
		return [
			"app_name" => self::AppName(),
			"max_upload_size" => (string)$maxUpload,
			"max_upload_size_formatted" => MagratheaHelper::FormatSize((int)$maxUpload),
			// the default profile's `thumb` (keys with their own profile may differ)
			"thumbnail_size" => RenditionProfile::ThumbnailSize(),
			"source_url_enabled" => self::SourceUrlEnabled(),
		];
	}

	/**
	 * Whether `POST /files` takes `source_url`, so a client can check before relying on it. A
	 * value magrathea.conf can't read is false here: /settings is public and the app reads it,
	 * so it has to answer. The upload itself says 5004, naming the key.
	 */
	public static function SourceUrlEnabled(): bool {
		try {
			return SafeFetcher::IsEnabled();
		} catch(MagratheaApiException $ex) {
			return false;
		}
	}

	/** Every entry type changelog.md uses. An entry of any other type is left out, wrapped lines included. */
	const CHANGELOG_TYPES = ["new", "fix", "improvement", "improvements", "breaking", "db", "upgrade"];

	public function GetChangelog(): array {
		$this->Cache("changelog");
		$filepath = MagratheaHelper::EnsureTrailingSlash(MagratheaPHP::Instance()->magRoot)."changelog.md";
		if(!file_exists($filepath)) return [];
		return array_slice(self::ParseChangelog(file_get_contents($filepath)), 0, 5);
	}

	/**
	 * changelog.md's versions, in file order (newest first). A version is `## 1.5.0` (or
	 * `## v.1.5.0`), then a `YYYY-MM-DD` line (anything else, like `unreleased`, leaves `date`
	 * null), then `- **type:** text` entries. An entry's wrapped lines are joined to it with a
	 * space, up to the next entry, a blank line or the next version.
	 */
	public static function ParseChangelog(string $content): array {
		$changelog = [];
		$current = null;
		$open = false; // whether a wrapped line belongs to the last entry in $current["changes"]
		foreach(preg_split('/\r?\n/', $content) as $line) {
			$line = trim($line);
			if($line === "") {
				$open = false;
			} elseif(preg_match('/^## (?:v\.)?([0-9][0-9.]*)/i', $line, $m)) {
				if($current) $changelog[] = $current;
				$current = ["version" => $m[1], "date" => null, "changes" => []];
				$open = false;
			} elseif($current === null) {
				continue; // the title, anything before the first version
			} elseif(str_starts_with($line, "- ")) {
				$open = preg_match('/^- \*\*([a-z]+):?\*\*\s*(.*)$/i', $line, $m) === 1
					&& in_array(strtolower($m[1]), self::CHANGELOG_TYPES, true);
				if($open) $current["changes"][] = ["type" => strtolower($m[1]), "description" => $m[2]];
			} elseif($open) {
				$last = count($current["changes"]) - 1;
				$current["changes"][$last]["description"] = ltrim($current["changes"][$last]["description"]." ".$line);
			} elseif(preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2})/', $line, $m)) {
				$current["date"] = $m[1];
			}
		}
		if($current) $changelog[] = $current;
		return $changelog;
	}

	public function GetErrorCodes(): array {
		$this->Cache("error_codes");
		return ErrorCodes::Instance()->GetAll();
	}

}
