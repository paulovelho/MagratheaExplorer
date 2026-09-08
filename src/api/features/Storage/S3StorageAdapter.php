<?php

namespace MagratheaExplorer\Storage;

use AsyncAws\S3\S3Client;

/**
 * One class for both R2 and real S3 -- R2 is just S3 with a Cloudflare endpoint and
 * region "auto" (async-aws/s3 talks to both identically); adding real AWS S3 later is
 * only a config change. Unlike Images3's R2Client (which only sets ContentType +
 * Metadata on PutObject, since it's backup-only and never serves a read), this adapter
 * sets ContentDisposition and CacheControl too, since here S3 is the primary read path
 * and headers can only be set once, at upload time -- there's no per-request PHP
 * opportunity to correct them later. `X-Content-Type-Options: nosniff` has no PutObject
 * equivalent, so it stays a CDN/vhost deploy concern (see the design doc).
 */
class S3StorageAdapter implements StorageAdapter {

	private S3Client $client;
	private string $bucket;
	private ?string $publicUrlBase;

	public function __construct() {
		$this->bucket = StorageConfig::GetS3Bucket() ?? "";
		$this->publicUrlBase = StorageConfig::GetS3PublicUrl();
		if(empty($this->bucket) || empty(StorageConfig::GetS3Endpoint()) || empty(StorageConfig::GetS3AccessKey()) || empty(StorageConfig::GetS3SecretKey())) {
			throw new StorageException("s3 storage driver selected but bucket/endpoint/access_key/secret_key are not fully configured in storage.conf");
		}
		$this->client = new S3Client([
			"endpoint" => StorageConfig::GetS3Endpoint(),
			"region" => StorageConfig::GetS3Region(),
			"accessKeyId" => StorageConfig::GetS3AccessKey(),
			"accessKeySecret" => StorageConfig::GetS3SecretKey(),
			"pathStyleEndpoint" => StorageConfig::GetS3PathStyle(),
		]);
	}

	public function put(string $path, string $localTmpFile, array $meta): void {
		if(!file_exists($localTmpFile)) {
			throw new StorageException("local file does not exist: ".$localTmpFile);
		}
		$stream = fopen($localTmpFile, "rb");
		try {
			$this->client->putObject([
				"Bucket" => $this->bucket,
				"Key" => $path,
				"Body" => $stream,
				"ContentType" => $meta["content_type"] ?? "application/octet-stream",
				"ContentDisposition" => $meta["disposition"] ?? "inline",
				"CacheControl" => "public, max-age=31536000, immutable",
			]);
		} catch(\Throwable $ex) {
			throw new StorageException("s3 put failed for ".$path.": ".$ex->getMessage(), 0, $ex);
		} finally {
			if(is_resource($stream)) fclose($stream);
		}
	}

	public function delete(string $path): void {
		try {
			$this->client->deleteObject([
				"Bucket" => $this->bucket,
				"Key" => $path,
			]);
		} catch(\Throwable $ex) {
			throw new StorageException("s3 delete failed for ".$path.": ".$ex->getMessage(), 0, $ex);
		}
	}

	public function url(string $path): string {
		if($this->publicUrlBase) {
			return $this->publicUrlBase."/".ltrim($path, "/");
		}
		return rtrim(StorageConfig::GetS3Endpoint() ?? "", "/")."/".$this->bucket."/".ltrim($path, "/");
	}

	public function exists(string $path): bool {
		try {
			return $this->client->objectExists([
				"Bucket" => $this->bucket,
				"Key" => $path,
			])->isSuccess();
		} catch(\Throwable $ex) {
			return false;
		}
	}

}
