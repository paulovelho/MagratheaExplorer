<?php

namespace MagratheaExplorer;

use Magrathea\Config;
use Magrathea\MagratheaPHP;

/**
 * Instance settings read from magrathea.conf (the active environment's section): the ones an
 * operator sets per server, and the ones that must never be editable from the admin panel
 * (ConfigApp is). Absent → the caller's default. Present but unreadable → 5004 naming the
 * key: a typo in a server's config fails loudly instead of silently falling back.
 */
class ExplorerConfig {

	/** Any value RequestBool reads. parse_ini_file turns an unquoted `false` into "", which reads as false. */
	public static function GetBool(string $key, bool $default): bool {
		$raw = Config::Instance()->Get($key);
		if($raw === null) return $default;
		$value = RequestBool::ParseValue((string)$raw);
		if($value === null) self::ThrowInvalid($key, $raw);
		return $value;
	}

	/** Bytes: a plain count or a PHP-ini-style size ("25M"). Empty = absent. Must be at least 1. */
	public static function GetSize(string $key, int $default): int {
		$raw = Config::Instance()->Get($key);
		if($raw === null || $raw === "") return $default;
		$value = self::ParseSize($raw);
		if($value === null || $value < 1) self::ThrowInvalid($key, $raw);
		return $value;
	}

	/** A positive integer. Empty = absent. */
	public static function GetPositiveInt(string $key, int $default): int {
		$raw = Config::Instance()->Get($key);
		if($raw === null || $raw === "") return $default;
		$raw = trim((string)$raw);
		if(preg_match('/^\d+$/', $raw) !== 1 || (int)$raw < 1) self::ThrowInvalid($key, $raw);
		return (int)$raw;
	}

	/**
	 * A command line as a JSON array of strings (`'["/path/to/python","/path/to/script.py"]'`, in
	 * single quotes in the ini), executed as is: no shell, so no quoting, globbing or variables.
	 * Absent or empty = null. Anything but a non-empty array of non-empty strings → 5004.
	 */
	public static function GetArgv(string $key): ?array {
		$raw = Config::Instance()->Get($key);
		if($raw === null || trim((string)$raw) === "") return null;
		$argv = json_decode((string)$raw); // objects stay objects: {"0": "…"} isn't a list
		if(!is_array($argv) || $argv === []) self::ThrowInvalid($key, $raw);
		foreach($argv as $arg) {
			if(!is_string($arg) || $arg === "" || str_contains($arg, "\0")) self::ThrowInvalid($key, $raw);
		}
		return $argv;
	}

	/**
	 * `processing_tmp_path`: the instance's own scratch folder (cron locks, files fetched back
	 * from storage, the processor's job dirs, `source_url` downloads). Inside the account, never
	 * the shared /tmp.
	 * Absent = `api/tmp`, which is gitignored. Created on first use.
	 */
	public static function ProcessingTmpPath(): string {
		$raw = Config::Instance()->Get("processing_tmp_path");
		$path = ($raw === null || trim((string)$raw) === "")
			? MagratheaPHP::Instance()->magRoot."/tmp"
			: rtrim(trim((string)$raw), "/");
		if($path === "") self::ThrowInvalid("processing_tmp_path", $raw);
		if(!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) {
			ErrorCodes::Instance()->ThrowException(5004, null, "magrathea.conf `processing_tmp_path` can't be created");
		}
		if(!is_writable($path)) {
			ErrorCodes::Instance()->ThrowException(5004, null, "magrathea.conf `processing_tmp_path` isn't writable");
		}
		return $path;
	}

	/** A plain byte count or a PHP-ini-style size ("5M", "512K", "1G", case-insensitive). null = neither. */
	public static function ParseSize(string|int $value): ?int {
		$value = trim((string)$value);
		if(preg_match('/^(\d+)([KMG]?)$/i', $value, $m) !== 1) return null;
		$bytes = (int)$m[1];
		switch(strtoupper($m[2])) {
			case "G": $bytes *= 1024;
			case "M": $bytes *= 1024;
			case "K": $bytes *= 1024;
		}
		return $bytes;
	}

	/** Names the key, never its value: the message reaches API clients. */
	private static function ThrowInvalid(string $key, $raw): never {
		ErrorCodes::Instance()->ThrowException(5004, null, "magrathea.conf `".$key."`");
		throw new \LogicException("unreachable"); // ThrowException() always throws
	}

}
