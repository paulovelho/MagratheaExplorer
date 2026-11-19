<?php

namespace MagratheaExplorer\Tests\Shared;

use Magrathea\Exceptions\MagratheaApiException;
use MagratheaExplorer\File\FileApi;
use MagratheaExplorer\File\SourceUrl;
use MagratheaExplorer\Idempotency;
use MagratheaExplorer\IdempotencyClaim;
use MagratheaExplorer\IdempotencyRecord;
use MagratheaExplorer\Provisioning\ProvisioningApi;
use MagratheaExplorer\Tests\Support\FakeIdempotencyStore;
use MagratheaExplorer\Tests\Support\TestConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tier 2 §5 of the 1.5.0 plan: the header, what counts as the same request, and the state
 * machine, against FakeIdempotencyStore (same conditions as the SQL in IdempotencyDbStore).
 */
class IdempotencyTest extends TestCase {

	const LEASE = 120;
	const HASH_A = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
	const HASH_B = "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb";

	private FakeIdempotencyStore $store;

	protected function setUp(): void {
		$this->store = new FakeIdempotencyStore();
	}

	protected function tearDown(): void {
		TestConfig::Set();
	}

	// ------------------------------------------------------------ the header

	public function testNoHeaderIsNoIdempotency(): void {
		$this->assertNull(Idempotency::KeyFromRequest([]));
		$this->assertNull(Idempotency::KeyFromRequest(["HTTP_X_IDEMPOTENCY_KEY" => "abc"]));
	}

	public function testHeaderIsReadAndTrimmed(): void {
		$this->assertSame("upload-42", Idempotency::KeyFromRequest([Idempotency::SERVER_KEY => "upload-42"]));
		$this->assertSame("upload 42", Idempotency::KeyFromRequest([Idempotency::SERVER_KEY => " \tupload 42 "]));
		$this->assertSame("0196b1d3-5c2e-7a10-8f3b-6d9e2a4c7b15", Idempotency::ValidateKey("0196b1d3-5c2e-7a10-8f3b-6d9e2a4c7b15"));
		$this->assertSame(str_repeat("k", 255), Idempotency::ValidateKey(str_repeat("k", 255)));
		$this->assertSame("~!@#$%^&*()_+{}|:\"<>?`-=[]\\;',./", Idempotency::ValidateKey("~!@#$%^&*()_+{}|:\"<>?`-=[]\\;',./"));
	}

	public function testCaseIsKept(): void {
		// the column is ascii_bin: ABC and abc are two keys
		$this->assertSame("ABC", Idempotency::ValidateKey("ABC"));
	}

	public static function badKeys(): array {
		return [
			"empty" => ["", "empty"],
			"spaces only" => ["   ", "empty"],
			"256 characters" => [str_repeat("k", 256), "over 255"],
			"non-ASCII" => ["clé", "printable ASCII"],
			"control character" => ["a\x01b", "printable ASCII"],
			"newline" => ["a\nb", "printable ASCII"],
			"DEL" => ["a\x7Fb", "printable ASCII"],
			"not a string" => [["a"], "empty"],
		];
	}

	#[DataProvider("badKeys")]
	public function testBadKeyIs40011(mixed $raw, string $detail): void {
		$ex = $this->Refused(fn() => Idempotency::KeyFromRequest([Idempotency::SERVER_KEY => $raw]), 40011);
		$this->assertStringContainsString($detail, $ex->getMessage());
	}

	// ------------------------------------------------------------ what counts as the same request

	public function testHashIgnoresObjectKeyOrderAtEveryLevel(): void {
		$this->assertSame(
			Idempotency::RequestHash("POST files", ["a" => 1, "b" => ["y" => 2, "x" => 3]]),
			Idempotency::RequestHash("POST files", ["b" => ["x" => 3, "y" => 2], "a" => 1])
		);
	}

