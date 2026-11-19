<?php

namespace MagratheaExplorer\Tests\File;

use Magrathea\Exceptions\MagratheaApiException;
use Magrathea\Exceptions\MagratheaDBException;
use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\File\UploadPipeline;
use MagratheaExplorer\File\UploadToken;
use MagratheaExplorer\File\UploadTokenApi;
use MagratheaExplorer\File\UploadTokenControl;
use MagratheaExplorer\Tests\Support\FakeDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Direct uploads (Tier 3 §5): the mint body, the claim, and how a token is settled. */
class UploadTokenTest extends TestCase {

	const MB = 1048576;

	private ?FakeDatabase $db = null;

	protected function tearDown(): void {
		if($this->db !== null) FakeDatabase::Uninstall();
	}

	public function testDefaults(): void {
		$this->assertSame([
			"max_size" => 150 * self::MB, "file_types" => null, "expires_in" => 900,
			"verbatim" => null, "no_convert" => null, "tags" => [],
		], UploadTokenApi::ParseCreate([], 150 * self::MB), "the key's limit, any type, 15 minutes, the key's defaults");
	}

	public function testFields(): void {
		$parsed = UploadTokenApi::ParseCreate([
			"folder_uuid" => "0196b1d2-7f3e-7c4a-9b1e-2f6a8d4c1e90", "max_size" => "1048576", "file_types" => ["video", "IMAGE", "image"],
			"expires_in" => 60, "verbatim" => true, "no_convert" => "0", "tags" => "print, 2026",
		], 150 * self::MB);
		$this->assertSame(self::MB, $parsed["max_size"]);
		$this->assertSame(["image", "video"], $parsed["file_types"], "deduplicated, lowercased, in a fixed order");
		$this->assertSame(60, $parsed["expires_in"]);
		$this->assertTrue($parsed["verbatim"]);
		$this->assertFalse($parsed["no_convert"]);
		$this->assertSame(["print", "2026"], $parsed["tags"]);
		$this->assertSame(["document", "other"], UploadTokenApi::ParseCreate(["file_types" => "other,document"], 10)["file_types"]);
	}

	public static function refused(): array {
		return [
			"unknown field" => [["max_bytes" => 10], "max_bytes"],
			"max_size over the key's limit" => [["max_size" => 101], "max_size"],
			"max_size 0" => [["max_size" => 0], "max_size"],
			"max_size not a number" => [["max_size" => "10MB"], "max_size"],
			"max_size a float" => [["max_size" => 1.5], "max_size"],
			"expires_in below 60" => [["expires_in" => 59], "expires_in"],
			"expires_in above 900" => [["expires_in" => 901], "expires_in"],
			"an unknown file type" => [["file_types" => ["image", "exe"]], "file_types"],
			"file_types empty" => [["file_types" => []], "file_types"],
			"file_types only commas" => [["file_types" => " , "], "file_types"],
			"file_types an object" => [["file_types" => ["a" => "image"]], "file_types"],
			"a tag over 100 characters" => [["tags" => str_repeat("a", 101)], "tags"],
			"too many tags" => [["tags" => implode(",", array_map(fn($n) => "tag-".$n, range(1, 200)))], "tags"],
			"folder_uuid not a string" => [["folder_uuid" => ["x"]], "folder_uuid"],
		];
	}

	#[DataProvider("refused")]
	public function testRefusedWith40013(array $body, string $field): void {
		try {
			UploadTokenApi::ParseCreate($body, 100);
			$this->fail("expected 40013");
		} catch(MagratheaApiException $ex) {
			$this->assertSame(40013, $ex->getCode());
			$this->assertStringContainsString($field, $ex->getMessage());
		}
	}

	public function testABadFlagIs4009(): void {
		$this->expectExceptionCode(4009);
		UploadTokenApi::ParseCreate(["verbatim" => "maybe"], 100);
	}

	public function testRefusalCodes(): void {
		$this->assertSame(4046, UploadTokenControl::Refusal(null, false)[0], "gone (purged, or the key deleted)");
		$this->assertSame(4101, UploadTokenControl::Refusal("pending", true)[0]);
		$this->assertSame(4092, UploadTokenControl::Refusal("used", true)[0], "used beats expired");
		$this->assertSame(4092, UploadTokenControl::Refusal("used", false)[0]);
		$this->assertSame(4092, UploadTokenControl::Refusal("uploading", false)[0]);
		$this->assertSame(4092, UploadTokenControl::Refusal("failed", false)[0]);
		$this->assertSame(4092, UploadTokenControl::Refusal("pending", false)[0], "lost a race to a request that put it back");
	}

	public function testHttpStatus(): void {
		$this->assertSame(415, UploadTokenApi::HttpStatus(ErrorCodes::Instance()->GetException(4151)));
		$this->assertSame(400, UploadTokenApi::HttpStatus(ErrorCodes::Instance()->GetException(40013)));
		$this->assertSame(413, UploadTokenApi::HttpStatus(ErrorCodes::Instance()->GetException(4131)));
		$this->assertSame(500, UploadTokenApi::HttpStatus(ErrorCodes::Instance()->GetException(5001)));
		$this->assertSame(500, UploadTokenApi::HttpStatus(new MagratheaDBException("duplicate", 4031)), "a DB error is a 500, whatever its errno");
		$this->assertSame(500, UploadTokenApi::HttpStatus(new \RuntimeException("boom")));
		$this->assertSame(500, UploadTokenApi::HttpStatus(new \RuntimeException("x", 1062)));
	}

