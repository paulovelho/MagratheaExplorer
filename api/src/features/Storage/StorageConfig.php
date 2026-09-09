<?php

namespace MagratheaExplorer\Storage;

use Magrathea2\Config;
use Magrathea2\ConfigFile;
use Magrathea2\MagratheaPHP;

/**
 * Reads `storage.conf` (env-sectioned, file-based, deploy-time -- not the DB-backed
 * ConfigApp, since switching storage backend for a running instance is a deploy
 * decision). Mirrors Images3's R2Config pattern: absent file must never throw, since
 * "local" (the default) needs no S3/R2 section at all to be fully configured.
 */
class StorageConfig {

	private static ?string $configRootOverride = null;

	public static function SetConfigRootForTests(?string $path): void {
		self::$configRootOverride = $path;
	}

	private static function GetConfigRootPath(): string {
		return self::$configRootOverride ?? MagratheaPHP::Instance()->GetConfigRoot();
	}

	public static function Get(): array {
		$path = self::GetConfigRootPath();
		$file = $path."/storage.conf";
		if(!file_exists($file)) return [];
		$section = (new ConfigFile())->SetPath($path)->SetFile("storage.conf")
			->GetConfigSection(Config::Instance()->GetEnvironment());
		return $section ?: [];
	}

	public static function GetDriver(): string {
		return self::Get()["driver"] ?? "local";
	}

	public static function GetLocalPath(): ?string {
		return self::Get()["local_path"] ?? null;
	}

	public static function GetLocalUrl(): ?string {
		return self::Get()["local_url"] ?? null;
	}

	public static function GetS3Endpoint(): ?string {
		return self::Get()["s3_endpoint"] ?? null;
	}

	public static function GetS3Region(): string {
		return self::Get()["s3_region"] ?? "auto";
	}

	public static function GetS3Bucket(): ?string {
		return self::Get()["s3_bucket"] ?? null;
	}

	public static function GetS3AccessKey(): ?string {
		return self::Get()["s3_access_key"] ?? null;
	}

	public static function GetS3SecretKey(): ?string {
		return self::Get()["s3_secret_key"] ?? null;
	}

	public static function GetS3PathStyle(): bool {
		return !empty(self::Get()["s3_path_style"]);
	}

	public static function GetS3PublicUrl(): ?string {
		$url = self::Get()["s3_public_url"] ?? null;
		return $url ? rtrim($url, "/") : null;
	}

}