	public function testHashKeepsListOrderAndTypes(): void {
		$this->assertNotSame(Idempotency::RequestHash("r", ["t" => ["a", "b"]]), Idempotency::RequestHash("r", ["t" => ["b", "a"]]));
		$this->assertNotSame(Idempotency::RequestHash("r", ["v" => true]), Idempotency::RequestHash("r", ["v" => "1"]));
		$this->assertNotSame(Idempotency::RequestHash("r", ["v" => null]), Idempotency::RequestHash("r", ["v" => false]));
		$this->assertNotSame(Idempotency::RequestHash("r", ["t" => []]), Idempotency::RequestHash("r", ["t" => (object)[]]));
	}

	public function testHashIncludesTheRoute(): void {
		$this->assertNotSame(Idempotency::RequestHash("POST files", ["name" => "a"]), Idempotency::RequestHash("POST provision/keys", ["name" => "a"]));
	}

	public function testHashTakesInvalidUtf8(): void {
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', Idempotency::RequestHash("r", ["name" => "caf\xE9.jpg"]));
	}

	public function testUploadHashTakesTagsAsASet(): void {
		$this->assertSame(
			FileApi::UploadRequestHash(7, ["b", "a", "a"], true, null, "photo.jpg", self::HASH_A),
			FileApi::UploadRequestHash(7, ["a", "b"], true, null, "photo.jpg", self::HASH_A)
		);
	}

	public static function differentUploads(): array {
		// each one changes one thing from [7, ["a"], true, null, "photo.jpg", HASH_A]
		return [
			"folder" => [8, ["a"], true, null, "photo.jpg", self::HASH_A],
			"tags" => [7, ["a", "b"], true, null, "photo.jpg", self::HASH_A],
			"verbatim not sent" => [7, ["a"], null, null, "photo.jpg", self::HASH_A],
			"verbatim false" => [7, ["a"], false, null, "photo.jpg", self::HASH_A],
			"no_convert sent" => [7, ["a"], true, false, "photo.jpg", self::HASH_A],
			"name" => [7, ["a"], true, null, "photo.png", self::HASH_A],
			"bytes" => [7, ["a"], true, null, "photo.jpg", self::HASH_B],
		];
	}

	#[DataProvider("differentUploads")]
	public function testUploadHashChangesWithAnyPart(int $folder, array $tags, ?bool $verbatim, ?bool $noConvert, string $name, string $sha256): void {
		$this->assertNotSame(
			FileApi::UploadRequestHash(7, ["a"], true, null, "photo.jpg", self::HASH_A),
			FileApi::UploadRequestHash($folder, $tags, $verbatim, $noConvert, $name, $sha256)
		);
	}

	public function testSourceUrlHashTakesTagsAsASet(): void {
		$this->assertSame(
			FileApi::SourceUrlRequestHash(7, ["b", "a", "a"], true, null, null, "https://example.com/a.jpg"),
			FileApi::SourceUrlRequestHash(7, ["a", "b"], true, null, null, "https://example.com/a.jpg")
		);
	}

	public static function differentSourceUrlUploads(): array {
		// each one changes one thing from [7, ["a"], true, null, null, "https://example.com/a.jpg"]
		return [
			"folder" => [8, ["a"], true, null, null, "https://example.com/a.jpg"],
			"tags" => [7, ["a", "b"], true, null, null, "https://example.com/a.jpg"],
			"verbatim not sent" => [7, ["a"], null, null, null, "https://example.com/a.jpg"],
			"no_convert sent" => [7, ["a"], true, true, null, "https://example.com/a.jpg"],
			"name sent" => [7, ["a"], true, null, "a.jpg", "https://example.com/a.jpg"],
			"url" => [7, ["a"], true, null, null, "https://example.com/b.jpg"],
			"query" => [7, ["a"], true, null, null, "https://example.com/a.jpg?v=2"],
		];
	}

	#[DataProvider("differentSourceUrlUploads")]
	public function testSourceUrlHashChangesWithAnyPart(int $folder, array $tags, ?bool $verbatim, ?bool $noConvert, ?string $name, string $url): void {
		$this->assertNotSame(
			FileApi::SourceUrlRequestHash(7, ["a"], true, null, null, "https://example.com/a.jpg"),
			FileApi::SourceUrlRequestHash($folder, $tags, $verbatim, $noConvert, $name, $url)
		);
	}

