<?php

namespace MagratheaExplorer\File;

use Magrathea\DB\Database;
use Magrathea\MagratheaModelControl;
use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\Folder\Folder;
use MagratheaExplorer\Key\Key;

/**
 * Upload tokens' state changes. Each one is a single conditional UPDATE on the row's status, so
 * two requests carrying the same token can't both upload: one claim wins, the other is told why.
 * Times are the database's clock (UNIX_TIMESTAMP()) on both sides, never PHP's.
 */
class UploadTokenControl extends MagratheaModelControl {
	protected static $model = UploadToken::class;

	const MIN_EXPIRES_S = 60;
	const MAX_EXPIRES_S = 900;
	/** Tokens this long past their expiry are deleted by `cron.php --process`, whatever their status. */
	const PURGE_AFTER_S = 7 * 86400;
	const PURGE_PER_RUN = 1000;
	/** 32 random bytes, lowercase hex: the router lowercases paths, so the token can't use upper case. */
	const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

	public static function DbNow(): int {
		return (int)Database::Instance()->QueryOne("SELECT UNIX_TIMESTAMP()");
	}

	public static function Hash(string $token): string {
		return hash("sha256", $token);
	}

	/**
	 * A new token, returned once: only its hash is stored.
	 * @param array{max_size: int, file_types: ?string[], expires_in: int, verbatim: ?bool, no_convert: ?bool, tags: string[]} $request
	 * @return array{0: UploadToken, 1: string}
	 */
	public static function Mint(Key $key, Folder $folder, array $request): array {
		$token = bin2hex(random_bytes(32));
		$row = new UploadToken();
		$row->token_hash = self::Hash($token);
		$row->key_id = (int)$key->id;
		$row->folder_id = (int)$folder->id;
		$row->max_size = $request["max_size"];
		$row->file_types = $request["file_types"] === null ? null : implode(",", $request["file_types"]);
		$row->verbatim = $request["verbatim"];
		$row->no_convert = $request["no_convert"];
		$row->tags = $request["tags"] === [] ? null : json_encode(array_values($request["tags"]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		$row->status = UploadToken::PENDING;
		$row->expires_at = self::DbNow() + $request["expires_in"];
		$row->Insert();
		return [$row, $token];
	}

	/** The token's row, or null for anything that isn't a token we minted. */
	public static function GetByToken(string $token): ?UploadToken {
		if(preg_match(self::TOKEN_PATTERN, $token) !== 1) return null;
		return self::GetRowWhere(["token_hash" => self::Hash($token)]);
	}

	/** By its public id, the key's own only: another key's is 4046, like an unknown one. */
	public static function GetForKeyByUuid(Key $key, string $uuid): UploadToken {
		$row = self::GetRowWhere(["uuid" => $uuid, "key_id" => (int)$key->id]);
		if($row === null) ErrorCodes::Instance()->ThrowException(4046);
		return $row;
	}

	/**
	 * Takes the token for this request: pending and not expired → uploading. Checked when the
	 * upload *arrives* (PHP only runs once the whole body is in). Anything else throws: 4101
	 * expired, 4092 used (or another request has it right now).
	 */
	public static function Claim(UploadToken $token): void {
		$db = Database::Instance();
		$db->PrepareAndExecute(
			"UPDATE `upload_tokens` SET `status` = 'uploading' WHERE `id` = ? AND `status` = 'pending' AND `expires_at` > UNIX_TIMESTAMP()",
			["int"],
			[(int)$token->id]
		);
		if($db->LastAffectedRows() === 1) {
			$token->status = UploadToken::UPLOADING;
			return;
		}
		$row = $db->QueryRow("SELECT `status`, `expires_at` <= UNIX_TIMESTAMP() AS `expired` FROM `upload_tokens` WHERE `id` = ".(int)$token->id);
		[$code, $detail] = self::Refusal(empty($row) ? null : (string)$row["status"], !empty($row) && (int)$row["expired"] === 1);
		ErrorCodes::Instance()->ThrowException($code, null, $detail);
	}

	/**
	 * Why a claim failed, from the row as it is now. A pending row that isn't expired lost a race
	 * to a request that has since put it back: 4092 too, the browser can try again.
	 * @return array{0: int, 1: ?string}
	 */
	public static function Refusal(?string $status, bool $expired): array {
		return match(true) {
			$status === null => [4046, null],
			$status === UploadToken::PENDING && $expired => [4101, null],
			$status === UploadToken::UPLOADING => [4092, "another upload with it is in progress"],
			$status === UploadToken::FAILED => [4092, "an upload with it was refused: ask for a new token"],
			default => [4092, null],
		};
	}

	/**
	 * The upload's file was inserted: the token is used up, in the same transaction (UploadOptions'
	 * onStored), so the file and the token's state commit together. Throws if this request doesn't
	 * hold the token any more, which rolls the file back.
	 */
	public static function MarkUsed(int $id, int $fileId): void {
		$db = Database::Instance();
		$db->PrepareAndExecute(
			"UPDATE `upload_tokens` SET `status` = 'used', `file_id` = ?, `error_code` = NULL, `error` = NULL WHERE `id` = ? AND `status` = 'uploading'",
			["int", "int"],
			[$fileId, $id]
		);
		if($db->LastAffectedRows() !== 1) {
			throw new \RuntimeException("upload token ".$id." isn't held by this upload any more");
		}
	}

	/** A 4xx refusal: the token is used up, and GET /upload-tokens/:id says why. */
	public static function MarkFailed(int $id, int $code, string $message): void {
		Database::Instance()->PrepareAndExecute(
			"UPDATE `upload_tokens` SET `status` = 'failed', `error_code` = ?, `error` = ? WHERE `id` = ? AND `status` = 'uploading'",
			["int", "string", "int"],
			[$code, mb_strlen($message) > 1000 ? mb_substr($message, 0, 999)."…" : $message, $id]
		);
	}

	/** A 5xx (storage, database) or a fatal: back to pending, so the browser can retry while it's valid. */
	public static function Release(int $id): void {
		Database::Instance()->PrepareAndExecute(
			"UPDATE `upload_tokens` SET `status` = 'pending' WHERE `id` = ? AND `status` = 'uploading'",
			["int"],
			[$id]
		);
	}

	/** `cron.php --process`. @return int how many */
	public static function Purge(): int {
		$db = Database::Instance();
		$db->Query("DELETE FROM `upload_tokens` WHERE `expires_at` < UNIX_TIMESTAMP() - ".self::PURGE_AFTER_S." LIMIT ".self::PURGE_PER_RUN);
		return $db->LastAffectedRows();
	}

	/** Before the key row is deleted (upload_tokens.key_id is a FK). */
	public static function DeleteForKey(int $keyId): void {
		Database::Instance()->PrepareAndExecute("DELETE FROM `upload_tokens` WHERE `key_id` = ?", ["int"], [$keyId]);
	}

}
