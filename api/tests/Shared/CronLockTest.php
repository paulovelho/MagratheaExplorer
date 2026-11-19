<?php

namespace MagratheaExplorer\Tests\Shared;

use MagratheaExplorer\CronLock;
use MagratheaExplorer\Tests\Support\ScratchDir;
use PHPUnit\Framework\TestCase;

/** flock() locks belong to the open file, so a second TryAcquire() in the same process sees the first one. */
class CronLockTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = ScratchDir::Create();
	}

	protected function tearDown(): void {
		ScratchDir::Remove($this->dir);
	}

	public function testSecondRunFindsTheLockTaken(): void {
		$first = CronLock::TryAcquire("backfill-sha256", $this->dir);
		$this->assertNotNull($first);
		$this->assertFileExists($this->dir."/backfill-sha256.lock");
		$this->assertNull(CronLock::TryAcquire("backfill-sha256", $this->dir));

		$first->Release();
		$again = CronLock::TryAcquire("backfill-sha256", $this->dir);
		$this->assertNotNull($again);
		$again->Release();
	}

	public function testNamesDontShareALock(): void {
		$backfill = CronLock::TryAcquire("backfill-sha256", $this->dir);
		$process = CronLock::TryAcquire("process", $this->dir);
		$this->assertNotNull($backfill);
		$this->assertNotNull($process);
		$backfill->Release();
		$process->Release();
	}

	public function testDroppingTheObjectReleases(): void {
		$lock = CronLock::TryAcquire("process", $this->dir);
		$this->assertNotNull($lock);
		unset($lock);
		$this->assertNotNull(CronLock::TryAcquire("process", $this->dir));
	}

	public function testReleaseTwiceIsHarmless(): void {
		$lock = CronLock::TryAcquire("process", $this->dir);
		$lock->Release();
		$lock->Release();
		$this->assertNotNull(CronLock::TryAcquire("process", $this->dir));
	}

	public function testNameCantLeaveTheDirectory(): void {
		foreach(["../process", "a/b", "Process", "", "x.lock"] as $name) {
			try {
				CronLock::TryAcquire($name, $this->dir);
				$this->fail("accepted lock name: ".$name);
			} catch(\InvalidArgumentException $ex) {
				$this->addToAssertionCount(1);
			}
		}
	}

}
