<?php

namespace MagratheaExplorer\File;

use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\ExplorerApiControl;
use MagratheaExplorer\ExplorerConfig;
use MagratheaExplorer\Idempotency;
use MagratheaExplorer\IdempotencyDbStore;
use MagratheaExplorer\IdempotencyRecord;
use MagratheaExplorer\Key\Key;
use MagratheaExplorer\RequestBody;
use MagratheaExplorer\RequestBool;
use MagratheaExplorer\Folder\Folder;
use MagratheaExplorer\Folder\FolderControl;
use MagratheaExplorer\Processing\FileProcessing;

class FileApi extends ExplorerApiControl {

	private static array $validFileTypes = ["image", "audio", "video", "document", "other"];

	/**
	 * POST /files -- one file, from exactly one of (4001 otherwise):
	 * - `file`: a multipart upload;
	 * - `source_url`: an https URL the server downloads (SafeFetcher), in a JSON or form body (or a
	 *   multipart field). Off unless magrathea.conf `source_url_enabled` is on.
	 * Either one takes `name` (else the file's own), `folder_uuid`, `verbatim`, `no_convert` and
	 * `tags` (comma-separated, or a JSON list). Header: Idempotency-Key (optional, see Idempotency).
	 */
	public function Upload(): array {
		$key = $this->GetRequestKey();

		// A multipart body above post_max_size: PHP dropped $_FILES and $_POST whole, with no
		// error code, so it's checked before anything takes it for a request without a file.
		// Only multipart: any other body (JSON, form) with no `file` is a 4001, whatever its size.
		$postMaxBytes = Key::IniSizeToBytes(ini_get("post_max_size"));
		if(RequestBody::ExceedsPostMaxSize($_SERVER["CONTENT_TYPE"] ?? null, $_SERVER["CONTENT_LENGTH"] ?? null, $postMaxBytes)) {
			UploadPipeline::RefuseTooLarge($key, null); // CONTENT_LENGTH is the whole body, not the file
		}
		$post = $this->GetPost() ?? $_POST;
		// A file input left empty arrives as UPLOAD_ERR_NO_FILE: that's no file.
		$hasFile = !empty($_FILES["file"]) && ($_FILES["file"]["error"] ?? null) !== UPLOAD_ERR_NO_FILE;
		$sourceUrl = $post["source_url"] ?? null;
		$hasUrl = $sourceUrl !== null && $sourceUrl !== "";
		if($hasFile === $hasUrl) {
			ErrorCodes::Instance()->ThrowException(4001, null, "file or source_url (exactly one)");
		}
		if($hasUrl && !SafeFetcher::IsEnabled()) {
			ErrorCodes::Instance()->ThrowException(40012, null, "uploads from a URL aren't enabled on this instance");
		}
		$idempotencyKey = Idempotency::KeyFromRequest();

		$folder = !empty($post["folder_uuid"]) ? FolderControl::GetForKeyByUuid($key, (string)$post["folder_uuid"]) : FolderControl::GetRoot($key);
		$tagNames = self::ParseTags($post);
		// Both parsed (and a bad value refused with 4009) before the file is touched.
		$verbatim = RequestBool::Parse($post, "verbatim");
		$noConvert = RequestBool::Parse($post, "no_convert");
		$name = self::ParseName($post);

		return $hasFile
			? self::UploadFile($key, $folder, $tagNames, $verbatim, $noConvert, $name, $idempotencyKey)
			: self::UploadFromUrl($key, $folder, $tagNames, $verbatim, $noConvert, $name, $sourceUrl, $idempotencyKey);
	}

	private static function UploadFile(Key $key, Folder $folder, array $tagNames, ?bool $verbatim, ?bool $noConvert, ?string $name, ?string $idempotencyKey): array {
		$upload = $_FILES["file"];
		UploadPipeline::AssertAcceptable($key, $upload);
		if($name !== null) $upload["name"] = $name;

		$claim = null;
		$uploadSha256 = null;
		if($idempotencyKey !== null) {
			$uploadSha256 = UploadPipeline::HashUpload($upload);
			$begin = Idempotency::Begin(new IdempotencyDbStore(), Idempotency::CREDENTIAL_KEY, (int)$key->id, $idempotencyKey,
				self::UploadRequestHash((int)$folder->id, $tagNames, $verbatim, $noConvert, (string)($upload["name"] ?? ""), $uploadSha256));
			if($begin instanceof IdempotencyRecord) return self::Replay($key, $begin);
			$claim = $begin;
		}
		$options = UploadOptions::Resolve($key, $verbatim, $noConvert, $tagNames,
			uploadSha256: $uploadSha256, onStored: $claim?->Recorder(Idempotency::RESOURCE_FILE));

		return Idempotency::Run($claim, fn() => self::Store($key, $folder, $upload, $options));
	}

