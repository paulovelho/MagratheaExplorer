<?php

namespace MagratheaExplorer\Key;

use Magrathea\DB\Database;
use Magrathea\MagratheaModelControl;
use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\Secret;
use MagratheaExplorer\Folder\FolderControl;
use MagratheaExplorer\File\FileControl;
use MagratheaExplorer\Processing\RenditionProfile;
use MagratheaExplorer\Share\ShareControl;
use MagratheaExplorer\Storage\StorageFactory;

class KeyControl extends MagratheaModelControl {
	protected static $model = Key::class;

	/** Fields of a key the admin form edits, besides the name: KeySettings::FIELDS plus these. */
	const EDITABLE_FIELDS = ["expiration", "active"];

	const NAME_MAX_LENGTH = 255;

	/**
	 * Creates a key, its settings and its root folder, in one transaction: a failure in between
	 * leaves nothing behind (a key without a root folder answers 5004 on every request). Callers
	 * are the admin (KeyAdmin) and provisioning (ProvisioningControl::CreateKey()), never a key.
	 * @param string $name already checked: NormalizeName(), and not NameTaken()
	 * @param array $fields column values: KeySettings::FIELDS and EDITABLE_FIELDS, others ignored
	 * @return array{0: Key, 1: string} the key and its secret, which isn't stored and can't be read again
	 * @throws \Magrathea\Exceptions\MagratheaDBException 1062 when the name was taken meanwhile
	 */
	public static function Create(string $name, array $fields = [], bool $provisioned = false): array {
		$secret = Secret::NewKeySecret();
		$key = new Key();
		$key->secret_hash = Secret::Hash($secret);
		$key->name = $name;
		$key->provisioned = $provisioned;
		self::AssignFields($key, $fields);
		$key->Normalize();
		Database::Instance()->Transaction(function() use ($key) {
			$key->Insert();
			FolderControl::CreateRoot($key);
		});
		return [$key, $secret];
	}

	/**
	 * Admin-only setter: the name and the operator-editable fields, already parsed (KeyAdmin).
	 * Never `secret_hash` (RotateSecret()), `uses`/`total_size` (counters kept correct only by
	 * IncrementUses()/AdjustSize()'s atomic updates) or `provisioned`.
	 */
	public static function UpdateFields(Key $key, string $name, array $fields): Key {
		$key->name = $name;
		self::AssignFields($key, $fields);
		$key->Update();
		return $key;
	}

	private static function AssignFields(Key $key, array $fields): void {
		KeySettings::Apply($key, $fields);
		foreach(self::EDITABLE_FIELDS as $field) {
			if(array_key_exists($field, $fields)) $key->$field = $fields[$field];
		}
	}

	/**
	 * A new secret for $key, returned once. The old one fails from the very next request: no
	 * grace period. One UPDATE.
	 */
	public static function RotateSecret(Key $key): string {
		$secret = Secret::NewKeySecret();
		$hash = Secret::Hash($secret);
		Database::Instance()->PrepareAndExecute(
			"UPDATE `access_keys` SET `secret_hash` = ? WHERE `id` = ?",
			["string", "int"],
			[$hash, (int)$key->id]
		);
		$key->secret_hash = $hash;
		return $secret;
	}

	/** A key name as stored: trimmed, 1 to 255 characters, no control characters. null = not a valid name. */
	public static function NormalizeName(mixed $name): ?string {
		if(!is_string($name)) return null;
		$name = trim($name);
		if($name === "" || !mb_check_encoding($name, "UTF-8")) return null;
		if(mb_strlen($name, "UTF-8") > self::NAME_MAX_LENGTH || preg_match('/\p{Cc}/u', $name) === 1) return null;
		return $name;
	}

	/** Whether another key (not $exceptId) has this name. Names are unique across the instance. */
	public static function NameTaken(string $name, ?int $exceptId = null): bool {
		$existing = self::GetRowWhere(["name" => $name]);
		return $existing !== null && (int)$existing->id !== (int)$exceptId;
	}

	/** Largest per-key upload limit the admin form takes, in MB: far beyond any real upload, and safe in an int. */
	const MAX_UPLOAD_MB_CEILING = 1048576; // 1 TB
	const MAX_UPLOAD_MB_RULE = "Max upload (MB) takes a number of MB above 0 (decimals allowed, up to ".self::MAX_UPLOAD_MB_CEILING."), or nothing for the instance default.";

