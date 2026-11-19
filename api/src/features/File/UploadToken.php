<?php

namespace MagratheaExplorer\File;

use Magrathea\MagratheaModel;

/**
 * A single-use permission to upload one file straight from a browser (POST /upload/:token),
 * minted by the key's backend (POST /upload-tokens). The token itself is never stored, only its
 * sha256; `uuid` is its public, non-secret id, the one the backend confirms with
 * (GET /upload-tokens/:uuid).
 *
 * Lifecycle: pending → uploading (claimed by one request) → used (the file was stored, `file_id`)
 * or failed (a 4xx refusal: `error_code`/`error`); a 5xx puts it back to pending. A pending token
 * past `expires_at` reads as `expired` and can't be claimed.
 */
class UploadToken extends MagratheaModel {

	const PENDING = "pending";
	const UPLOADING = "uploading";
	const USED = "used";
	const FAILED = "failed";
	/** Only in the API: a pending token past its expiry. */
	const EXPIRED = "expired";

	protected $dbTable = "upload_tokens";
	protected $dbPk = "id";
	protected $dbValues = [
		"id" => "int",
		"uuid" => "uuid",
		"token_hash" => "string",
		"key_id" => "int",
		"folder_id" => "int",
		// bytes
		"max_size" => "int",
		// comma-separated file_type values; null = any
		"file_types" => "string",
		// as the backend sent them; null = the key's default at upload time
		"verbatim" => "boolean",
		"no_convert" => "boolean",
		// JSON list of tag names
		"tags" => "string",
		"status" => "string",
		"file_id" => "int",
		"error_code" => "int",
		"error" => "string",
		// unix time, the database's clock (UNIX_TIMESTAMP()): no local-time DST overlap
		"expires_at" => "int",
	];

	public $id, $uuid, $token_hash, $key_id, $folder_id, $max_size, $file_types, $verbatim, $no_convert, $tags;
	public $status, $file_id, $error_code, $error, $expires_at;

	/** @return ?string[] */
	public function FileTypes(): ?array {
		if($this->file_types === null || $this->file_types === "") return null;
		return explode(",", (string)$this->file_types);
	}

	/** @return string[] */
	public function TagNames(): array {
		$decoded = json_decode((string)$this->tags, true);
		return is_array($decoded) ? array_values(array_filter($decoded, "is_string")) : [];
	}

}
