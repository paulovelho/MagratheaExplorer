<?php

namespace MagratheaExplorer\Tests\Provisioning;

use Magrathea\Exceptions\MagratheaApiException;
use MagratheaExplorer\Provisioning\ProvisioningApi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Tier 2 §4 of the 1.5.0 plan: POST /provision/keys takes a name and nothing else. */
class ProvisioningApiTest extends TestCase {

	public function testName(): void {
		$this->assertSame("workspace-42", ProvisioningApi::ParseCreateBody(["name" => " workspace-42 "]));
	}

	public static function missing(): array {
		return ["no body" => [[]], "null" => [["name" => null]], "empty" => [["name" => ""]], "spaces" => [["name" => "  "]]];
	}

	#[DataProvider("missing")]
	public function testMissingNameIs4001(array $body): void {
		$this->assertRefused($body, 4001, "name");
	}

	public static function invalid(): array {
		return [
			"a number" => [["name" => 42]], "a list" => [["name" => ["a"]]], "an object" => [["name" => ["a" => "b"]]],
			"true" => [["name" => true]], "too long" => [["name" => str_repeat("a", 256)]], "control character" => [["name" => "a\nb"]],
		];
	}

	#[DataProvider("invalid")]
	public function testInvalidNameIs40013(array $body): void {
		$this->assertRefused($body, 40013, "name");
	}

	public static function unknownFields(): array {
		return [
			"a setting" => ["usage_limit_mb"], "a typo" => ["Name"], "rendition_profile" => ["rendition_profile"],
			"active" => ["active"], "secret" => ["secret"],
		];
	}

	/** Settings are the operator's: a field silently ignored would look like it had applied. */
	#[DataProvider("unknownFields")]
	public function testAnyOtherFieldIs40013NamingIt(string $field): void {
		$this->assertRefused(["name" => "workspace-42", $field => "1"], 40013, $field);
	}

	private function assertRefused(array $body, int $code, string $named): void {
		try {
			ProvisioningApi::ParseCreateBody($body);
			$this->fail("expected ".$code);
		} catch(MagratheaApiException $ex) {
			$this->assertSame($code, $ex->getCode());
			$this->assertStringContainsString($named, $ex->getMessage());
		}
	}

}