	/**
	 * The `source_url` upload: the URL is checked (40012) and the Idempotency-Key claimed before
	 * anything is downloaded, so a replay never fetches. The download then goes through the same
	 * pipeline as an upload, from the fetched file itself (consumeSource: no second copy).
	 */
	private static function UploadFromUrl(Key $key, Folder $folder, array $tagNames, ?bool $verbatim, ?bool $noConvert, ?string $name, mixed $rawUrl, ?string $idempotencyKey): array {
		if(!is_string($rawUrl)) {
			ErrorCodes::Instance()->ThrowException(40013, null, "source_url (expected a string)");
		}
		$fetcher = new SafeFetcher();
		try {
			$url = SourceUrl::Parse($rawUrl, $fetcher->Ports());
		} catch(SourceUrlException $ex) {
			self::ThrowSourceUrlError($key, $ex);
		}

		$claim = null;
		if($idempotencyKey !== null) {
			$begin = Idempotency::Begin(new IdempotencyDbStore(), Idempotency::CREDENTIAL_KEY, (int)$key->id, $idempotencyKey,
				self::SourceUrlRequestHash((int)$folder->id, $tagNames, $verbatim, $noConvert, $name, $url->ToString()),
				Idempotency::LeaseSeconds(null, $fetcher->TimeoutS()));
			if($begin instanceof IdempotencyRecord) return self::Replay($key, $begin);
			$claim = $begin;
		}
		$options = UploadOptions::Resolve($key, $verbatim, $noConvert, $tagNames,
			onStored: $claim?->Recorder(Idempotency::RESOURCE_FILE), consumeSource: true);

		return Idempotency::Run($claim, function() use ($key, $folder, $name, $url, $fetcher, $options) {
			// An inactive, expired, pending-deletion or count-capped key downloads nothing. The size
			// cap is checked against the Content-Length before the body, and for real in Handle().
			$key->AssertCanImport(0, true);
			try {
				$fetched = $fetcher->Fetch($url, $key->EffectiveMaxUploadSize(), ExplorerConfig::ProcessingTmpPath(),
					fn(int $length) => $key->AssertCanImport($length, true));
			} catch(SourceUrlException $ex) {
				self::ThrowSourceUrlError($key, $ex);
			}
			try {
				$upload = [
					"name" => $name ?? RemoteFileName::Choose($fetched->contentDisposition, $fetched->finalUrl, $url),
					"type" => "",
					"tmp_name" => $fetched->path,
					"error" => UPLOAD_ERR_OK,
					"size" => $fetched->size,
				];
				return self::Store($key, $folder, $upload, $options);
			} finally {
				// Handle() deletes it; this is for a throw before Handle() took it over.
				if(is_file($fetched->path)) @unlink($fetched->path);
			}
		});
	}

	private static function Store(Key $key, Folder $folder, array $upload, UploadOptions $options): array {
		$file = UploadPipeline::Handle($key, $folder, $upload, $options);
		// Insert() leaves created_at null on the object (the database fills it), and an inline
		// processing run changed the row after it: read it back for the response. Here, not in
		// UploadPipeline, which bulk import calls per file.
		return FileResponse::Owner($file->Reload(), $folder->uuid);
	}

	/** A SourceUrlException as the API's error: 40012 refused, 4002 too large (with the key's limit), 5021 failed. */
	private static function ThrowSourceUrlError(Key $key, SourceUrlException $ex): never {
		if($ex->kind === SourceUrlException::TOO_LARGE) UploadPipeline::RefuseTooLarge($key, $ex->size);
		ErrorCodes::Instance()->ThrowException($ex->kind === SourceUrlException::REFUSED ? 40012 : 5021, null, $ex->getMessage());
		throw new \LogicException("unreachable"); // ThrowException() always throws
	}

