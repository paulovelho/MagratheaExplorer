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

	/**
	 * Writes each chunk as it arrives. async-aws also mirrors the body into its own php://temp
	 * buffer (in memory up to 2 MB, then a file in sys_get_temp_dir()), so a fetch needs about
	 * twice the object's size in temp disk until it returns; PHP memory stays flat either way.
	 * getContentAsResource() would cost the same disk and write nothing until the whole body
	 * had arrived.
	 */
	public function fetch(string $path, string $localTmpFile): void {
		$out = @fopen($localTmpFile, "wb");
		if($out === false) {
			throw new StorageException("could not open ".$localTmpFile." for writing");
		}
		$complete = false;
		try {
			$result = $this->client->getObject([
				"Bucket" => $this->bucket,
				"Key" => $path,
			]);
			$written = 0;
			foreach($result->getBody()->getChunks() as $chunk) {
				$length = strlen($chunk);
				if(fwrite($out, $chunk) !== $length) {
					throw new StorageException("short write to ".$localTmpFile." (disk full?)");
				}
				$written += $length;
			}
			$expected = $result->getContentLength();
			if($expected !== null && $written !== $expected) {
				throw new StorageException("s3 fetch of ".$path." got ".$written." of ".$expected." bytes");
			}
			$complete = true;
		} catch(StorageException $ex) {
			throw $ex;
		} catch(\Throwable $ex) {
			throw new StorageException("s3 fetch failed for ".$path.": ".$ex->getMessage(), 0, $ex);
		} finally {
			fclose($out);
			// releases async-aws' php://temp mirror of the body
			unset($result);
			if(!$complete && is_file($localTmpFile)) @unlink($localTmpFile);
		}
	}

	/**
	 * CopyObject, done by the backend. MetadataDirective COPY keeps every header put() set.
	 * A single CopyObject stops at 5 GiB, on S3 and on R2 alike (R2's single-request limit
	 * covers copies); no multipart copy here, so a bigger object's rotation fails.
	 */
	public function copy(string $from, string $to): void {
		try {
			$this->client->copyObject([
				"Bucket" => $this->bucket,
				"Key" => $to,
				"CopySource" => $this->bucket."/".implode("/", array_map("rawurlencode", explode("/", ltrim($from, "/")))),
				"MetadataDirective" => "COPY",
			])->resolve();
		} catch(\Throwable $ex) {
			throw new StorageException("s3 copy failed from ".$from." to ".$to.": ".$ex->getMessage(), 0, $ex);
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

	// $downloadName/$dispositionType ignored: Content-Disposition was already baked
	// into object metadata at put() time (see class doc above).
	public function url(string $path, ?string $downloadName = null, string $dispositionType = "inline"): string {
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

	/** No-op: S3 has no directories, just object keys -- a "directory" is a key prefix. */
	public function removeDirectory(string $path): void {
	}

}
