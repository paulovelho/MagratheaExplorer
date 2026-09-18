<?php

namespace MagratheaExplorer\Storage;

/**
 * Writes into a folder a vhost serves directly, bypassing PHP for GETs -- same
 * no-PHP-in-the-read-path property as the S3/R2 adapter. Content-Type isn't set per
 * file here (relies on the vhost's own mime-type guess by extension); Content-Disposition
 * is: url() passes it through the query string, and the storage root's .htaccess
 * (Apache) / the matching Caddyfile block (Caddy) turn that into the actual response
 * header (see api/storage/.htaccess and docker/caddy/site.caddy) -- this adapter itself
 * can't guarantee those are deployed correctly.
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

	/**
	 * The vhost's static file_server can't attach per-file headers on its own, so a
	 * download name is smuggled in as a query string ?disposition=&filename= pair and
	 * turned into Content-Disposition by the storage root's .htaccess (Apache) or the
	 * matching Caddyfile block (Caddy) -- see api/storage/.htaccess and
	 * docker/caddy/site.caddy. Both read %{QUERY_STRING}/the raw query verbatim, with
	 * no percent-decoding step of their own, so $downloadName is reduced to a charset
	 * ([A-Za-z0-9._-]) that never needs percent-encoding in the first place -- anything
	 * that did would either break the query string or show up still percent-encoded in
	 * the header. That's a stricter fallback than S3's (which keeps spaces/punctuation
	 * and full Unicode via RFC 5987, since it never round-trips through a query string).
	 */
	public function url(string $path, ?string $downloadName = null, string $dispositionType = "inline"): string {
		$url = $this->baseUrl."/".ltrim($path, "/");
		if($downloadName === null) return $url;
		$safeName = preg_replace('/[^A-Za-z0-9._-]/', "_", basename($downloadName));
		if($safeName === "" || $safeName === "." || $safeName === "..") $safeName = "download";
		return $url."?disposition=".$dispositionType."&filename=".$safeName;
	}

	public function exists(string $path): bool {
		return file_exists($this->fullPath($path));
	}

}
