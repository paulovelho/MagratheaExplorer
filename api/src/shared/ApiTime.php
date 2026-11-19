<?php

namespace MagratheaExplorer;

/**
 * Datetimes at the API's edge. The database holds local wall time (magrathea.conf `timezone`,
 * which Load() made PHP's zone, and which the framework syncs to MySQL's session zone, so
 * TIMESTAMP and DATETIME columns read back alike). Every datetime the API returns goes out
 * through Utc(); every datetime it takes comes in through FromApi(). The admin keeps local time.
 *
 * A zone with DST has one ambiguous local hour a year (the clocks go back) and one that doesn't
 * exist (they go forward). PHP resolves both its own way: documented, not engineered around.
 */
final class ApiTime {

	const API_FORMAT = 'Y-m-d\TH:i:s\Z';
	const DB_FORMAT = "Y-m-d H:i:s";

	/** "YYYY-MM-DD", or "YYYY-MM-DDTHH:MM[:SS[.fraction]]" + "Z" or "±HH:MM"/"±HHMM". Case-insensitive T and Z (RFC 3339). */
	private const API_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})(?:[Tt](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?([Zz]|([+-])(\d{2}):?(\d{2})))?$/';

	/** A DB datetime (local wall time in magrathea.conf `timezone`) as "2026-11-17T03:22:10Z". null stays null. */
	public static function Utc(?string $local): ?string {
		if($local === null) return null;
		$local = trim($local);
		// a zero date, or anything that isn't a DB datetime: shouldn't happen, and isn't a time
		if($local === "" || str_starts_with($local, "0000-00-00")) return null;
		$time = \DateTimeImmutable::createFromFormat("!".self::DB_FORMAT, $local, new \DateTimeZone(date_default_timezone_get()));
		if($time === false || self::HadWarnings()) return null;
		return $time->setTimezone(new \DateTimeZone("UTC"))->format(self::API_FORMAT);
	}

	/** A unix time (columns that store one, like `upload_tokens.expires_at`) as "2026-11-17T03:22:10Z". */
	public static function FromUnix(int $time): string {
		return gmdate(self::API_FORMAT, $time);
	}

	/**
	 * An API datetime: ISO 8601 with `Z` or an offset, or a plain date (= 00:00:00Z that day).
	 * Returns local "Y-m-d H:i:s" for storage. 40013 naming $field on anything else.
	 *
	 * A date-time without an offset is refused, not guessed: it would mean the server's zone,
	 * which the caller can't see. Fractional seconds (JavaScript's toISOString()) are accepted
	 * and dropped. The result has to fit a MySQL DATETIME (years 1000 to 9999).
	 */
	public static function FromApi(string $value, string $field): string {
		if(preg_match(self::API_PATTERN, trim($value), $m) !== 1) self::Refuse($field);
		[$year, $month, $day] = [(int)$m[1], (int)$m[2], (int)$m[3]];
		if(!checkdate($month, $day, $year)) self::Refuse($field);

		$hasTime = isset($m[4]) && $m[4] !== "";
		[$hour, $minute, $second] = $hasTime ? [(int)$m[4], (int)$m[5], (int)($m[6] ?? 0)] : [0, 0, 0];
		if($hour > 23 || $minute > 59 || $second > 59) self::Refuse($field);

		$offset = "+00:00";
		if($hasTime && strtoupper($m[7]) !== "Z") {
			if((int)$m[9] > 23 || (int)$m[10] > 59) self::Refuse($field);
			$offset = $m[8].$m[9].":".$m[10];
		}

		$time = \DateTimeImmutable::createFromFormat(
			"!Y-m-d H:i:s P",
			sprintf("%04d-%02d-%02d %02d:%02d:%02d %s", $year, $month, $day, $hour, $minute, $second, $offset)
		);
		if($time === false || self::HadWarnings()) self::Refuse($field);

		$local = $time->setTimezone(new \DateTimeZone(date_default_timezone_get()));
		$localYear = (int)$local->format("Y");
		if($localYear < 1000 || $localYear > 9999) self::Refuse($field);
		return $local->format(self::DB_FORMAT);
	}

	/** createFromFormat() accepts 2026-02-30 as 2026-03-02 and says so only here. false (8.2+) or zero counts = clean. */
	private static function HadWarnings(): bool {
		$errors = \DateTimeImmutable::getLastErrors();
		return $errors !== false && ($errors["warning_count"] > 0 || $errors["error_count"] > 0);
	}

	private static function Refuse(string $field): never {
		ErrorCodes::Instance()->ThrowException(40013, null,
			$field." (expected an ISO 8601 date-time with Z or an offset, like 2026-11-18T12:00:00Z, or a YYYY-MM-DD date)");
	}

}
