<?php

namespace MagratheaExplorer\Processing;

use MagratheaExplorer\ExplorerConfig;

/**
 * One processor run's scratch folder, `processing_tmp_path/{name}/`:
 *   original   where a caller puts the input (fetched from storage, or copied); optional
 *   out/       the processor's only writable place, renditions land here
 *   tmp/       the processor's TMPDIR
 * The caller owns its life: Create(), put the input, run, store the outputs, Remove() in a
 * `finally`. A run killed before its `finally` leaves the folder behind; Create() with the same
 * name wipes it first.
 */
final class ProcessorJobDir {

	public readonly string $path;
	public readonly string $input;
	public readonly string $outDir;
	public readonly string $tmpDir;

	private function __construct(string $path) {
		$this->path = $path;
		$this->input = $path."/original";
		$this->outDir = $path."/out";
		$this->tmpDir = $path."/tmp";
	}

	/** $name: `job-{id}-{attempt}` in the queue. Letters, digits, `.`, `_`, `-`; no leading dot. */
	public static function Create(string $name, ?string $root = null): self {
		$dir = self::Named($name, $root);
		if(file_exists($dir->path) || is_link($dir->path)) $dir->Remove();
		foreach([$dir->path, $dir->outDir, $dir->tmpDir] as $path) {
			if(!@mkdir($path, 0700) && !is_dir($path)) {
				throw new \RuntimeException("could not create job dir ".$path);
			}
		}
		return $dir;
	}

	/** Removes a leftover of that name (a run killed before its `finally`), creating nothing. */
	public static function RemoveLeftover(string $name, ?string $root = null): bool {
		return self::Named($name, $root)->Remove();
	}

	private static function Named(string $name, ?string $root): self {
		if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $name) !== 1) {
			throw new \InvalidArgumentException("invalid job dir name: ".$name);
		}
		$root ??= ExplorerConfig::ProcessingTmpPath();
		return new self(rtrim($root, "/")."/".$name);
	}

	/**
	 * Deletes the folder and everything in it. Never follows a symlink: the processor wrote
	 * out/ and tmp/, so a link there is removed, never what it points to. Best effort, never
	 * throws: false if something couldn't be deleted.
	 */
	public function Remove(): bool {
		if(is_link($this->path)) return @unlink($this->path);
		if(!is_dir($this->path)) return true;
		$ok = true;
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		try {
			foreach($items as $item) {
				$path = $item->getPathname();
				$ok = ($item->isDir() && !$item->isLink() ? @rmdir($path) : @unlink($path)) && $ok;
			}
		} catch(\UnexpectedValueException $ex) {
			$ok = false; // a subfolder that can't be listed
		}
		return @rmdir($this->path) && $ok;
	}

}