	/** One Idempotency-Key reused for a multipart upload and a URL upload is a 4221, never a replay. */
	public function testSourceUrlHashIsNeverAnUploadHash(): void {
		$this->assertNotSame(
			FileApi::UploadRequestHash(7, ["a"], true, null, "a.jpg", self::HASH_A),
			FileApi::SourceUrlRequestHash(7, ["a"], true, null, "a.jpg", "https://example.com/a.jpg")
		);
	}

	/** The hash takes the URL as SourceUrl rebuilt it: `HTTPS://Example.com:443/a.jpg#x` is the same request. */
	public function testSourceUrlHashTakesTheCanonicalUrl(): void {
		$canonical = fn(string $url) => SourceUrl::Parse($url, [443])->ToString();
		$this->assertSame(
			FileApi::SourceUrlRequestHash(7, [], null, null, null, $canonical("https://example.com/a.jpg")),
			FileApi::SourceUrlRequestHash(7, [], null, null, null, $canonical("HTTPS://Example.com:443/a.jpg#x"))
		);
	}

	public function testProvisioningHashIsTheName(): void {
		$this->assertSame(ProvisioningApi::CreateRequestHash("workspace-42"), ProvisioningApi::CreateRequestHash("workspace-42"));
		$this->assertNotSame(ProvisioningApi::CreateRequestHash("workspace-42"), ProvisioningApi::CreateRequestHash("workspace-43"));
	}

	// ------------------------------------------------------------ lease and retention

	public static function leases(): array {
		return [
			"PHP's default 30 s" => ["30", 120],
			"deploy.md's 300 s" => ["300", 360],
			"no limit" => ["0", 360],
			"unreadable" => ["", 360],
			"no setting" => [false, 360],
			"long" => ["600", 660],
		];
	}

	#[DataProvider("leases")]
	public function testLease(string|false $maxExecutionTime, int $expected): void {
		$this->assertSame($expected, Idempotency::LeaseSeconds($maxExecutionTime));
	}

	/** A source_url upload waits on the network, which max_execution_time doesn't count on Linux: the fetch's timeout is added. */
	public function testLeaseOfAUrlUpload(): void {
		$this->assertSame(180, Idempotency::LeaseSeconds("30", 90));
		$this->assertSame(450, Idempotency::LeaseSeconds("300", 90));
		$this->assertSame(450, Idempotency::LeaseSeconds("0", 90));
		$this->assertSame(120, Idempotency::LeaseSeconds("30", 0));
		$this->assertSame(120, Idempotency::LeaseSeconds("30", -5), "a negative timeout adds nothing");
	}

	public function testRetentionDefaultsTo24Hours(): void {
		TestConfig::Set();
		$this->assertSame(24, Idempotency::RetentionHours());
		TestConfig::Set(["idempotency_retention_h" => "72"]);
		$this->assertSame(72, Idempotency::RetentionHours());
	}

	public static function badRetentions(): array {
		return ["below the floor" => ["23"], "zero" => ["0"], "not a number" => ["a day"], "negative" => ["-24"]];
	}

	#[DataProvider("badRetentions")]
	public function testRetentionBelow24HoursIs5004(string $value): void {
		TestConfig::Set(["idempotency_retention_h" => $value]);
		$ex = $this->Refused(fn() => Idempotency::RetentionHours(), 5004);
		$this->assertStringContainsString("idempotency_retention_h", $ex->getMessage());
	}

	// ------------------------------------------------------------ the state machine

	public function testANewKeyIsOwned(): void {
		$claim = $this->Begin("k1");
		$this->assertInstanceOf(IdempotencyClaim::class, $claim);
		$this->assertSame(1, $claim->attempt);
	}

	public function testARetryAfterSuccessReplaysTheResource(): void {
		$this->Succeed($this->Begin("k1"), 501);
		$replay = $this->Begin("k1");
		$this->assertInstanceOf(IdempotencyRecord::class, $replay);
		$this->assertSame(Idempotency::RESOURCE_FILE, $replay->resourceType);
		$this->assertSame(501, $replay->resourceId);
		$this->assertSame(Idempotency::STATUS_DONE, $replay->status);
		// and again: replays don't use the record up
		$this->assertSame(501, $this->Begin("k1")->resourceId);
	}

