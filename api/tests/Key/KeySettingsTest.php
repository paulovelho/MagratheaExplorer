<?php

namespace MagratheaExplorer\Tests\Key;

use Magrathea\ConfigApp;
use MagratheaExplorer\Key\Key;
use MagratheaExplorer\Key\KeyControl;
use MagratheaExplorer\Key\KeySettings;
use MagratheaExplorer\Key\KeySettingsException;
use MagratheaExplorer\Provisioning\Provisioning;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tier 2 §4 of the 1.5.0 plan: the admin's key form and admin → Provisioning parse the same
 * settings the same way, and a provisioned key copies the provisioning row's.
 */
class KeySettingsTest extends TestCase {

	const MB = 1048576;

	protected function setUp(): void {
		ConfigApp::Instance()->Mock([]);
	}

	protected function tearDown(): void {
		ConfigApp::Instance()->UnMock();
	}

	public function testOnlyTheFieldsSent(): void {
		$this->assertSame([], KeySettings::FromForm([]));
		$this->assertSame(["usage_limit" => 10], KeySettings::FromForm(["usage_limit" => "10", "name" => "x", "active" => "1"]));
	}

	public function testEveryField(): void {
		$settings = KeySettings::FromForm([
			"usage_limit" => " 100 ",
			"usage_limit_mb" => "2048",
			"verbatim_default" => "1",
			"max_upload_size_mb" => "150",
			"rendition_profile" => '[{"name":"thumb","width":300,"height":300,"fit":"contain","format":"webp"}]',
		]);
		$this->assertSame(100, $settings["usage_limit"]);
		$this->assertSame(2048, $settings["usage_limit_mb"]);
		$this->assertTrue($settings["verbatim_default"]);
		$this->assertSame(150 * self::MB, $settings["max_upload_size"]);
		$this->assertSame('[{"name":"thumb","width":300,"height":300,"fit":"contain","format":"webp","quality":85}]', $settings["rendition_profile"]);
	}

	public function testEmptyMeansNoLimitOrTheInstanceDefault(): void {
		$settings = KeySettings::FromForm(["usage_limit" => "", "usage_limit_mb" => " ", "verbatim_default" => "", "max_upload_size_mb" => "", "rendition_profile" => ""]);
		$this->assertSame(["usage_limit" => null, "usage_limit_mb" => null, "verbatim_default" => null, "max_upload_size" => null, "rendition_profile" => null], $settings);
	}

	/** 0 is a limit (nothing may be uploaded). Before 1.5.0 a new key's 0 silently became "no limit". */
	public function testZeroIsALimit(): void {
		$this->assertSame(["usage_limit" => 0, "usage_limit_mb" => 0], KeySettings::FromForm(["usage_limit" => "0", "usage_limit_mb" => "0"]));
		$key = new Key();
		KeySettings::Apply($key, ["usage_limit" => 0, "usage_limit_mb" => 0]);
		$key->Normalize();
		$this->assertSame(0, $key->usage_limit);
		$this->assertSame(0, $key->usage_limit_mb);
	}

	public function testVerbatimOptions(): void {
		$this->assertFalse(KeySettings::FromForm(["verbatim_default" => "0"])["verbatim_default"]);
		$this->assertNull(KeySettings::FromForm(["verbatim_default" => "maybe"])["verbatim_default"]);
	}

	public static function refused(): array {
		return [
			"usage_limit: text" => ["usage_limit", "lots"],
			"usage_limit: negative" => ["usage_limit", "-1"],
			"usage_limit: decimal" => ["usage_limit", "1.5"],
			"usage_limit_mb: above int(11)" => ["usage_limit_mb", "2147483648"],
			"usage_limit_mb: huge" => ["usage_limit_mb", "99999999999999999999"],
			"max_upload_size_mb: zero" => ["max_upload_size_mb", "0"],
			"max_upload_size_mb: text" => ["max_upload_size_mb", "big"],
			"rendition_profile: not JSON" => ["rendition_profile", "{nope"],
			"rendition_profile: not a list" => ["rendition_profile", '{"name":"thumb"}'],
		];
	}

