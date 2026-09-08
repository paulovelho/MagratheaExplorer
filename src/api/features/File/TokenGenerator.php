<?php

namespace MagratheaExplorer\File;

use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\File\Base\FileControlBase;

/**
 * Mints the random per-file/per-thumbnail URL tokens (see plan.md §3): each file gets
 * its own token, generated independently of the key and of any other file's token, so
 * knowing one file's URL gives zero information about any other file. No Images3
 * precedent -- that project identifies files by sequential id + a standard UUID column,
 * not by an unguessable per-object token, so this is written fresh.
 */
class TokenGenerator {

	const LENGTH = 21;
	const ALPHABET = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";
	const MAX_ATTEMPTS = 10;

	/** ~125 bits of randomness (21 chars, base62), nanoid-style. */
	public static function Generate(): string {
		$alphabetLength = strlen(self::ALPHABET);
		$token = "";
		for($i = 0; $i < self::LENGTH; $i++) {
			$token .= self::ALPHABET[random_int(0, $alphabetLength - 1)];
		}
		return $token;
	}

	/**
	 * Generates a token and confirms it's not already in use on `files.token` or
	 * `files.thumbnail_token` (they share one namespace: a thumbnail token must never
	 * collide with a file token either). Collision odds are astronomically low at this
	 * entropy -- this loop is a correctness backstop, not an expected code path.
	 */
	public static function GenerateUnique(): string {
		for($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
			$token = self::Generate();
			$inUse = FileControlBase::GetSimpleWhere(
				"`token` = '".$token."' OR `thumbnail_token` = '".$token."'"
			);
			if(count($inUse) === 0) return $token;
		}
		ErrorCodes::Instance()->ThrowException(5003);
	}

}
