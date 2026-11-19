<?php

namespace MagratheaExplorer\Key;

use MagratheaExplorer\Provisioning\Provisioning;

/**
 * The settings a key has and the provisioning row has too: what every key POST /provision/keys
 * creates copies (admin → Provisioning). Same columns, same meaning (null = the instance
 * default), so both admin forms parse them here and copy them with Of()/Apply().
 */
final class KeySettings {

	const FIELDS = ["usage_limit", "usage_limit_mb", "verbatim_default", "max_upload_size", "rendition_profile"];

	/** int(11) columns. */
	const MAX_COUNT = 2147483647;

	/**
	 * The settings fields of an admin form, as column values: only the fields it sent. Each one is
	 * parsed before anything is assigned anywhere, so a refused value leaves everything as it was.
	 * - `usage_limit`, `usage_limit_mb`: empty = no limit (null), else a whole number, 0 included.
	 * - `verbatim_default`: "1", "0", or anything else = the instance default (null).
	 * - `max_upload_size_mb`: KeyControl::ParseMaxUploadMb(), stored as `max_upload_size` (bytes).
	 * - `rendition_profile`: KeyControl::ParseRenditionProfile().
	 * @throws KeySettingsException naming the refused form field
	 */
	public static function FromForm(array $data): array {
		$settings = [];
		foreach(["usage_limit", "usage_limit_mb"] as $field) {
			if(array_key_exists($field, $data)) $settings[$field] = self::ParseCount((string)$data[$field], $field);
		}
		if(array_key_exists("verbatim_default", $data)) {
			$settings["verbatim_default"] = match((string)$data["verbatim_default"]) { "1" => true, "0" => false, default => null };
		}
		if(array_key_exists("max_upload_size_mb", $data)) {
			try {
				$settings["max_upload_size"] = KeyControl::ParseMaxUploadMb((string)$data["max_upload_size_mb"]);
			} catch(\InvalidArgumentException $ex) {
				throw new KeySettingsException("max_upload_size_mb", $ex->getMessage());
			}
		}
		if(array_key_exists("rendition_profile", $data)) {
			try {
				$settings["rendition_profile"] = KeyControl::ParseRenditionProfile((string)$data["rendition_profile"]);
			} catch(\InvalidArgumentException $ex) {
				throw new KeySettingsException("rendition_profile", $ex->getMessage());
			}
		}
		return $settings;
	}

	/** $source's settings, every field. */
	public static function Of(Key|Provisioning $source): array {
		$settings = [];
		foreach(self::FIELDS as $field) $settings[$field] = $source->$field;
		return $settings;
	}

	/** Assigns $settings to $target. Fields that aren't settings are ignored. Nothing is saved. */
	public static function Apply(Key|Provisioning $target, array $settings): void {
		foreach(self::FIELDS as $field) {
			if(array_key_exists($field, $settings)) $target->$field = $settings[$field];
		}
	}

	private static function ParseCount(string $raw, string $field): ?int {
		$raw = trim($raw);
		if($raw === "") return null;
		if(preg_match('/^\d+$/', $raw) !== 1 || strlen(ltrim($raw, "0")) > 10 || (int)$raw > self::MAX_COUNT) {
			$label = $field === "usage_limit" ? "Upload Limit" : "Size Limit (MB)";
			throw new KeySettingsException($field, $label." takes a whole number (0 or more, up to ".self::MAX_COUNT."), or nothing for no limit.");
		}
		return (int)$raw;
	}

}
