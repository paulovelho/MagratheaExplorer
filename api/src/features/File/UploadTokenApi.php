<?php

namespace MagratheaExplorer\File;

use Magrathea\Config;
use Magrathea\Exceptions\MagratheaDBException;
use Magrathea\Logger;
use MagratheaExplorer\ApiTime;
use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\ExplorerApiControl;
use MagratheaExplorer\Folder\Folder;
use MagratheaExplorer\Folder\FolderControl;
use MagratheaExplorer\Key\Key;
use MagratheaExplorer\Key\KeyControl;
use MagratheaExplorer\RequestBody;
use MagratheaExplorer\RequestBool;

/**
 * Direct uploads: a browser sends a file to the API without ever holding the key.
 *   1. the key's backend mints a token: POST /upload-tokens (key auth) → {id, token, upload_url, expires_at};
 *   2. the browser posts the file to `upload_url` (POST /upload/:token, no auth: the token is the
 *      credential), multipart `file`, and gets the File back;
 *   3. the backend confirms by the token's id, never trusting the browser: GET /upload-tokens/:id.
 * A token is single use. A 4xx refusal (too large, wrong type, quota) uses it up, with the reason
 * kept for step 3; a 5xx puts it back, so the browser can retry while it's valid.
 *
 * Extends ExplorerApiControl for its body parsing. GetRequestKey() is only called on the two
 * key routes: the upload route has no key in the request.
 */
class UploadTokenApi extends ExplorerApiControl {

	const FIELDS = ["folder_uuid", "max_size", "file_types", "expires_in", "verbatim", "no_convert", "tags"];
	const FILE_TYPES = ["image", "audio", "video", "document", "other"];

	/** POST /upload-tokens */
	public function Create($data = false): array {
		$key = $this->GetRequestKey();
		$body = $this->GetPost() ?? [];
		$request = self::ParseCreate($body, $key->EffectiveMaxUploadSize());
		// active, not expired, no pending deletion, upload count left: a token the key couldn't use isn't minted
		$key->AssertCanUpload(0);
		$folder = !empty($body["folder_uuid"]) ? FolderControl::GetForKeyByUuid($key, (string)$body["folder_uuid"]) : FolderControl::GetRoot($key);
		[$row, $token] = UploadTokenControl::Mint($key, $folder, $request);
		return [
			"id" => $row->uuid,
			"token" => $token,
			"upload_url" => self::UploadUrl($token),
			"expires_at" => ApiTime::FromUnix((int)$row->expires_at),
		];
	}

	/** GET /upload-tokens/:uuid -- the key's own tokens only */
	public function Get($params): array {
		$key = $this->GetRequestKey();
		$row = UploadTokenControl::GetForKeyByUuid($key, (string)($params["uuid"] ?? ""));
		$file = null;
		if($row->file_id !== null) {
			$stored = FileControl::GetRowWhere(["id" => (int)$row->file_id, "key_id" => (int)$key->id]);
			if($stored !== null) $file = FileResponse::Owner($stored);
		}
		return self::View($row, UploadTokenControl::DbNow(), $file);
	}

	/** POST /upload/:token -- no auth, multipart `file`. Returns the File (owner view). */
	public function Upload($params): array {
		$token = UploadTokenControl::GetByToken((string)($params["token"] ?? ""));
		if($token === null) ErrorCodes::Instance()->ThrowException(4046);
		$overflow = RequestBody::ExceedsPostMaxSize($_SERVER["CONTENT_TYPE"] ?? null, $_SERVER["CONTENT_LENGTH"] ?? null,
			Key::IniSizeToBytes(ini_get("post_max_size")));
		$hasFile = !empty($_FILES["file"]) && ($_FILES["file"]["error"] ?? null) !== UPLOAD_ERR_NO_FILE;
		// A request with no file isn't an upload: it doesn't use the token up.
		if(!$hasFile && !$overflow) ErrorCodes::Instance()->ThrowException(4001, null, "file");

		return self::Consume($token, function(UploadToken $token) use ($overflow) {
			$key = KeyControl::GetRowWhere(["id" => (int)$token->key_id]);
			if($key === null) ErrorCodes::Instance()->ThrowException(4046);
			if($overflow) UploadPipeline::RefuseAbove(min((int)$token->max_size, $key->EffectiveMaxUploadSize()), null);
			$folder = FolderControl::GetForKey($key, (int)$token->folder_id);
			return self::Store($token, $key, $folder, $_FILES["file"]);
		});
	}

