<?php

namespace MagratheaExplorer\Tests\Support;

use MagratheaExplorer\Storage\StorageAdapter;
use MagratheaExplorer\Storage\StorageException;

/**
 * An in-memory StorageAdapter. `$failOn` makes an operation fail for a path:
 * ["copy" => ["k/a.png"], "delete" => ["*"]] ("*" = every path).
 */
class FakeStorage implements StorageAdapter {

	/** @var array<string, string> path => bytes */
	public array $objects = [];
	/** @var array<string, string> path => content type */
	public array $types = [];
	/** @var array<string, string[]> operation => paths */
	public array $failOn = [];
	/** @var string[] "op path" (or "copy from to") in order */
	public array $log = [];

	private function Fails(string $op, string $path): bool {
		$paths = $this->failOn[$op] ?? [];
		return in_array("*", $paths, true) || in_array($path, $paths, true);
	}

	public function put(string $path, string $localTmpFile, array $meta): void {
		$this->log[] = "put ".$path;
		if($this->Fails("put", $path)) throw new StorageException("fake put failure: ".$path);
		$this->objects[$path] = (string)file_get_contents($localTmpFile);
		$this->types[$path] = $meta["content_type"] ?? "application/octet-stream";
	}

	public function fetch(string $path, string $localTmpFile): void {
		$this->log[] = "fetch ".$path;
		if(!isset($this->objects[$path]) || $this->Fails("fetch", $path)) throw new StorageException("fake fetch failure: ".$path);
		file_put_contents($localTmpFile, $this->objects[$path]);
	}

	public function copy(string $from, string $to): void {
		$this->log[] = "copy ".$from." ".$to;
		if(!isset($this->objects[$from]) || $this->Fails("copy", $from)) throw new StorageException("fake copy failure: ".$from);
		$this->objects[$to] = $this->objects[$from];
		$this->types[$to] = $this->types[$from] ?? "application/octet-stream";
	}

	public function delete(string $path): void {
		$this->log[] = "delete ".$path;
		if($this->Fails("delete", $path)) throw new StorageException("fake delete failure: ".$path);
		unset($this->objects[$path], $this->types[$path]);
	}

	public function url(string $path, ?string $downloadName = null, string $dispositionType = "inline"): string {
		return "https://files.test/".ltrim($path, "/");
	}

	public function exists(string $path): bool {
		return isset($this->objects[$path]);
	}

	public function removeDirectory(string $path): void {
	}

}
