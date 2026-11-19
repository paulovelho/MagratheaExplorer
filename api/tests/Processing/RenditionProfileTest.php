<?php

namespace MagratheaExplorer\Tests\Processing;

use Magrathea\ConfigApp;
use MagratheaExplorer\Key\Key;
use MagratheaExplorer\Processing\RenditionProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The rendition-profile validator (plan §7.3): what the admin form and the queue accept. */
class RenditionProfileTest extends TestCase {

	protected function setUp(): void {
		ConfigApp::Instance()->Mock([]);
	}

	protected function tearDown(): void {
		ConfigApp::Instance()->UnMock();
	}

	public function testNormalizesQualityAndBackground(): void {
		$specs = RenditionProfile::Parse('[
			{"format": "webp", "fit": "contain", "height": 300, "width": 300, "name": "thumb"},
			{"name": "preview", "width": 1200, "height": 800, "fit": "cover", "format": "jpg", "quality": 70, "background": "#FFAA00"},
			{"name": "legacy_thumb", "width": 200, "height": 200, "fit": "cover", "format": "legacy"}
		]');
		$this->assertSame([
			["name" => "thumb", "width" => 300, "height" => 300, "fit" => "contain", "format" => "webp", "quality" => 85],
			["name" => "preview", "width" => 1200, "height" => 800, "fit" => "cover", "format" => "jpg", "quality" => 70, "background" => "#ffaa00"],
			["name" => "legacy_thumb", "width" => 200, "height" => 200, "fit" => "cover", "format" => "legacy", "quality" => 85, "background" => "#ffffff"],
		], $specs);
		$this->assertSame($specs, RenditionProfile::Parse(RenditionProfile::Encode($specs)), "the stored form reads back the same");
		$this->assertSame($specs, RenditionProfile::Parse(RenditionProfile::Pretty($specs)), "so does the form's");
	}

	public function testAnEmptyListMeansNoRenditions(): void {
		$this->assertSame([], RenditionProfile::Parse("[]"));
		$this->assertSame("[]", RenditionProfile::Pretty([]));
	}

	public function testTheBoundsAreInclusive(): void {
		$specs = RenditionProfile::Parse('[{"name": "a", "width": 1, "height": 2048, "fit": "cover", "format": "png", "quality": 100},
			{"name": "b", "width": 2048, "height": 1, "fit": "cover", "format": "png", "quality": 1}]');
		$this->assertCount(2, $specs);
		$six = array_map(fn($i) => ["name" => "r".$i, "width" => 10, "height" => 10, "fit" => "cover", "format" => "png"], range(1, 6));
		$this->assertCount(6, RenditionProfile::Validate($six));
	}

	public static function invalidProfiles(): array {
		$ok = ["name" => "thumb", "width" => 300, "height" => 300, "fit" => "contain", "format" => "webp"];
		$seven = array_map(fn($i) => ["name" => "r".$i] + $ok, range(1, 7));
		return [
			"not JSON" => ['[{"name": "thumb",]', "valid JSON"],
			"an object, not a list" => ['{"name": "thumb"}', "JSON list"],
			"a spec that's a list" => ['[[1, 2]]', "must be an object"],
			"seven specs" => [json_encode($seven), "at most 6"],
			"unknown field" => [json_encode([$ok + ["widht" => 3]]), "unknown field \"widht\""],
			"no name" => [json_encode([array_diff_key($ok, ["name" => 1])]), "`name`"],
			"uppercase name" => [json_encode([["name" => "Thumb"] + $ok]), "`name`"],
			"name too long" => [json_encode([["name" => str_repeat("a", 33)] + $ok]), "`name`"],
			"all-digit name" => [json_encode([["name" => "300"] + $ok]), "only digits"],
			"duplicate name" => [json_encode([$ok, $ok]), "already has this name"],
			"width 0" => [json_encode([["width" => 0] + $ok]), "`width`"],
			"height 2049" => [json_encode([["height" => 2049] + $ok]), "`height`"],
			"width as a string" => [json_encode([["width" => "300"] + $ok]), "`width`"],
			"width as a float" => ['[{"name": "t", "width": 300.0, "height": 300, "fit": "cover", "format": "png"}]', "`width`"],
			"fit" => [json_encode([["fit" => "fill"] + $ok]), "`fit`"],
			"format" => [json_encode([["format" => "gif"] + $ok]), "`format`"],
			"quality 0" => [json_encode([["quality" => 0] + $ok]), "`quality`"],
			"quality 101" => [json_encode([["quality" => 101] + $ok]), "`quality`"],
			"background on webp" => [json_encode([["background" => "#000000"] + $ok]), "only applies to jpg"],
			"background not a color" => [json_encode([["format" => "jpg", "background" => "white"] + $ok]), "#rrggbb"],
			"background short form" => [json_encode([["format" => "jpg", "background" => "#fff"] + $ok]), "#rrggbb"],
		];
	}