	/**
	 * Claims the token, runs $store, and settles the token by how it ended: stored → used (inside
	 * the file's transaction, see Store()); a 4xx → failed, with its code and message; anything
	 * else (5xx, a PHP fatal) → pending again. Every settle is conditional on `uploading`, so a
	 * token the file's transaction already made `used` stays used whatever happens after.
	 * @param callable(UploadToken): array $store
	 */
	public static function Consume(UploadToken $token, callable $store): array {
		UploadTokenControl::Claim($token);
		$id = (int)$token->id;
		$settled = false;
		// a fatal (memory, time limit) skips every catch: the token mustn't stay `uploading`
		register_shutdown_function(function() use (&$settled, $id) {
			if($settled) return;
			try { UploadTokenControl::Release($id); } catch(\Throwable $ex) {}
		});
		try {
			$result = $store($token);
			$settled = true;
			return $result;
		} catch(\Throwable $ex) {
			$status = self::HttpStatus($ex);
			try {
				if($status >= 400 && $status < 500) {
					UploadTokenControl::MarkFailed($id, (int)$ex->getCode(), $ex->getMessage());
				} else {
					UploadTokenControl::Release($id);
				}
			} catch(\Throwable $bookkeeping) {
				Logger::Instance()->LogError($bookkeeping);
			}
			$settled = true;
			throw $ex;
		}
	}

	private static function Store(UploadToken $token, Key $key, Folder $folder, array $upload): array {
		UploadPipeline::AssertAcceptable($key, $upload);
		$size = (int)($upload["size"] ?? 0);
		if($size > (int)$token->max_size) {
			UploadPipeline::RefuseAbove((int)$token->max_size, $size, " (this upload token's max_size)");
		}
		$tokenId = (int)$token->id;
		$options = UploadOptions::Resolve($key, $token->verbatim, $token->no_convert, $token->TagNames(),
			onStored: fn(File $file) => UploadTokenControl::MarkUsed($tokenId, (int)$file->id),
			allowedFileTypes: $token->FileTypes());
		$file = UploadPipeline::Handle($key, $folder, $upload, $options);
		return FileResponse::Owner($file->Reload(), $folder->uuid);
	}

	/**
	 * The HTTP status an exception becomes, by the framework's rule (MagratheaApi::
	 * HttpStatusFromException(), private there): a status set with SetStatus(); else a 3-digit
	 * code as is, 4 digits / 10, 5 digits / 100; anything else, and every database error, 500.
	 */
	public static function HttpStatus(\Throwable $ex): int {
		if($ex instanceof MagratheaDBException || $ex instanceof \mysqli_sql_exception) return 500;
		if($ex instanceof \Magrathea\Exceptions\MagratheaApiException && is_numeric($ex->status ?? null)
			&& (int)$ex->status >= 400 && (int)$ex->status <= 599) {
			return (int)$ex->status;
		}
		$code = (int)$ex->getCode();
		$status = match(true) {
			$code >= 100 && $code <= 999 => $code,
			$code >= 1000 && $code <= 9999 => intdiv($code, 10),
			$code >= 10000 && $code <= 59999 => intdiv($code, 100),
			default => 500,
		};
		return $status >= 400 && $status <= 599 ? $status : 500;
	}

	/** The token's state for its backend. A pending token past its expiry reads `expired`. */
	public static function View(UploadToken $row, int $now, ?array $file): array {
		$status = (string)$row->status;
		if($status === UploadToken::PENDING && (int)$row->expires_at <= $now) $status = UploadToken::EXPIRED;
		return [
			"id" => $row->uuid,
			"status" => $status,
			"expires_at" => ApiTime::FromUnix((int)$row->expires_at),
			"file" => $file,
			"error" => $row->error_code !== null ? ["code" => (int)$row->error_code, "message" => (string)$row->error] : null,
		];
	}

