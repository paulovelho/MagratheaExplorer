<?php

namespace MagratheaExplorer\Tests\File;

use Magrathea\Exceptions\MagratheaApiException;
use MagratheaExplorer\File\FileApi;
use PHPUnit\Framework\TestCase;

/** POST /files/lookup's `uuids` (Tier 3 §2 of the 1.5.0 plan): dedupe, order, cap, malformed ones. */
class LookupUuidsTest extends TestCase {

	private static function uuid(int $n): string {
		return sprintf("0196b1d3-5c2e-7a10-8f3b-%012x", $n);
	}

	public function testOrderKeptDuplicatesAndCaseFolded(): void {
		$a = self::uuid(1);
		$b = self::uuid(2);
		$this->assertSame([$b, $a], FileApi::ParseLookupUuids(["uuids" => [$b, strtoupper($a), " ".$b." ", $a]]));
	}

	public function testMalformedAreLeftOut(): void {
		$a = self::uuid(1);
		$this->assertSame([$a], FileApi::ParseLookupUuids(["uuids" => ["nope", $a, 42, null, "' OR 1=1 --", ["x"]]]));
		$this->assertSame([], FileApi::ParseLookupUuids(["uuids" => ["nope"]]));
	}

	public function testHundredDistinctPassAfterDedupe(): void {
		$uuids = array_map([self::class, "uuid"], range(1, 100));
		$this->assertCount(100, FileApi::ParseLookupUuids(["uuids" => array_merge($uuids, $uuids)]));
	}

	public function testHundredAndOneRefused(): void {
		$this->assertCode(40013, ["uuids" => array_map([self::class, "uuid"], range(1, 101))], "at most 100");
		// malformed ones count too: 101 items of garbage can't slip past the cap
		$this->assertCode(40013, ["uuids" => array_map(fn($n) => "x".$n, range(1, 101))], "at most 100");
	}

	public function testShapeErrors(): void {
		$this->assertCode(4001, [], "uuids");
		$this->assertCode(4001, ["uuids" => null], "uuids");
		$this->assertCode(40013, ["uuids" => []], "at least 1");
		$this->assertCode(40013, ["uuids" => self::uuid(1)], "list");
		$this->assertCode(40013, ["uuids" => ["a" => self::uuid(1)]], "list");
	}

	private function assertCode(int $code, array $post, string $detail): void {
		try {
			FileApi::ParseLookupUuids($post);
			$this->fail("expected ".$code);
		} catch(MagratheaApiException $ex) {
			$this->assertSame($code, $ex->getCode());
			$this->assertStringContainsString($detail, $ex->getMessage());
		}
	}

}