	/**
	 * The key form's "Max upload (MB)": empty = the instance default (null); otherwise MB (MiB,
	 * like `usage_limit_mb`), decimals allowed, stored as bytes.
	 * @throws \InvalidArgumentException anything else (MAX_UPLOAD_MB_RULE)
	 */
	public static function ParseMaxUploadMb(string $raw): ?int {
		$raw = trim($raw);
		if($raw === "") return null;
		$bytes = preg_match('/^\d+(\.\d+)?$/', $raw) === 1 && (float)$raw <= self::MAX_UPLOAD_MB_CEILING
			? (int)round((float)$raw * 1048576)
			: 0;
		if($bytes < 1) throw new \InvalidArgumentException(self::MAX_UPLOAD_MB_RULE);
		return $bytes;
	}

	/**
	 * The key form's rendition profile: empty = the instance default (null); otherwise JSON that
	 * RenditionProfile validates, stored normalized. Takes effect on the next upload or
	 * regenerate: queued jobs keep the specs they were queued with.
	 * @throws \InvalidArgumentException what's wrong with it
	 */
	public static function ParseRenditionProfile(string $raw): ?string {
		if(trim($raw) === "") return null;
		return RenditionProfile::Encode(RenditionProfile::Parse($raw));
	}

	/** ParseMaxUploadMb()'s inverse, exact: a limit not set in whole MB shows enough decimals to save back unchanged. */
	public static function MaxUploadMbForForm(?int $bytes): string {
		if($bytes === null) return "";
		if($bytes % 1048576 === 0) return (string)intdiv($bytes, 1048576);
		return rtrim(sprintf("%.7F", $bytes / 1048576), "0");
	}

	/**
	 * Resolves the bearer token from `Authorization: Bearer <secret>` into a usable Key, by its
	 * hash. Called independently by both the route's boolean auth gate (Key/KeyAuthControl.php)
	 * and the controller action itself (ExplorerApiControl::GetRequestKey()) -- the
	 * framework's declarative route auth doesn't thread identity through, so this is a
	 * cheap double lookup rather than one shared resolution.
	 */
	public static function ResolveFromBearer(?string $secret): Key {
		if($secret === null || trim($secret) === "") {
			ErrorCodes::Instance()->ThrowException(4011, null, "missing bearer token");
		}
		$key = self::GetRowWhere(["secret_hash" => Secret::Hash($secret)]);
		if(empty($key)) {
			ErrorCodes::Instance()->ThrowException(4041);
		}
		$key->AssertUsable();
		return $key;
	}

	/**
	 * Full cascading delete: every file (storage object + renditions + jobs + row), every folder,
	 * every scheduled-deletion row, then the key itself. No FK is ON DELETE CASCADE (plan.md's
	 * finalized schema doesn't use it), so deletion order matters: files before folders
	 * (files.folder_id FK), folders deepest-first (folders.parent_id self-FK), scheduled
	 * deletions and folders/files before the key row (their own key_id FK).
	 */
	public static function DeleteKeyNow(Key $key): void {
		// First of all: every share row points at a file, a folder AND this key, so it
		// blocks all three deletes below (and, via the file delete, removeDirectory()).
		ShareControl::DeleteForKey((int)$key->id);

		$files = FileControl::GetWhere(["key_id" => $key->id]);
		foreach($files as $file) {
			FileControl::DeleteFileAndStorage($file);
		}

		// Best-effort: only succeeds once every file above is gone, since the directory
		// has to be empty. Called while $key->storage_uuid is still readable -- must run
		// before $key->Delete() below.
		StorageFactory::Instance()->Get()->removeDirectory($key->StorageDir());

		self::DeleteFoldersDeepestFirst((int)$key->id);

		// upload_tokens.key_id is a FK; a token's folder_id and file_id aren't
		\MagratheaExplorer\File\UploadTokenControl::DeleteForKey((int)$key->id);

		$deletions = ScheduledDeletionControl::GetWhere(["key_id" => $key->id]);
		foreach($deletions as $deletion) {
			$deletion->Delete();
		}

		$key->Delete();
	}

	private static function DeleteFoldersDeepestFirst(int $keyId): void {
		$folders = FolderControl::GetWhere(["key_id" => $keyId]);
		while(count($folders) > 0) {
			$parentIds = array_filter(array_map(fn($f) => $f->parent_id, $folders));
			$progressed = false;
			foreach($folders as $idx => $folder) {
				if(!in_array($folder->id, $parentIds)) {
					$folder->Delete();
					unset($folders[$idx]);
					$progressed = true;
				}
			}
			if(!$progressed) break; // safety net: shouldn't happen against a valid tree
			$folders = array_values($folders);
		}
	}

}
