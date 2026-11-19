<?php

namespace MagratheaExplorer\File;

use MagratheaExplorer\Key\Key;

/**
 * How one upload is stored. Replaces UploadPipeline::Handle()'s positional bools, which kept
 * growing. Invariant: verbatim implies noConvert (a verbatim file is never converted), so
 * `files.no_convert` is 1 on every verbatim row.
 */
final class UploadOptions {

	public readonly bool $verbatim;
	public readonly bool $noConvert;
	/** @var string[] tag names to attach after a successful upload */
	public readonly array $tagNames;
	/** false lets a bulk import run past the key's usage caps (see Key::AssertCanImport()) */
	public readonly bool $enforceQuota;
	/** false = never process in the request, always queue (bulk import: many files in one request) */
	public readonly bool $inlineProcessing;
	/**
	 * sha256 of the uploaded bytes, when the caller already has it (FileApi hashes an upload with
	 * an Idempotency-Key). Stored as `files.sha256` when nothing changes the bytes (verbatim, or
	 * not an image), instead of hashing them again. Never trusted for anything else.
	 */
	public readonly ?string $uploadSha256;
	/**
	 * Called with the new File inside the transaction that inserts its row: throwing rolls the
	 * insert back. FileApi records it as the Idempotency-Key's resource (IdempotencyClaim::Recorder()).
	 */
	public readonly ?\Closure $onStored;
	/**
	 * true = the upload's `tmp_name` is a scratch file the pipeline may take over: it's processed
	 * where it is and deleted afterwards, instead of being copied into a working copy first. A
	 * `source_url` fetch sets it (FileApi), so a 150 MB download isn't on disk twice.
	 */
	public readonly bool $consumeSource;
	/**
	 * The file types (image, audio, video, document, other) this upload may be, by what the
	 * content sniffs as; null = any. An upload token sets it (POST /upload/:token): anything else
	 * is a 4151, before anything is stored.
	 * @var ?string[]
	 */
	public readonly ?array $allowedFileTypes;

	public function __construct(bool $verbatim = false, bool $noConvert = false, array $tagNames = [], bool $enforceQuota = true, bool $inlineProcessing = true, ?string $uploadSha256 = null, ?\Closure $onStored = null, bool $consumeSource = false, ?array $allowedFileTypes = null) {
		$this->verbatim = $verbatim;
		$this->noConvert = $verbatim || $noConvert;
		$this->tagNames = $tagNames;
		$this->enforceQuota = $enforceQuota;
		$this->inlineProcessing = $inlineProcessing;
		$this->uploadSha256 = $uploadSha256;
		$this->onStored = $onStored;
		$this->consumeSource = $consumeSource;
		$this->allowedFileTypes = $allowedFileTypes;
	}

	/**
	 * The one place request flags meet the key's defaults; null = the flag wasn't sent.
	 * An explicit `verbatim` always wins, `verbatim=0` included on a verbatim-by-default key.
	 */
	public static function Resolve(Key $key, ?bool $verbatim, ?bool $noConvert, array $tagNames = [], bool $enforceQuota = true, bool $inlineProcessing = true, ?string $uploadSha256 = null, ?\Closure $onStored = null, bool $consumeSource = false, ?array $allowedFileTypes = null): self {
		return new self($verbatim ?? $key->VerbatimByDefault(), $noConvert ?? false, $tagNames, $enforceQuota, $inlineProcessing, $uploadSha256, $onStored, $consumeSource, $allowedFileTypes);
	}

}