	/**
	 * `tags`: comma-separated (a form or multipart field), or a list of strings (JSON). Trimmed,
	 * empty ones dropped. Anything else → 40013.
	 * @return string[]
	 */
	public static function ParseTags(array $post): array {
		$raw = $post["tags"] ?? null;
		if($raw === null || $raw === "") return [];
		if(is_string($raw)) {
			$items = explode(",", $raw);
		} elseif(is_array($raw) && array_is_list($raw) && array_filter($raw, fn($t) => !is_string($t)) === []) {
			$items = $raw;
		} else {
			ErrorCodes::Instance()->ThrowException(40013, null, "tags (expected a comma-separated string or a list of strings)");
		}
		return array_values(array_filter(array_map("trim", $items), fn($t) => $t !== ""));
	}

	/**
	 * `name`: the file's display name, for either source. Absent or blank = the file's own (the
	 * multipart filename, or RemoteFileName's for a URL). Not a string, invalid UTF-8, control
	 * characters or over 255 characters → 40013.
	 */
	public static function ParseName(array $post): ?string {
		$raw = $post["name"] ?? null;
		if($raw === null) return null;
		if(!is_string($raw)) {
			ErrorCodes::Instance()->ThrowException(40013, null, "name (expected a string)");
		}
		$name = trim($raw);
		if($name === "") return null;
		if(!mb_check_encoding($name, "UTF-8") || preg_match('/\p{Cc}/u', $name) === 1) {
			ErrorCodes::Instance()->ThrowException(40013, null, "name (control characters or invalid UTF-8)");
		}
		if(mb_strlen($name, "UTF-8") > RemoteFileName::MAX_LENGTH) {
			ErrorCodes::Instance()->ThrowException(40013, null, "name (over ".RemoteFileName::MAX_LENGTH." characters)");
		}
		return $name;
	}

	/**
	 * What makes two uploads "the same request" for an Idempotency-Key: the folder, the tags (a
	 * set), the flags as sent (null = not sent, so a key's default changing in between doesn't
	 * turn a retry into a different request), the file's name (`name`, else the part's filename)
	 * and the sha256 of its bytes.
	 */
	public static function UploadRequestHash(int $folderId, array $tagNames, ?bool $verbatim, ?bool $noConvert, string $name, string $sha256): string {
		return Idempotency::RequestHash("POST files", self::RequestFields($folderId, $tagNames, $verbatim, $noConvert) + [
			"name" => $name,
			"sha256" => $sha256,
		]);
	}

	/**
	 * The same for a `source_url` upload: the canonical URL (SourceUrl::ToString()) instead of
	 * the bytes, which aren't known before the fetch the key is there to save, and `name` as sent
	 * (null = not sent). It never equals an upload's hash: a key reused across the two is a 4221.
	 */
	public static function SourceUrlRequestHash(int $folderId, array $tagNames, ?bool $verbatim, ?bool $noConvert, ?string $name, string $sourceUrl): string {
		return Idempotency::RequestHash("POST files", self::RequestFields($folderId, $tagNames, $verbatim, $noConvert) + [
			"name" => $name,
			"source_url" => $sourceUrl,
		]);
	}

	private static function RequestFields(int $folderId, array $tagNames, ?bool $verbatim, ?bool $noConvert): array {
		$tags = array_values(array_unique(array_map("strval", $tagNames)));
		sort($tags, SORT_STRING);
		return [
			"folder_id" => $folderId,
			"tags" => $tags,
			"verbatim" => $verbatim,
			"no_convert" => $noConvert,
		];
	}

	/**
	 * The file a completed upload made, as it is now: same shape and status as the first
	 * response, `Idempotent-Replayed: true`. The pipeline doesn't run, so `uses` and
	 * `total_size` don't change. Deleted since: 4043.
	 */
	private static function Replay(Key $key, IdempotencyRecord $record): array {
		// can't happen (the route is in the request hash), but a key id must never be read as a file id
		if($record->resourceType !== Idempotency::RESOURCE_FILE) ErrorCodes::Instance()->ThrowException(4221);
		$file = FileControl::GetForKey($key, (int)$record->resourceId);
		Idempotency::MarkReplayed();
		return FileResponse::Owner($file);
	}

