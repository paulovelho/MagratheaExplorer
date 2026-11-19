<?php

namespace MagratheaExplorer;

/**
 * Strict boolean parsing for request flags (multipart fields, form fields, JSON bodies).
 * Every API flag goes through it: PHP truthiness (`!empty()`) turned `no_convert=false`
 * into true. Unknown values are refused instead of guessed.
 */
class RequestBool {

	private const TRUE_STRINGS = ["1", "true", "yes", "on"];
	private const FALSE_STRINGS = ["0", "false", "no", "off", ""];

	/**
	 * null = the field is absent (or JSON null): the caller applies its own default.
	 * Anything ParseValue() can't read throws 4009 (HTTP 400).
	 */
	public static function Parse(array $input, string $field): ?bool {
		if(!array_key_exists($field, $input) || $input[$field] === null) return null;
		$value = self::ParseValue($input[$field]);
		if($value === null) {
			ErrorCodes::Instance()->ThrowException(4009, null, $field);
		}
		return $value;
	}

	/**
	 * The parsing alone, for callers with their own error (magrathea.conf, see ExplorerConfig).
	 * Accepts PHP bools (JSON bodies), the ints 1/0, and the strings above, trimmed and
	 * case-insensitive. null = not a boolean.
	 */
	public static function ParseValue(mixed $value): ?bool {
		if(is_bool($value)) return $value;
		if($value === 1) return true;
		if($value === 0) return false;
		if(!is_string($value)) return null;
		$normalized = strtolower(trim($value));
		if(in_array($normalized, self::TRUE_STRINGS, true)) return true;
		if(in_array($normalized, self::FALSE_STRINGS, true)) return false;
		return null;
	}

}