	#[DataProvider("invalidProfiles")]
	public function testRefuses(string $json, string $messagePart): void {
		try {
			RenditionProfile::Parse($json);
			$this->fail("accepted: ".$json);
		} catch(\InvalidArgumentException $ex) {
			$this->assertStringContainsString($messagePart, $ex->getMessage());
		}
	}

	public function testTheMessageNamesTheSpec(): void {
		$this->expectExceptionMessage("Spec 2 (\"preview\"): `width`");
		RenditionProfile::Parse('[{"name": "thumb", "width": 300, "height": 300, "fit": "contain", "format": "webp"},
			{"name": "preview", "width": 5000, "height": 300, "fit": "contain", "format": "webp"}]');
	}

	public function testTheDefaultIsTodaysThumbnail(): void {
		$this->assertSame([[
			"name" => "thumb", "width" => 200, "height" => 200, "fit" => "cover", "format" => "legacy", "quality" => 85, "background" => "#ffffff",
		]], RenditionProfile::Default());
		$this->assertSame(RenditionProfile::Default(), RenditionProfile::Validate(RenditionProfile::Default()), "the default passes its own validator");

		ConfigApp::Instance()->Mock(["thumbnail_size" => "320"]);
		$this->assertSame(320, RenditionProfile::Default()[0]["width"]);
		ConfigApp::Instance()->Mock(["thumbnail_size" => "9000"]);
		$this->assertSame(2048, RenditionProfile::ThumbnailSize(), "kept within the validator's bounds");
		ConfigApp::Instance()->Mock(["thumbnail_size" => "big"]);
		$this->assertSame(200, RenditionProfile::ThumbnailSize(), "unreadable = 200, as before 1.5.0");
		ConfigApp::Instance()->Mock(["thumbnail_size" => "0"]);
		$this->assertSame(200, RenditionProfile::ThumbnailSize());
	}

	public function testThePresetIsValid(): void {
		$this->assertSame(RenditionProfile::PRESET_THUMB_PREVIEW, RenditionProfile::Validate(RenditionProfile::PRESET_THUMB_PREVIEW));
	}

	public function testAKeyWithoutAProfileGetsTheDefault(): void {
		$key = new Key();
		$this->assertFalse($key->HasOwnRenditionProfile());
		$this->assertSame(RenditionProfile::Default(), $key->RenditionSpecs());

		$key->rendition_profile = "  ";
		$this->assertFalse($key->HasOwnRenditionProfile(), "blank counts as none");

		$key->rendition_profile = "[]";
		$this->assertTrue($key->HasOwnRenditionProfile());
		$this->assertSame([], $key->RenditionSpecs(), "its own empty profile: no renditions");

		$key->rendition_profile = '[{"name": "x"}]';
		$this->expectException(\InvalidArgumentException::class);
		$key->RenditionSpecs();
	}

	public function testDescribe(): void {
		$this->assertSame("none", RenditionProfile::Describe([]));
		$this->assertSame("thumb 300x300 contain webp, preview 1200x1200 contain webp", RenditionProfile::Describe(RenditionProfile::PRESET_THUMB_PREVIEW));
	}

}