	public static function UploadUrl(string $token): string {
		return rtrim((string)Config::Instance()->Get("app_url"), "/")."/api/v1/upload/".$token;
	}

	/**
	 * POST /upload-tokens' body. Every field is optional; an unknown one is 40013 naming it (a typo
	 * silently ignored would mint a token looser than asked for).
	 * @param int $keyLimit the key's effective upload limit: max_size's ceiling and default
	 * @return array{max_size: int, file_types: ?string[], expires_in: int, verbatim: ?bool, no_convert: ?bool, tags: string[]}
	 */
	public static function ParseCreate(array $body, int $keyLimit): array {
		foreach(array_keys($body) as $field) {
			if(!in_array($field, self::FIELDS, true)) {
				ErrorCodes::Instance()->ThrowException(40013, null, $field." (not a field of POST /upload-tokens)");
			}
		}
		if(isset($body["folder_uuid"]) && !is_string($body["folder_uuid"])) {
			ErrorCodes::Instance()->ThrowException(40013, null, "folder_uuid (expected a string)");
		}
		$maxSize = self::ParseInt($body, "max_size", 1, $keyLimit, " (bytes, at most ".$keyLimit.", this key's limit)") ?? $keyLimit;
		$expiresIn = self::ParseInt($body, "expires_in", UploadTokenControl::MIN_EXPIRES_S, UploadTokenControl::MAX_EXPIRES_S, " (seconds)")
			?? UploadTokenControl::MAX_EXPIRES_S;
		$tags = FileApi::ParseTags($body);
		foreach($tags as $tag) {
			if(mb_strlen($tag, "UTF-8") > 100) ErrorCodes::Instance()->ThrowException(40013, null, "tags (at most 100 characters each)");
		}
		if(strlen(json_encode($tags, JSON_UNESCAPED_UNICODE) ?: "") > 1000) {
			ErrorCodes::Instance()->ThrowException(40013, null, "tags (too many)");
		}
		return [
			"max_size" => $maxSize,
			"file_types" => self::ParseFileTypes($body),
			"expires_in" => $expiresIn,
			"verbatim" => RequestBool::Parse($body, "verbatim"),
			"no_convert" => RequestBool::Parse($body, "no_convert"),
			"tags" => $tags,
		];
	}

	/** An int field (a JSON number or a string of digits) from $min to $max. null = absent. */
	private static function ParseInt(array $body, string $field, int $min, int $max, string $hint): ?int {
		$raw = $body[$field] ?? null;
		if($raw === null || $raw === "") return null;
		$value = is_int($raw) ? $raw : (is_string($raw) && preg_match('/^\d{1,18}$/', trim($raw)) === 1 ? (int)trim($raw) : null);
		if($value === null || $value < $min || $value > $max) {
			ErrorCodes::Instance()->ThrowException(40013, null, $field." (expected ".$min." to ".$max.$hint.")");
		}
		return $value;
	}

	/** `file_types`: a list, or comma-separated, of the five types. null = any. @return ?string[] */
	public static function ParseFileTypes(array $body): ?array {
		$raw = $body["file_types"] ?? null;
		if($raw === null || $raw === "") return null;
		$items = is_string($raw) ? explode(",", $raw) : (is_array($raw) && array_is_list($raw) ? $raw : null);
		$types = [];
		foreach($items ?? [false] as $item) {
			$type = is_string($item) ? strtolower(trim($item)) : null;
			if($type === "") continue;
			if(!in_array($type, self::FILE_TYPES, true)) {
				ErrorCodes::Instance()->ThrowException(40013, null, "file_types (expected a list of: ".implode(", ", self::FILE_TYPES).")");
			}
			$types[$type] = true;
		}
		if($types === []) ErrorCodes::Instance()->ThrowException(40013, null, "file_types (empty: leave it out for any type)");
		return array_values(array_intersect(self::FILE_TYPES, array_keys($types)));
	}

}