	/** GET /files -- filters: ?folder_uuid= (defaults to the key's root folder), ?file_type=, ?tag= */
	public function GetAll(): array {
		$key = $this->GetRequestKey();
		$folder = !empty($_GET["folder_uuid"]) ? FolderControl::GetForKeyByUuid($key, (string)$_GET["folder_uuid"]) : FolderControl::GetRoot($key);
		$conditions = ["`key_id` = ".(int)$key->id, "`folder_id` = ".(int)$folder->id];

		if(!empty($_GET["file_type"])) {
			if(!in_array($_GET["file_type"], self::$validFileTypes, true)) {
				ErrorCodes::Instance()->ThrowException(4001, null, "file_type");
			}
			$conditions[] = "`file_type` = '".$_GET["file_type"]."'";
		}

		$files = FileControl::GetSimpleWhere(implode(" AND ", $conditions));

		if(!empty($_GET["tag"])) {
			$tag = \MagratheaExplorer\File\TagControl::GetRowWhere(["name" => $_GET["tag"]]);
			$fileIds = $tag !== null ? FileTagControl::GetFileIdsForTag((int)$tag->id) : [];
			$files = array_values(array_filter($files, fn($f) => in_array((int)$f->id, $fileIds, true)));
		}

		return self::OwnerViews($files, $folder->uuid);
	}

	const LOOKUP_MAX = 100;
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	/**
	 * POST /files/lookup -- body: `uuids`, a JSON list or form `uuids[]`, 1 to 100 after removing
	 * duplicates. Returns the key's files among them, in the request's order. Unknown, another
	 * key's and malformed uuids are left out (a malformed one can't be the key's either).
	 * One query for the files, then renditions, tags and folder uuids preloaded: 4 in all.
	 */
	public function Lookup(): array {
		return self::LookupFor($this->GetRequestKey(), $this->GetPost() ?? $_POST);
	}

	public static function LookupFor(Key $key, array $post): array {
		$uuids = self::ParseLookupUuids($post);
		if($uuids === []) return [];
		$db = \Magrathea\DB\Database::Instance();
		// the pattern allows only hex and hyphens: Escape() is a second guard, not the first
		$in = implode(",", array_map(fn($u) => "'".$db->Escape($u)."'", $uuids));
		$files = FileControl::GetSimpleWhere("`key_id` = ".(int)$key->id." AND `uuid` IN (".$in.")");
		$byUuid = [];
		foreach($files as $file) $byUuid[strtolower((string)$file->uuid)] = $file;
		$ordered = [];
		foreach($uuids as $uuid) {
			if(isset($byUuid[$uuid])) $ordered[] = $byUuid[$uuid];
		}
		return self::OwnerViews($ordered, null);
	}

	/**
	 * `uuids` for the lookup: the well-formed ones, lowercased, duplicates removed, first
	 * occurrence's order kept. Missing → 4001. Not a list, empty, or more than 100 distinct
	 * items (counted before malformed ones are dropped) → 40013.
	 * @return string[]
	 */
	public static function ParseLookupUuids(array $post): array {
		if(!array_key_exists("uuids", $post) || $post["uuids"] === null) {
			ErrorCodes::Instance()->ThrowException(4001, null, "uuids");
		}
		$raw = $post["uuids"];
		if(!is_array($raw) || !array_is_list($raw)) {
			ErrorCodes::Instance()->ThrowException(40013, null, "uuids (expected a list of uuids)");
		}
		$distinct = [];
		foreach($raw as $item) {
			$normalized = is_string($item) ? strtolower(trim($item)) : null;
			// a non-string can't be a uuid: counted (so 101 items are refused anyway), never matched
			$distinct[$normalized ?? "\0".count($distinct)] = $normalized;
		}
		if($distinct === []) {
			ErrorCodes::Instance()->ThrowException(40013, null, "uuids (at least 1)");
		}
		if(count($distinct) > self::LOOKUP_MAX) {
			ErrorCodes::Instance()->ThrowException(40013, null, "uuids (at most ".self::LOOKUP_MAX.")");
		}
		return array_values(array_filter($distinct, fn($u) => $u !== null && preg_match(self::UUID_PATTERN, $u) === 1));
	}

