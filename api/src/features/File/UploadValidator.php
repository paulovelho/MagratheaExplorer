<?php

namespace MagratheaExplorer\File;

use MagratheaExplorer\ErrorCodes;

/**
 * Content checks that refuse an upload before anything processes or stores it. Runs once per
 * upload, right after MimeSniffer, on every path (stripped or stored as-is), so a rule here
 * never depends on what happens to the file afterwards. One arm per type that needs a check.
 */
class UploadValidator {

	/** Largest internal DTD subset accepted. Illustrator's namespace/style entities stay far below. */
	const SVG_MAX_INTERNAL_SUBSET = 65536;
	/** Longest entity value accepted: bounds what each reference expands to in a client's parser. */
	const SVG_MAX_ENTITY_VALUE = 1024;

	/** @param array $detected MimeSniffer::Detect()'s result */
	public static function Validate(string $path, array $detected): void {
		match($detected["extension"]) {
			"svg" => self::Svg($path),
			default => null,
		};
	}

	/**
	 * Refuses (4151) an SVG whose DTD declares anything but plain entities, i.e. anything that
	 * could load a file or a URL, nest references or inject markup. Plain entities
	 * (`<!ENTITY ns_svg "http://www.w3.org/2000/svg">`, as old Illustrator exports write them) pass.
	 * Also refuses an SVG libxml can't read up to its root element: what isn't inspected isn't stored.
	 */
	private static function Svg(string $path): void {
		$doctype = self::ReadSvgDoctype($path);
		if($doctype === null) return;

		// $doctype is libxml's own serialization of what it parsed: UTF-8 and normalized, never
		// the raw bytes. A file in UTF-16, UTF-7 or EBCDIC can't hide a declaration from this.
		$literal = '(?:"[^"]*"|\'[^\']*\')';
		$pattern = '/^<!DOCTYPE\s+[^\s\[>]+(?:\s+(?:SYSTEM\s+'.$literal.'|PUBLIC\s+'.$literal.'\s+'.$literal.'))?\s*(?:\[(.*)\])?\s*>$/s';
		if(preg_match($pattern, $doctype, $m) !== 1) {
			self::RefuseSvg("unexpected DOCTYPE");
		}
		$subset = $m[1] ?? "";
		if($subset === "") return;
		if(strlen($subset) > self::SVG_MAX_INTERNAL_SUBSET) {
			self::RefuseSvg("DTD too large");
		}

		$subset = preg_replace('/<!--.*?-->/s', "", $subset);
		// No & (nested or character references), no % (parameter entities), no < (markup), and
		// no SYSTEM/PUBLIC/NDATA (a quoted value right after the name is the only form accepted).
		$max = self::SVG_MAX_ENTITY_VALUE;
		$entity = '<!ENTITY\s+[A-Za-z_:][A-Za-z0-9._:-]*\s+(?:"[^"&%<]{0,'.$max.'}"|\'[^\'&%<]{0,'.$max.'}\')\s*>';
		if($subset === null || preg_match('/^(?:\s*'.$entity.')*\s*$/', $subset) !== 1) {
			self::RefuseSvg("DTD declarations other than plain entities");
		}
	}

	/**
	 * Streams the prolog with libxml and returns the DOCTYPE as libxml serializes it, or null when
	 * there's none. Stops at the root element, so memory stays flat whatever the file's size.
	 */
	private static function ReadSvgDoctype(string $path): ?string {
		$previous = libxml_use_internal_errors(true);
		$reader = new \XMLReader();
		$opened = false;
		try {
			// LIBXML_NONET and nothing else. Never LIBXML_NOENT (substitutes entities: file
			// disclosure) nor LIBXML_DTDLOAD (fetches the external DTD).
			$opened = $reader->open($path, null, LIBXML_NONET);
			$doctype = null;
			while($opened && $reader->read()) {
				if($reader->nodeType === \XMLReader::DOC_TYPE) {
					$doctype = $reader->readOuterXml();
					if($doctype === "") break;
				} elseif($reader->nodeType === \XMLReader::ELEMENT) {
					return $doctype;
				}
			}
		} finally {
			if($opened) $reader->close();
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
		// end of file, or a parse error, before the root element
		self::RefuseSvg("not well-formed");
	}

	private static function RefuseSvg(string $reason): never {
		throw ErrorCodes::Instance()->GetException(4151, null, "SVG ".$reason);
	}

}