	public function testADifferentRequestIs4221WhateverTheState(): void {
		$running = $this->Begin("running");
		$this->Refused(fn() => $this->Begin("running", self::HASH_B), 4221);
		$this->Succeed($this->Begin("done"), 501);
		$this->Refused(fn() => $this->Begin("done", self::HASH_B), 4221);
		$this->store->now += 3600;
		$this->Refused(fn() => $this->Begin("running", self::HASH_B), 4221); // lease over: still not a takeover
		$this->assertSame(1, $this->store->rows[$running->id]["attempt"]);
	}

	public function testARetryWhileTheFirstRunsIs4091(): void {
		$this->Begin("k1");
		$this->store->now += self::LEASE; // the last second of the lease
		$this->Refused(fn() => $this->Begin("k1"), 4091);
	}

	public function testACrashAfterTheResourceReplays(): void {
		// recorded in the resource's transaction, then the request died: no Complete()
		$claim = $this->Begin("k1");
		$claim->RecordResource(Idempotency::RESOURCE_FILE, 501);
		$replay = $this->Begin("k1");
		$this->assertInstanceOf(IdempotencyRecord::class, $replay);
		$this->assertSame(Idempotency::STATUS_IN_PROGRESS, $replay->status);
		$this->assertSame(501, $replay->resourceId);
		$this->store->now += 3600; // and after the lease: still a replay, never a takeover
		$this->assertSame(501, $this->Begin("k1")->resourceId);
	}

	public function testACrashBeforeTheResourceIsTakenOverAfterTheLease(): void {
		$first = $this->Begin("k1");
		$this->store->now += self::LEASE + 1;
		$retry = $this->Begin("k1");
		$this->assertInstanceOf(IdempotencyClaim::class, $retry);
		$this->assertSame($first->id, $retry->id);
		$this->assertSame(2, $retry->attempt);
		$this->Refused(fn() => $this->Begin("k1"), 4091); // the retry holds a new lease
	}

	public function testARequestThatOutlivedItsLeaseCantAddASecondResource(): void {
		$slow = $this->Begin("k1");
		$this->store->now += self::LEASE + 1;
		$retry = $this->Begin("k1");
		$retry->RecordResource(Idempotency::RESOURCE_FILE, 502);
		// the slow one finally gets to its insert: refused, so its transaction rolls back
		$this->Refused(fn() => $slow->RecordResource(Idempotency::RESOURCE_FILE, 501), 4091);
		$slow->Release(); // what Run() does after the throw: must not touch the retry's row
		$retry->Complete();
		$this->assertSame(502, $this->Begin("k1")->resourceId);
	}

	public function testAStaleReleaseLeavesTheNewOwnersRow(): void {
		$slow = $this->Begin("k1");
		$this->store->now += self::LEASE + 1;
		$retry = $this->Begin("k1");
		$slow->Release();
		$this->assertArrayHasKey($retry->id, $this->store->rows);
		$this->Refused(fn() => $this->Begin("k1"), 4091);
	}

	public function testAFailureFreesTheKey(): void {
		$this->Begin("k1")->Release();
		$this->assertSame([], $this->store->rows);
		$again = $this->Begin("k1"); // errors aren't stored: the retry runs again
		$this->assertInstanceOf(IdempotencyClaim::class, $again);
		$this->assertSame(1, $again->attempt);
	}

	public function testAFailureAfterTheResourceKeepsIt(): void {
		// something threw after the resource's transaction committed: the retry replays it
		$claim = $this->Begin("k1");
		$claim->RecordResource(Idempotency::RESOURCE_KEY, 9);
		$claim->Release();
		$this->assertSame(9, $this->Begin("k1")->resourceId);
	}

	public function testCompletingWithoutAResourceReleases(): void {
		$this->Begin("k1")->Complete();
		$this->assertSame([], $this->store->rows);
	}

