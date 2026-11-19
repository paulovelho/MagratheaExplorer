<?php

namespace MagratheaExplorer\Processing;

use Magrathea\ConfigApp;
use MagratheaExplorer\ExplorerConfig;

/**
 * The renditions a key's images get: a list of specs, each one a box and a format. A key's
 * `rendition_profile` (JSON) holds its own; null = Default(). Every profile goes through
 * Validate() before it's stored, and again when it's read (a column edited by SQL is still
 * checked), so the processor only ever sees well-formed specs.
 *
 * A spec: `name` (`[a-z0-9_-]{1,32}`, not all digits, unique), `width` and `height` (1-2048: a
 * bounding box, never upscaled), `fit` (`contain` keeps the aspect ratio, `cover` crops to fill,
 * centered), `format` (`webp`, `png`, `jpg`, or `legacy` = png for a png original, jpg
 * otherwise), `quality` (1-100, default 85) and `background` (`#rrggbb`, what jpg flattens
 * transparency onto, default white; jpg and legacy only).
 *
 * Renditions don't count toward the key's usage (plan §7.9): the 2048 px cap and the 6 specs
 * bound what they can cost.
 */
final class RenditionProfile {

	const MAX_SPECS = 6;
	const MAX_SIDE = 2048;
	const DEFAULT_QUALITY = 85;
	const DEFAULT_BACKGROUND = "#ffffff";
	/** ConfigApp `thumbnail_size` when it's empty or unreadable, as before 1.5.0. */
	const DEFAULT_THUMBNAIL_SIZE = 200;
	/** The rendition `thumbnail_url` points at. */
	const THUMB = "thumb";

	const FITS = ["contain", "cover"];
	const FORMATS = ["webp", "png", "jpg", "legacy"];
	const KEYS = ["name", "width", "height", "fit", "format", "quality", "background"];

	/** The admin's preset button: a small thumbnail and a large preview, both whole images in webp. */
	const PRESET_THUMB_PREVIEW = [
		["name" => "thumb", "width" => 300, "height" => 300, "fit" => "contain", "format" => "webp", "quality" => 85],
		["name" => "preview", "width" => 1200, "height" => 1200, "fit" => "contain", "format" => "webp", "quality" => 85],
	];

	/**
	 * The profile of a key without its own: one `thumb`, the same name, size, fit and format as
	 * the 1.4.x thumbnail (a ConfigApp `thumbnail_size` square, cropped to fill, png for a png and
	 * jpg otherwise). The pixels differ: centered crop where GD cropped portraits from the top,
	 * and jpg flattened onto white where GD left transparency black.
	 */
	public static function Default(): array {
		$size = self::ThumbnailSize();
		return [[
			"name" => self::THUMB,
			"width" => $size,
			"height" => $size,
			"fit" => "cover",
			"format" => "legacy",
			"quality" => self::DEFAULT_QUALITY,
			"background" => self::DEFAULT_BACKGROUND,
		]];
	}

	/** ConfigApp `thumbnail_size`, kept within 1-2048. Empty or unreadable = 200, as before 1.5.0. */
	public static function ThumbnailSize(): int {
		$raw = ConfigApp::Instance()->Get("thumbnail_size");
		$size = empty($raw) ? null : ExplorerConfig::ParseSize($raw);
		if($size === null || $size < 1) return self::DEFAULT_THUMBNAIL_SIZE;
		return min($size, self::MAX_SIDE);
	}

	/**
	 * A profile from its JSON text (the key's column, the admin form).
	 * @return array the normalized specs (see Validate())
	 * @throws \InvalidArgumentException what's wrong, for a human
	 */
	public static function Parse(string $json): array {
		try {
			$decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
		} catch(\JsonException $ex) {
			throw new \InvalidArgumentException("The rendition profile isn't valid JSON (".$ex->getMessage().").");
		}
		return self::Validate($decoded);
	}

