<?php

namespace MagratheaExplorer\Tests\Shared;

use MagratheaExplorer\SystemApi;
use PHPUnit\Framework\TestCase;

/** Tier 2 §2.3 of the 1.5.0 plan: `/changelog` keeps every entry type and every wrapped line. */
class ChangelogTest extends TestCase {

	const SAMPLE = <<<'MD'
		# Changelog

		## 2.0.0
		unreleased
		- **breaking:** Timestamps are UTC. They
		  were local
		  time.
		- **db:** `files.size` is a bigint.
		- **upgrade:** Run the migration
		  first.
		- **internal:** Refactored things
		  nobody sees.
		- Plain bullet, no type
		  with a wrapped line.
		- **fix:**
		  Text that starts on the next line.

		A paragraph after a blank line.

		## v.1.9.1
		2026-01-02
		- **new:** One line.
		- **Improvement:** Mixed case
		  type.
		- **improvements** No colon.
		MD;

	public function testParsesEveryType(): void {
		$changelog = SystemApi::ParseChangelog(self::SAMPLE);
		$this->assertSame([
			[
				"version" => "2.0.0",
				"date" => null,
				"changes" => [
					["type" => "breaking", "description" => "Timestamps are UTC. They were local time."],
					["type" => "db", "description" => "`files.size` is a bigint."],
					["type" => "upgrade", "description" => "Run the migration first."],
					["type" => "fix", "description" => "Text that starts on the next line."],
				],
			],
			[
				"version" => "1.9.1",
				"date" => "2026-01-02",
				"changes" => [
					["type" => "new", "description" => "One line."],
					["type" => "improvement", "description" => "Mixed case type."],
					["type" => "improvements", "description" => "No colon."],
				],
			],
		], $changelog);
	}

	public function testCrlf(): void {
		$changelog = SystemApi::ParseChangelog(str_replace("\n", "\r\n", self::SAMPLE));
		$this->assertSame("Timestamps are UTC. They were local time.", $changelog[0]["changes"][0]["description"]);
	}

	public function testTheRealChangelog(): void {
		$changelog = SystemApi::ParseChangelog(file_get_contents(__DIR__."/../../changelog.md"));
		$this->assertGreaterThanOrEqual(5, count($changelog));
		$this->assertMatchesRegularExpression('/^[0-9]+\.[0-9]+\.[0-9]+$/', $changelog[0]["version"]);
		foreach($changelog as $version) {
			$this->assertNotEmpty($version["changes"], $version["version"]." has no entries");
			foreach($version["changes"] as $change) {
				$this->assertContains($change["type"], SystemApi::CHANGELOG_TYPES);
				$this->assertStringNotContainsString("  ", $change["description"], "a wrapped line joined with its indentation");
				$this->assertDoesNotMatchRegularExpression('/^- \*\*/', $change["description"]);
			}
		}
		// 1.4.0 has every type but `new`, and entries that wrap
		$v140 = array_values(array_filter($changelog, fn($v) => $v["version"] === "1.4.0"))[0];
		$this->assertSame("2026-11-17", $v140["date"]);
		$this->assertSame(["improvement", "fix", "fix", "fix", "breaking", "breaking", "db", "upgrade"], array_column($v140["changes"], "type"));
		$this->assertStringEndsWith("\"Updating an instance\" in `docs/deploy.md`.", $v140["changes"][7]["description"]);
	}

}