	public function testAResourceIsRecordedOnce(): void {
		$claim = $this->Begin("k1");
		$claim->RecordResource(Idempotency::RESOURCE_FILE, 501);
		$this->Refused(fn() => $claim->RecordResource(Idempotency::RESOURCE_FILE, 502), 4091);
		$this->assertSame(501, $this->store->rows[$claim->id]["resource_id"]);
	}

	public function testKeysAreScopedToTheCredential(): void {
		$this->Succeed($this->Begin("k1"), 501);
		$this->assertInstanceOf(IdempotencyClaim::class, $this->Begin("k1", self::HASH_B, credentialId: 2));
		$this->assertInstanceOf(IdempotencyClaim::class,
			Idempotency::Begin($this->store, Idempotency::CREDENTIAL_PROVISIONING, 1, "k1", self::HASH_B, self::LEASE));
	}

	public function testARowReleasedBetweenInsertAndReadIsInsertedAgain(): void {
		$first = $this->Begin("k1");
		$this->store->beforeFind = fn(FakeIdempotencyStore $store) => $first->Release();
		$claim = $this->Begin("k1");
		$this->assertInstanceOf(IdempotencyClaim::class, $claim);
		$this->assertNotSame($first->id, $claim->id);
	}

	public function testALostTakeoverRaceIs4091(): void {
		$this->Begin("k1");
		$this->store->now += self::LEASE + 1;
		// another retry takes it over between this one's read and its own takeover
		$this->store->beforeTakeOver = function() {
			$this->assertSame(2, $this->Begin("k1")->attempt);
		};
		$this->Refused(fn() => $this->Begin("k1"), 4091);
	}

	// ------------------------------------------------------------ Run()

	public function testRunWithoutAClaimJustRuns(): void {
		$this->assertSame("ok", Idempotency::Run(null, fn() => "ok"));
	}

	public function testRunCompletesOnSuccess(): void {
		$claim = $this->Begin("k1");
		$result = Idempotency::Run($claim, function() use ($claim) {
			$claim->RecordResource(Idempotency::RESOURCE_FILE, 501);
			return ["uuid" => "x"];
		});
		$this->assertSame(["uuid" => "x"], $result);
		$this->assertTrue($claim->IsFinished());
		$this->assertSame(Idempotency::STATUS_DONE, $this->store->rows[$claim->id]["status"]);
	}

	public function testRunReleasesAndRethrowsOnFailure(): void {
		$claim = $this->Begin("k1");
		try {
			Idempotency::Run($claim, fn() => throw new \RuntimeException("storage down"));
			$this->fail("expected the exception");
		} catch(\RuntimeException $ex) {
			$this->assertSame("storage down", $ex->getMessage());
		}
		$this->assertTrue($claim->IsFinished());
		$this->assertSame([], $this->store->rows);
	}

	public function testRecorderTakesTheModelsId(): void {
		$claim = $this->Begin("k1");
		$model = new \stdClass();
		$model->id = "501"; // as a non-strict model would have it
		($claim->Recorder(Idempotency::RESOURCE_FILE))($model);
		$this->assertSame(501, $this->store->rows[$claim->id]["resource_id"]);
	}

	// ------------------------------------------------------------ helpers

	private function Begin(string $key, string $hash = self::HASH_A, int $credentialId = 1): IdempotencyClaim|IdempotencyRecord {
		return Idempotency::Begin($this->store, Idempotency::CREDENTIAL_KEY, $credentialId, $key, $hash, self::LEASE);
	}

	private function Succeed(IdempotencyClaim $claim, int $fileId): void {
		Idempotency::Run($claim, fn() => $claim->RecordResource(Idempotency::RESOURCE_FILE, $fileId));
	}

	private function Refused(callable $fn, int $code): MagratheaApiException {
		try {
			$fn();
		} catch(MagratheaApiException $ex) {
			$this->assertSame($code, $ex->getCode(), $ex->getMessage());
			return $ex;
		}
		$this->fail("expected ".$code);
	}

}