	/**
	 * Checks a decoded profile and returns it normalized: every spec has the keys in KEYS order,
	 * `quality` filled in, and `background` only on jpg and legacy (filled in there). An empty
	 * list is valid: no renditions.
	 * @throws \InvalidArgumentException the first problem found
	 */
	public static function Validate(mixed $profile): array {
		if(!is_array($profile) || !array_is_list($profile)) {
			throw new \InvalidArgumentException("The rendition profile must be a JSON list of specs: [{\"name\": \"thumb\", ...}].");
		}
		if(count($profile) > self::MAX_SPECS) {
			throw new \InvalidArgumentException("A rendition profile has at most ".self::MAX_SPECS." specs, this one has ".count($profile).".");
		}
		$specs = [];
		foreach($profile as $i => $spec) {
			$label = "Spec ".($i + 1);
			if(!is_array($spec) || ($spec !== [] && array_is_list($spec))) {
				throw new \InvalidArgumentException($label." must be an object.");
			}
			$unknown = array_diff(array_map("strval", array_keys($spec)), self::KEYS);
			if($unknown !== []) {
				throw new \InvalidArgumentException($label.": unknown field ".self::Quote(reset($unknown)).". The fields are ".implode(", ", self::KEYS).".");
			}

			$name = $spec["name"] ?? null;
			if(!is_string($name) || preg_match('/^[a-z0-9_-]{1,32}$/', $name) !== 1) {
				throw new \InvalidArgumentException($label.": `name` must be 1 to 32 characters of a-z, 0-9, _ and -.");
			}
			if(ctype_digit($name)) {
				// PHP turns an all-digit array key into an int: a map keyed by name would
				// json_encode as a list.
				throw new \InvalidArgumentException($label.": `name` can't be only digits (".self::Quote($name)."): use something like ".self::Quote("w".$name).".");
			}
			$label .= " (".self::Quote($name).")";
			if(isset($specs[$name])) {
				throw new \InvalidArgumentException($label.": another spec already has this name.");
			}

			foreach(["width", "height"] as $side) {
				$value = $spec[$side] ?? null;
				if(!is_int($value) || $value < 1 || $value > self::MAX_SIDE) {
					throw new \InvalidArgumentException($label.": `".$side."` must be a whole number from 1 to ".self::MAX_SIDE.".");
				}
			}
			$fit = $spec["fit"] ?? null;
			if(!in_array($fit, self::FITS, true)) {
				throw new \InvalidArgumentException($label.": `fit` must be ".self::OneOf(self::FITS).".");
			}
			$format = $spec["format"] ?? null;
			if(!in_array($format, self::FORMATS, true)) {
				throw new \InvalidArgumentException($label.": `format` must be ".self::OneOf(self::FORMATS).".");
			}
			$quality = $spec["quality"] ?? self::DEFAULT_QUALITY;
			if(!is_int($quality) || $quality < 1 || $quality > 100) {
				throw new \InvalidArgumentException($label.": `quality` must be a whole number from 1 to 100.");
			}
			$flattens = $format === "jpg" || $format === "legacy";
			if(array_key_exists("background", $spec)) {
				if(!$flattens) {
					throw new \InvalidArgumentException($label.": `background` only applies to jpg (and legacy, which is jpg for anything but a png): ".$format." keeps transparency.");
				}
				if(!is_string($spec["background"]) || preg_match('/^#[0-9a-fA-F]{6}$/', $spec["background"]) !== 1) {
					throw new \InvalidArgumentException($label.": `background` must be a color as #rrggbb.");
				}
			}

			$normalized = [
				"name" => $name,
				"width" => $spec["width"],
				"height" => $spec["height"],
				"fit" => $fit,
				"format" => $format,
				"quality" => $quality,
			];
			if($flattens) $normalized["background"] = strtolower($spec["background"] ?? self::DEFAULT_BACKGROUND);
			$specs[$name] = $normalized;
		}
		return array_values($specs);
	}

	/** The stored form: compact JSON. */
	public static function Encode(array $specs): string {
		return json_encode(array_values($specs), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

	/** For the admin's textarea: one spec per line. */
	public static function Pretty(array $specs): string {
		if($specs === []) return "[]";
		$lines = array_map(fn($spec) => "  ".json_encode($spec, JSON_UNESCAPED_SLASHES), array_values($specs));
		return "[\n".implode(",\n", $lines)."\n]";
	}

	/** One line per spec, for lists: `thumb 300x300 contain webp`. */
	public static function Describe(array $specs): string {
		if($specs === []) return "none";
		return implode(", ", array_map(fn($s) => $s["name"]." ".$s["width"]."x".$s["height"]." ".$s["fit"]." ".$s["format"], $specs));
	}

	private static function Quote(string $value): string {
		return "\"".$value."\"";
	}

	private static function OneOf(array $values): string {
		$quoted = array_map(fn($v) => "`".$v."`", $values);
		$last = array_pop($quoted);
		return implode(", ", $quoted)." or ".$last;
	}

}
