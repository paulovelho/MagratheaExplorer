<?php

namespace MagratheaExplorer;

/**
 * One cron job at a time per name: a run that finds the lock taken exits at once, so runs
 * started by an every-minute cron don't pile up on shared hosting. flock() is released by the
 * kernel when the process ends, killed or not, so a crashed run never leaves a stale lock.
 * The lock files live in ExplorerConfig::ProcessingTmpPath(), inside the instance.
 */
class CronLock {

	/** @var resource */
	private $handle;

	/** @param resource $handle */
	private function __construct($handle) {
		$this->handle = $handle;
	}

	/** null = another run holds it. $name: `[a-z0-9-]+`, the lock file is `{name}.lock`. */
	public static function TryAcquire(string $name, ?string $dir = null): ?self {
		if(preg_match('/^[a-z0-9-]+$/', $name) !== 1) {
			throw new \InvalidArgumentException("invalid lock name: ".$name);
		}
		$dir ??= ExplorerConfig::ProcessingTmpPath();
		$file = $dir."/".$name.".lock";
		$handle = @fopen($file, "c");
		if($handle === false) {
			throw new \RuntimeException("could not open lock file ".$file);
		}
		if(!flock($handle, LOCK_EX | LOCK_NB)) {
			fclose($handle);
			return null;
		}
		return new self($handle);
	}

	public function Release(): void {
		if(!is_resource($this->handle)) return;
		flock($this->handle, LOCK_UN);
		fclose($this->handle);
	}

	public function __destruct() {
		$this->Release();
	}

}