	public function testView(): void {
		$row = new UploadToken();
		$row->uuid = "tok-uuid";
		$row->status = "pending";
		$row->expires_at = 1_000_000;
		$view = UploadTokenApi::View($row, 999_999, null);
		$this->assertSame(["id" => "tok-uuid", "status" => "pending", "expires_at" => "1970-01-12T13:46:40Z", "file" => null, "error" => null], $view);
		$this->assertSame("expired", UploadTokenApi::View($row, 1_000_000, null)["status"]);

		$row->status = "failed";
		$row->error_code = 4151;
		$row->error = "Unsupported or dangerous file type: audio isn't allowed";
		$view = UploadTokenApi::View($row, 2_000_000, null);
		$this->assertSame("failed", $view["status"], "only a pending token reads expired");
		$this->assertSame(["code" => 4151, "message" => "Unsupported or dangerous file type: audio isn't allowed"], $view["error"]);
	}

	public function testTheTokenShape(): void {
		$this->assertNull(UploadTokenControl::GetByToken("short"));
		$this->assertNull(UploadTokenControl::GetByToken(str_repeat("A", 64)), "the router lowercases paths: upper case is never minted");
		$this->assertSame(hash("sha256", "abc"), UploadTokenControl::Hash("abc"));
	}

	public function testAllowedTypes(): void {
		UploadPipeline::AssertAllowedType(null, "other");
		UploadPipeline::AssertAllowedType(["image", "video"], "video");
		try {
			UploadPipeline::AssertAllowedType(["image"], "audio");
			$this->fail("expected 4151");
		} catch(MagratheaApiException $ex) {
			$this->assertSame(4151, $ex->getCode());
			$this->assertStringContainsString("isn't allowed by this upload token (allowed: image)", $ex->getMessage());
		}
	}

	// ---- the claim and how a token is settled, against a fake database

	private function token(): UploadToken {
		$token = new UploadToken();
		$token->id = 31;
		$token->status = "pending";
		return $token;
	}

	private function settles(): array {
		return array_map(fn($s) => $s["sql"], $this->db->Statements('/^UPDATE `upload_tokens` SET `status` = \'(pending|failed|used)\'/'));
	}

	public function testAClaimThatLosesSaysWhy(): void {
		$this->db = FakeDatabase::Install();
		$this->db->On("/SET `status` = 'uploading'/", fn() => 0)
			->On('/SELECT `status`, `expires_at` <= UNIX_TIMESTAMP\(\)/', fn() => ["status" => "pending", "expired" => 1]);
		try {
			UploadTokenApi::Consume($this->token(), fn() => $this->fail("stored without a claim"));
			$this->fail("expected 4101");
		} catch(MagratheaApiException $ex) {
			$this->assertSame(4101, $ex->getCode());
		}
		$this->assertSame([], $this->settles(), "a token that wasn't claimed isn't touched");
	}

	public function testStoredLeavesTheTokenToTheFileTransaction(): void {
		$this->db = FakeDatabase::Install();
		$result = UploadTokenApi::Consume($this->token(), fn(UploadToken $t) => ["uuid" => "file"]);
		$this->assertSame(["uuid" => "file"], $result);
		$this->assertSame([], $this->settles(), "`used` is written by MarkUsed(), inside the file's transaction");
	}

	public function testA4xxUsesTheTokenUpWithTheReason(): void {
		$this->db = FakeDatabase::Install();
		try {
			UploadTokenApi::Consume($this->token(), fn() => ErrorCodes::Instance()->ThrowException(4151, null, "audio isn't allowed by this upload token"));
			$this->fail("expected 4151");
		} catch(MagratheaApiException $ex) {
			$this->assertSame(4151, $ex->getCode());
		}
		$failed = $this->db->Statements("/SET `status` = 'failed'/");
		$this->assertCount(1, $failed);
		$this->assertSame(4151, $failed[0]["values"][0]);
		$this->assertStringContainsString("audio isn't allowed", $failed[0]["values"][1]);
		$this->assertStringContainsString("AND `status` = 'uploading'", $failed[0]["sql"], "never overwrites a used token");
	}

	public static function retryable(): array {
		return [
			"storage" => [fn() => ErrorCodes::Instance()->ThrowException(5001, null, "s3 down")],
			"database" => [fn() => throw new MagratheaDBException("gone away", 2006)],
			"a PHP error" => [fn() => throw new \TypeError("bug")],
		];
	}

	#[DataProvider("retryable")]
	public function testA5xxPutsTheTokenBack(callable $store): void {
		$this->db = FakeDatabase::Install();
		try {
			UploadTokenApi::Consume($this->token(), $store);
			$this->fail("expected the store's exception");
		} catch(\Throwable $ex) {
		}
		$this->assertSame(1, count($this->db->Statements("/SET `status` = 'pending' WHERE `id` = \? AND `status` = 'uploading'/")));
		$this->assertSame([], $this->db->Statements("/SET `status` = 'failed'/"));
	}

	public function testMarkUsedThrowsWhenTheTokenIsntHeld(): void {
		$this->db = FakeDatabase::Install();
		$this->db->On("/SET `status` = 'used'/", fn() => 0);
		$this->expectException(\RuntimeException::class);
		UploadTokenControl::MarkUsed(31, 99);
	}

}
