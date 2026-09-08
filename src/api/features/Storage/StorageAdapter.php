<?php

namespace MagratheaExplorer\Storage;

/**
 * Storage is direct-from-storage, no PHP in the read path: `url()` returns a link the
 * vhost (local) or the bucket/CDN (s3) serves directly. `meta` carries whatever the
 * upload pipeline decided at upload time -- content_type (always the sniffed MIME, never
 * client-supplied) and disposition ("attachment" for file_type=other/SVG, "inline"
 * otherwise) -- since there's no per-request opportunity to correct them later.
 */
interface StorageAdapter {

	/**
	 * @param string $path        Storage-relative path/object key, e.g. "{token}.{ext}"
	 * @param string $localTmpFile Local filesystem path of the file to store
	 * @param array{content_type: string, disposition: string} $meta
	 * @throws StorageException
	 */
	public function put(string $path, string $localTmpFile, array $meta): void;

	/**
	 * @throws StorageException
	 */
	public function delete(string $path): void;

	public function url(string $path): string;

	public function exists(string $path): bool;

}
