<?php

namespace MagratheaExplorer\File;

/**
 * The name a `source_url` upload gets when the request doesn't send `name` (Tier 2 plan §6.3):
 * 1. the response's Content-Disposition filename (`filename*` first, then `filename`);
 * 2. else the last path segment of the URL the file came from (after redirects, as a browser
 *    does), URL-decoded; then the one that was sent, if they differ;
 * 3. else `download`.
 *
 * Whatever the source: only the part after the last `/` or `\`, no control characters, trimmed,
 * at most 255 characters (the column), the extension kept when it's cut. The upstream's
 * Content-Type is ignored, as a client's is: the type comes from sniffing.
 */
final class RemoteFileName {

	const MAX_LENGTH = 255;
	const FALLBACK = "download";
	/** An extension longer than this isn't kept whole when a name is cut. */
	private const MAX_KEPT_EXTENSION = 16;

	public static function Choose(?string $contentDisposition, SourceUrl ...$urls): string {
		if($contentDisposition !== null) {
			$name = self::FromContentDisposition($contentDisposition);
			if($name !== null) return $name;
		}
		foreach($urls as $url) {
			$name = self::FromUrl($url);
			if($name !== null) return $name;
		}
		return self::FALLBACK;
	}

	/** The filename a Content-Disposition value carries (RFC 6266), cleaned. null = none usable. */
	public static function FromContentDisposition(string $header): ?string {
		$params = self::DispositionParams($header);
		if(isset($params["filename*"])) {
			$name = self::DecodeExtValue($params["filename*"]);
			if($name !== null && ($name = self::Clean($name)) !== null) return $name;
		}
		if(isset($params["filename"])) {
			$name = $params["filename"];
			// RFC 6266 says ISO-8859-1; most servers send UTF-8. Valid UTF-8 is taken as UTF-8.
			if(!mb_check_encoding($name, "UTF-8")) $name = mb_convert_encoding($name, "UTF-8", "ISO-8859-1");
			return self::Clean($name);
		}
		return null;
	}

	/** The URL's last path segment, decoded and cleaned. null when it's empty or not UTF-8. */
	public static function FromUrl(SourceUrl $url): ?string {
		$segment = rawurldecode($url->LastSegment());
		if(!mb_check_encoding($segment, "UTF-8")) return null;
		return self::Clean($segment);
	}

	/** The last path component, no control characters, trimmed, cut to MAX_LENGTH. null when nothing's left (or only `.`/`..`). */
	public static function Clean(string $name): ?string {
		if(!mb_check_encoding($name, "UTF-8")) return null;
		$name = str_replace("\\", "/", $name);
		$slash = strrpos($name, "/");
		if($slash !== false) $name = substr($name, $slash + 1);
		$name = trim((string)preg_replace('/\p{Cc}/u', "", $name));
		if($name === "" || $name === "." || $name === "..") return null;
		return self::Truncate($name);
	}

	/** At most MAX_LENGTH characters. A short extension survives the cut: `long…name.pdf`. */
	public static function Truncate(string $name): string {
		if(mb_strlen($name, "UTF-8") <= self::MAX_LENGTH) return $name;
		$dot = mb_strrpos($name, ".", 0, "UTF-8");
		$extension = $dot === false ? "" : mb_substr($name, $dot, null, "UTF-8"); // with its dot
		$extensionLength = mb_strlen($extension, "UTF-8");
		if($extensionLength < 2 || $extensionLength > self::MAX_KEPT_EXTENSION + 1 || preg_match('/\s/u', $extension) === 1) {
			return mb_substr($name, 0, self::MAX_LENGTH, "UTF-8");
		}
		return rtrim(mb_substr($name, 0, self::MAX_LENGTH - $extensionLength, "UTF-8")).$extension;
	}

	/**
	 * The parameters of a Content-Disposition value, names lowercased, quoted strings unquoted.
	 * Lenient, as browsers are: an unparseable parameter is skipped, not fatal.
	 * @return array<string, string>
	 */
	private static function DispositionParams(string $header): array {
		$params = [];
		$length = strlen($header);
		$i = strcspn($header, ";"); // the disposition type
		while($i < $length) {
			$i++; // the ";"
			$i += strspn($header, " \t", $i);
			$nameLength = strcspn($header, "=;", $i);
			$name = strtolower(trim(substr($header, $i, $nameLength)));
			$i += $nameLength;
			if($i >= $length || $header[$i] !== "=") continue; // a parameter without a value
			$i++;
			$i += strspn($header, " \t", $i);
			if($i < $length && $header[$i] === '"') {
				$value = "";
				for($i++; $i < $length && $header[$i] !== '"'; $i++) {
					// Only `\"` and `\\` are escapes in practice. Any other backslash is kept, so a
					// Windows path a server sends unescaped still reduces to its last component.
					if($header[$i] === "\\" && $i + 1 < $length && ($header[$i + 1] === '"' || $header[$i + 1] === "\\")) $i++;
					$value .= $header[$i];
				}
				$i++; // the closing quote
				$i += strcspn($header, ";", min($i, $length));
			} else {
				$valueLength = strcspn($header, ";", $i);
				$value = trim(substr($header, $i, $valueLength));
				$i += $valueLength;
			}
			if($name !== "" && !isset($params[$name])) $params[$name] = $value;
		}
		return $params;
	}

	/** RFC 8187's `charset'lang'percent-encoded`, UTF-8 or ISO-8859-1. null otherwise. */
	private static function DecodeExtValue(string $value): ?string {
		if(preg_match("/^([A-Za-z0-9!#$%&+\\-^_`{}~]+)'[^']*'(.*)$/s", $value, $m) !== 1) return null;
		$decoded = rawurldecode($m[2]);
		$charset = strtolower($m[1]);
		if($charset === "utf-8") return mb_check_encoding($decoded, "UTF-8") ? $decoded : null;
		if($charset === "iso-8859-1") return mb_convert_encoding($decoded, "UTF-8", "ISO-8859-1");
		return null;
	}

}