	#[DataProvider("refused")]
	public function testRefusedFieldIsNamed(string $field, string $value): void {
		try {
			KeySettings::FromForm(["usage_limit" => "5", $field => $value]);
			$this->fail("expected a refusal of ".$field);
		} catch(KeySettingsException $ex) {
			$this->assertSame($field, $ex->field);
			$this->assertNotSame("", $ex->getMessage());
		}
	}

	public function testIntLimitAtTheTop(): void {
		$this->assertSame(2147483647, KeySettings::FromForm(["usage_limit_mb" => "2147483647"])["usage_limit_mb"]);
		$this->assertSame(7, KeySettings::FromForm(["usage_limit" => "0000000000007"])["usage_limit"]);
	}

	/** What POST /provision/keys does: the provisioning row's settings, copied onto the new key. */
	public function testAProvisionedKeyCopiesTheProvisioningSettings(): void {
		$provisioning = new Provisioning();
		$provisioning->max_keys = 500;
		$provisioning->usage_limit = null;
		$provisioning->usage_limit_mb = 2048;
		$provisioning->verbatim_default = true;
		$provisioning->max_upload_size = 150 * self::MB;
		$provisioning->rendition_profile = '[{"name":"thumb","width":300,"height":300,"fit":"contain","format":"webp","quality":85}]';

		$settings = KeySettings::Of($provisioning);
		$this->assertSame(KeySettings::FIELDS, array_keys($settings));
		$this->assertArrayNotHasKey("max_keys", $settings);

		$key = new Key();
		KeySettings::Apply($key, $settings);
		$this->assertNull($key->usage_limit);
		$this->assertSame(2048, $key->usage_limit_mb);
		$this->assertTrue($key->VerbatimByDefault());
		$this->assertSame(150 * self::MB, $key->ConfiguredMaxUploadSize());
		$this->assertSame("thumb", $key->RenditionSpecs()[0]["name"]);
	}

	/** null on the provisioning row is null on the key: the instance default, read when used. */
	public function testUnsetProvisioningSettingsMeanTheInstanceDefault(): void {
		$key = new Key();
		KeySettings::Apply($key, KeySettings::Of(new Provisioning()));
		$this->assertFalse($key->HasOwnRenditionProfile());
		$this->assertSame(Key::InstanceMaxUploadSize(), $key->ConfiguredMaxUploadSize());
		$this->assertSame(Key::InstanceVerbatimDefault(), $key->VerbatimByDefault());
	}

	public function testApplyIgnoresEverythingElse(): void {
		$key = new Key();
		$key->name = "kept";
		KeySettings::Apply($key, ["name" => "changed", "secret_hash" => "x", "provisioned" => true, "usage_limit" => 3]);
		$this->assertSame("kept", $key->name);
		$this->assertNull($key->secret_hash);
		$this->assertNull($key->provisioned);
		$this->assertSame(3, $key->usage_limit);
	}

	public function testWorstCase(): void {
		$provisioning = new Provisioning();
		$this->assertNull($provisioning->WorstCaseMb());
		$provisioning->max_keys = 500;
		$this->assertNull($provisioning->WorstCaseMb(), "no size limit: unbounded");
		$provisioning->usage_limit_mb = 2048;
		$this->assertSame(1024000, $provisioning->WorstCaseMb());
		$provisioning->max_keys = null;
		$this->assertNull($provisioning->WorstCaseMb(), "no key limit: unbounded");
	}

	public static function names(): array {
		return [
			"plain" => ["workspace-42", "workspace-42"],
			"trimmed" => ["  workspace-42 ", "workspace-42"],
			"unicode" => ["área de trabalho", "área de trabalho"],
			"255 characters" => [str_repeat("é", 255), str_repeat("é", 255)],
		];
	}

	#[DataProvider("names")]
	public function testName(string $raw, string $expected): void {
		$this->assertSame($expected, KeyControl::NormalizeName($raw));
	}

	public static function notNames(): array {
		return [
			"empty" => [""], "spaces" => ["   "], "256 characters" => [str_repeat("a", 256)],
			"newline inside" => ["work\nspace"], "NUL" => ["work\0space"], "tab inside" => ["work\tspace"],
			"invalid UTF-8" => ["work\xff"], "a number" => [42], "null" => [null], "a list" => [["a"]],
		];
	}

	#[DataProvider("notNames")]
	public function testNotAName(mixed $raw): void {
		$this->assertNull(KeyControl::NormalizeName($raw));
	}

}
