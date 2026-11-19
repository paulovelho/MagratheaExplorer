<?php

namespace MagratheaExplorer\Tests\Shared;

use MagratheaExplorer\SystemApi;
use MagratheaExplorer\Tests\Support\TestConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** `GET /settings`' `source_url_enabled` (Tier 2 §6.7 of the 1.5.0 plan). */
class SettingsTest extends TestCase {

	protected function tearDown(): void {
		TestConfig::Set();
	}

	public static function values(): array {
		return [
			"absent: off" => [null, false],
			"true" => ["true", true],
			"1" => ["1", true],
			"false" => ["false", false],
			"empty (an unquoted false in the ini)" => ["", false],
			"unreadable: off, never a 5004 on a public route" => ["maybe", false],
		];
	}

	#[DataProvider("values")]
	public function testSourceUrlEnabled(?string $conf, bool $expected): void {
		TestConfig::Set($conf === null ? [] : ["source_url_enabled" => $conf]);
		$this->assertSame($expected, SystemApi::SourceUrlEnabled());
	}

}