	/**
	 * Owner views of many files with no per-file query: renditions, tags and (when $folderUuid
	 * isn't given) folder uuids are each loaded in one query.
	 * @param File[] $files
	 */
	private static function OwnerViews(array $files, ?string $folderUuid): array {
		$ids = array_map(fn($f) => (int)$f->id, $files);
		$renditions = FileRenditionControl::GetForFiles($ids);
		$tags = FileTagControl::GetTagNamesForFiles($ids);
		$folders = $folderUuid === null ? FolderControl::UuidsFor(array_map(fn($f) => (int)$f->folder_id, $files)) : [];
		return array_map(fn($file) => FileResponse::Owner($file,
			$folderUuid ?? ($folders[(int)$file->folder_id] ?? null),
			$renditions[(int)$file->id] ?? [],
			$tags[(int)$file->id] ?? []
		), $files);
	}

	/** GET /file/:uuid */
	public function Get($params): array {
		$key = $this->GetRequestKey();
		$file = FileControl::GetForKeyByUuid($key, (string)$params["uuid"]);
		return FileResponse::Owner($file);
	}

	/**
	 * POST /file/:uuid/renditions -- makes the renditions again from the key's current profile,
	 * with new URLs. Queued, never inline: the response says `pending`. Any job already queued or
	 * running for the file is superseded, so calling it twice leaves one. A file that isn't an
	 * image has nothing to regenerate (status `none`). 5004 when the instance has no processor.
	 */
	public function Regenerate($params): array {
		$key = $this->GetRequestKey();
		$file = FileControl::GetForKeyByUuid($key, (string)$params["uuid"]);
		FileProcessing::Regenerate($file);
		return FileResponse::Owner($file->Reload());
	}

	/**
	 * POST /file/:uuid/rotate -- new URLs for the file and its renditions; the old ones stop
	 * working at the origin (FileRotation). Not idempotent by design: each call rotates again.
	 * 4093 when a processing run committed in between (retry), 5001 when a copy failed.
	 */
	public function Rotate($params): array {
		$key = $this->GetRequestKey();
		$file = FileControl::GetForKeyByUuid($key, (string)$params["uuid"]);
		return FileResponse::Owner(FileRotation::Rotate($file));
	}

	/** PUT /file/:uuid -- renames the display name only (POST /file/:uuid/renditions reprocesses) */
	public function Update($params): array {
		$key = $this->GetRequestKey();
		$file = FileControl::GetForKeyByUuid($key, (string)$params["uuid"]);
		$data = $this->GetPut();
		if(!empty($data["name"])) {
			$file->name = trim($data["name"]);
			$file->Update();
		}
		return FileResponse::Owner($file);
	}

	/** DELETE /file/:uuid -- deletes from storage (file + renditions) and adjusts the key's usage */
	public function Delete($params = false): array {
		$key = $this->GetRequestKey();
		$file = FileControl::GetForKeyByUuid($key, (string)$params["uuid"]);
		FileControl::Delete($file, $key);
		return ["deleted" => true];
	}

	/** POST /file/:uuid/tags -- body: name */
	public function AttachTag($params): array {
		$key = $this->GetRequestKey();
		$file = FileControl::GetForKeyByUuid($key, (string)$params["uuid"]);
		$data = $this->GetPost();
		$tag = TagControl::GetOrCreate($data["name"] ?? "");
		FileTagControl::Attach((int)$file->id, (int)$tag->id);
		return FileResponse::Owner($file);
	}

	/** DELETE /file/:uuid/tags/:tag -- :tag is the tag name */
	public function DetachTag($params): array {
		$key = $this->GetRequestKey();
		$file = FileControl::GetForKeyByUuid($key, (string)$params["uuid"]);
		$tag = TagControl::GetRowWhere(["name" => $params["tag"] ?? ""]);
		if($tag === null) {
			ErrorCodes::Instance()->ThrowException(4044);
		}
		FileTagControl::Detach((int)$file->id, (int)$tag->id);
		return FileResponse::Owner($file);
	}

}
