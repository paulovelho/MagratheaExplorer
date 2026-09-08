<?php

namespace MagratheaExplorer\Storage;

/**
 * Writes into a folder a vhost serves directly, bypassing PHP for GETs -- same
 * no-PHP-in-the-read-path property as the S3/R2 adapter. Response headers
 * (Content-Type/Content-Disposition) can't be attached to a plain file the way S3
 * object metadata can; the `Content-Disposition: attachment` protection for
 * file_type=other/SVG is a vhost/Caddy deploy-time rule instead (see storage.conf.sample
 * and the deploy note in the design doc), not something this adapter can guarantee.
 */
class LocalStorageAdapter implements StorageAdapter {

	private string $basePath;
	private string $baseUrl;

	public function __construct() {
		$path = StorageConfig::GetLocalPath();
		$url = StorageConfig::GetLocalUrl();
		if(empty($path) || empty($url)) {
			throw new StorageException("local storage driver selected but local_path/local_url are not configured in storage.conf");
		}
		$this->basePath = rtrim($path, "/");
		$this->baseUrl = rtrim($url, "/");
	}

	private function fullPath(string $path): string {
		return $this->basePath."/".ltrim($path, "/");
	}

	public function put(string $path, string $localTmpFile, array $meta): void {
		$dest = $this->fullPath($path);
		$dir = dirname($dest);
		if(!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
			throw new StorageException("could not create storage directory: ".$dir);
		}
		if(!@copy($localTmpFile, $dest)) {
			throw new StorageException("could not write file to local storage: ".$dest);
		}
	}

	public function delete(string $path): void {
		$dest = $this->fullPath($path);
		if(file_exists($dest) && !@unlink($dest)) {
			throw new StorageException("could not delete file from local storage: ".$dest);
		}
	}

	public function url(string $path): string {
		return $this->baseUrl."/".ltrim($path, "/");
	}

	public function exists(string $path): bool {
		return file_exists($this->fullPath($path));
	}

}
