<?php

namespace MagratheaExplorer\Tests\Shared;

use Magrathea\Exceptions\MagratheaApiException;
use MagratheaExplorer\ApiTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Tier 2 §3 of the 1.5.0 plan: every datetime in and out of the API is ISO 8601 UTC. */
class ApiTimeTest extends TestCase {

	private string $zone;

	protected function setUp(): void {
		$this->zone = date_default_timezone_get();
	}

	protected function tearDown(): void {
		date_default_timezone_set($this->zone);
	}

	// --- Utc(): DB local time -> API ---

	public function testSaoPauloHasNoDst(): void {
		date_default_timezone_set("America/Sao_Paulo");
		$this->assertSame("2026-11-17T06:22:10Z", ApiTime::Utc("2026-11-17 03:22:10"));
		$this->assertSame("2026-07-01T15:00:00Z", ApiTime::Utc("2026-07-01 12:00:00"));
	}

	public function testBerlinFollowsDst(): void {
		date_default_timezone_set("Europe/Berlin");
		$this->assertSame("2026-01-15T11:00:00Z", ApiTime::Utc("2026-01-15 12:00:00")); // CET, +01:00
		$this->assertSame("2026-07-15T10:00:00Z", ApiTime::Utc("2026-07-15 12:00:00")); // CEST, +02:00
	}

	public function testCrossesTheDateLine(): void {
		date_default_timezone_set("America/Sao_Paulo");
		$this->assertSame("2027-01-01T02:30:00Z", ApiTime::Utc("2026-12-31 23:30:00"));
	}

	public function testUtcZoneIsUnchanged(): void {
		date_default_timezone_set("UTC");
		$this->assertSame("2026-11-17T03:22:10Z", ApiTime::Utc("2026-11-17 03:22:10"));
	}

	/** The hour the clocks go back happens twice: either reading is right, the docs say so. */
	public function testAmbiguousHourIsOneOfTheTwo(): void {
		date_default_timezone_set("Europe/Berlin");
		$this->assertContains(ApiTime::Utc("2026-10-25 02:30:00"), ["2026-10-25T00:30:00Z", "2026-10-25T01:30:00Z"]);
	}

	public function testNullStaysNull(): void {
		$this->assertNull(ApiTime::Utc(null));
	}

	public static function notDatetimes(): array {
		return [
			"empty" => [""], "zero datetime" => ["0000-00-00 00:00:00"], "zero date" => ["0000-00-00"],
			"garbage" => ["yesterday"], "date only" => ["2026-11-17"], "already ISO" => ["2026-11-17T03:22:10Z"],
			"Feb 30" => ["2026-02-30 10:00:00"], "hour 25" => ["2026-11-17 25:00:00"],
		];
	}

	#[DataProvider("notDatetimes")]
	public function testWhatIsNotADbDatetimeIsNull(string $value): void {
		$this->assertNull(ApiTime::Utc($value));
	}

	// --- FromApi(): API -> DB local time ---

	public static function accepted(): array {
		// [input, local time in America/Sao_Paulo (-03:00)]
		return [
			"Z" => ["2026-11-18T12:00:00Z", "2026-11-18 09:00:00"],
			"lowercase t and z" => ["2026-11-18t12:00:00z", "2026-11-18 09:00:00"],
			"fraction (toISOString)" => ["2026-11-18T12:00:00.000Z", "2026-11-18 09:00:00"],
			"long fraction" => ["2026-11-18T12:00:00.123456789Z", "2026-11-18 09:00:00"],
			"no seconds" => ["2026-11-18T12:00Z", "2026-11-18 09:00:00"],
			"positive offset" => ["2026-11-18T12:00:00+05:30", "2026-11-18 03:30:00"],
			"negative offset" => ["2026-11-18T12:00:00-03:00", "2026-11-18 12:00:00"],
			"offset without colon" => ["2026-11-18T12:00:00+0200", "2026-11-18 07:00:00"],
			"-00:00" => ["2026-11-18T12:00:00-00:00", "2026-11-18 09:00:00"],
			"plain date = midnight UTC" => ["2026-11-18", "2026-11-17 21:00:00"],
			"leap day" => ["2028-02-29T00:00:00Z", "2028-02-28 21:00:00"],
			"surrounding whitespace" => [" 2026-11-18T12:00:00Z ", "2026-11-18 09:00:00"],
		];
	}

	#[DataProvider("accepted")]
	public function testFromApi(string $input, string $local): void {
		date_default_timezone_set("America/Sao_Paulo");
		$this->assertSame($local, ApiTime::FromApi($input, "expiration"));
	}

	public function testFromApiInBerlinFollowsDst(): void {
		date_default_timezone_set("Europe/Berlin");
		$this->assertSame("2026-01-15 13:00:00", ApiTime::FromApi("2026-01-15T12:00:00Z", "expiration"));
		$this->assertSame("2026-07-15 14:00:00", ApiTime::FromApi("2026-07-15T12:00:00Z", "expiration"));
	}

	public function testRoundTrip(): void {
		date_default_timezone_set("Europe/Berlin");
		foreach(["2026-01-15T12:00:00Z", "2026-07-15T23:59:59Z", "2026-11-18T00:00:00Z"] as $utc) {
			$this->assertSame($utc, ApiTime::Utc(ApiTime::FromApi($utc, "expiration")));
		}
	}

	public static function refused(): array {
		return [
			"empty" => [""], "garbage" => ["tomorrow"], "unix time" => ["1795000000"],
			"no offset" => ["2026-11-18T12:00:00"], "DB format" => ["2026-11-18 12:00:00"],
			"space and Z" => ["2026-11-18 12:00:00Z"], "Feb 30" => ["2026-02-30"],
			"Feb 29, not a leap year" => ["2026-02-29T00:00:00Z"], "month 13" => ["2026-13-01"],
			"hour 24" => ["2026-11-18T24:00:00Z"], "minute 60" => ["2026-11-18T12:60:00Z"],
			"leap second" => ["2026-12-31T23:59:60Z"], "offset hour 24" => ["2026-11-18T12:00:00+24:00"],
			"offset minute 60" => ["2026-11-18T12:00:00+05:60"], "short year" => ["26-11-18"],
			"named zone" => ["2026-11-18T12:00:00 America/Sao_Paulo"], "week date" => ["2026-W47-3"],
			"before MySQL's range" => ["0999-12-31T12:00:00Z"],
			"after MySQL's range, once local" => ["9999-12-31T23:30:00-05:00"],
			"trailing junk" => ["2026-11-18T12:00:00Zjunk"], "newline inside" => ["2026-11-18\nT12:00:00Z"],
		];
	}

	#[DataProvider("refused")]
	public function testFromApiRefuses(string $input): void {
		date_default_timezone_set("America/Sao_Paulo");
		try {
			ApiTime::FromApi($input, "expiration");
			$this->fail("expected 40013 for ".json_encode($input));
		} catch(MagratheaApiException $ex) {
			$this->assertSame(40013, $ex->getCode());
			$this->assertStringStartsWith("Invalid field value: expiration (", $ex->getMessage());
		}
	}

}
