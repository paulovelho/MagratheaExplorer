<?php

namespace MagratheaExplorer;

/**
 * Reading and parsing a request body (Tier 2 §2.1 of the 1.5.0 plan), for
 * ExplorerApiControl::GetPhpInput().
 *
 * MagratheaPHP 3.0.0's GetPhpInput() takes a body as JSON only when the Content-Type *ends in*
 * "json", so `application/json; charset=utf-8` went through parse_str() and every field read as
 * missing (a 4001). It also returned whatever json_decode() made of a bad body: null, a scalar,
 * a list. Once a framework release parses the media type, MediaType()/IsJson() can go; the
 * strict JSON and the size cap are Explorer's own, and stay.
 */
class RequestBody {

	/** JSON and form bodies here are a few hundred bytes. The cap keeps a huge one from becoming one PHP string. */
	const MAX_BYTES = 1048576;

	/** The media type, lowercase, without its parameters: "Application/JSON; charset=utf-8" → "application/json". "" when absent. */
	public static function MediaType(?string $contentType): string {
		if($contentType === null) return "";
		return strtolower(trim(explode(";", $contentType, 2)[0]));
	}

	/** `application/json` or any `+json` type (`application/merge-patch+json`), whatever its parameters and case. */
	public static function IsJson(?string $contentType): bool {
		$type = self::MediaType($contentType);
		return $type === "application/json" || str_ends_with($type, "+json");
	}

	public static function IsMultipart(?string $contentType): bool {
		return self::MediaType($contentType) === "multipart/form-data";
	}

	/** A Content-Length header as an int. null when absent or not a plain number. */
	public static function ContentLength(int|string|null $contentLength): ?int {
		if(is_int($contentLength)) return $contentLength >= 0 ? $contentLength : null;
		if($contentLength === null || !ctype_digit($contentLength)) return null;
		return (int)$contentLength;
	}

	/**
	 * A multipart body above `post_max_size`. PHP drops the whole of it ($_FILES and $_POST come
	 * out empty, with no error code), so its Content-Length is the only sign left.
	 * @param int $postMaxBytes Key::IniSizeToBytes(ini_get("post_max_size")): PHP_INT_MAX for no limit
	 */
	public static function ExceedsPostMaxSize(?string $contentType, int|string|null $contentLength, int $postMaxBytes): bool {
		if(!self::IsMultipart($contentType)) return false;
		$length = self::ContentLength($contentLength);
		return $length !== null && $length > $postMaxBytes;
	}

	/**
	 * The body, read from $stream. Above MAX_BYTES it's refused with 413: by its Content-Length,
	 * before anything is read, and by what actually arrives (the header can be missing or wrong).
	 * @param resource $stream
	 */
	public static function Read($stream, int|string|null $contentLength): string {
		$length = self::ContentLength($contentLength);
		if($length !== null && $length > self::MAX_BYTES) self::RefuseTooLarge();
		$raw = stream_get_contents($stream, self::MAX_BYTES + 1);
		if($raw === false) return "";
		if(strlen($raw) > self::MAX_BYTES) self::RefuseTooLarge();
		return $raw;
	}

	/**
	 * The fields of a body. A JSON one (IsJson()) has to be an object: a syntax error, a scalar
	 * or a list is 40010. An empty JSON body has no fields, so the route's own check names the
	 * one that's missing. Any other content type is parsed as a form, as the framework does.
	 */
	public static function Parse(?string $contentType, string $raw): array {
		if(!self::IsJson($contentType)) {
			parse_str($raw, $fields);
			return $fields;
		}
		$trimmed = ltrim($raw, " \t\n\r"); // JSON's whitespace
		if($trimmed === "") return [];
		try {
			$fields = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
		} catch(\JsonException $ex) {
			ErrorCodes::Instance()->ThrowException(40010, null, $ex->getMessage());
		}
		// json_decode(…, true) makes an array of an object and of a list alike: `{}` and `[]` both give []
		if($trimmed[0] !== "{") {
			ErrorCodes::Instance()->ThrowException(40010, null, "expected a JSON object");
		}
		return $fields;
	}

	private static function RefuseTooLarge(): never {
		ErrorCodes::Instance()->ThrowException(413, null, "request body over 1 MB");
		throw new \LogicException("unreachable"); // ThrowException() always throws
	}

}
