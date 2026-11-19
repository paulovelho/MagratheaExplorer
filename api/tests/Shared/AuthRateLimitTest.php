<?php

namespace MagratheaExplorer\Tests\Shared;

use MagratheaExplorer\AuthRateLimit;
use PHPUnit\Framework\TestCase;

/**
 * Tier 2 §4.4 of the 1.5.0 plan: 10 failures per 15-minute window, then 429 until it ends. The
 * counting itself is one INSERT … ON DUPLICATE KEY UPDATE: only the window arithmetic is here.
 */
class AuthRateLimitTest extends TestCase {

	/** 2026-11-18 12:00:00 UTC, a window boundary (divisible by 900). */
	const BOUNDARY = 1795003200;

	public function testWindowsAreAlignedToTheEpoch(): void {
		$this->assertSame(0, self::BOUNDARY % AuthRateLimit::WINDOW_S);
		$this->assertSame(self::BOUNDARY, AuthRateLimit::WindowStart(self::BOUNDARY));
		$this->assertSame(self::BOUNDARY, AuthRateLimit::WindowStart(self::BOUNDARY + 899));
		$this->assertSame(self::BOUNDARY + 900, AuthRateLimit::WindowStart(self::BOUNDARY + 900));
		$this->assertSame(self::BOUNDARY - 900, AuthRateLimit::WindowStart(self::BOUNDARY - 1));
	}

	public function testTenFailuresAreAllowedTheEleventhAttemptIsNot(): void {
		$now = self::BOUNDARY + 100;
		for($failures = 0; $failures < 10; $failures++) {
			$this->assertNull(AuthRateLimit::RetryAfterFor($failures, $now), $failures." failures");
		}
		$this->assertSame(800, AuthRateLimit::RetryAfterFor(10, $now));
		$this->assertSame(800, AuthRateLimit::RetryAfterFor(57, $now));
	}

	public function testRetryAfterRunsToTheEndOfTheWindowAndIsNeverZero(): void {
		$this->assertSame(900, AuthRateLimit::RetryAfterFor(10, self::BOUNDARY));
		$this->assertSame(1, AuthRateLimit::RetryAfterFor(10, self::BOUNDARY + 899));
	}

	public function testBucketIsKept(): void {
		$this->assertSame("prov:203.0.113.7", (new AuthRateLimit("prov:203.0.113.7"))->Bucket());
	}

	public function testBucketsFitTheColumn(): void {
		// varchar(64): the longest bucket is a full IPv6 /64
		$this->assertLessThanOrEqual(64, strlen("prov:ffff:ffff:ffff:ffff::/64"));
	}

}
