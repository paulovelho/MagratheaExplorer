<?php

namespace MagratheaExplorer\File;

/** What SafeFetcher::Fetch() downloaded. The caller owns $path: it deletes it (UploadPipeline does, with UploadOptions::$consumeSource). */
final class FetchedFile {

	/**
	 * @param ?string $contentDisposition the final response's header, as sent (RemoteFileName reads it)
	 * @param SourceUrl $finalUrl where the body came from, after redirects
	 */
	public function __construct(
		public readonly string $path,
		public readonly int $size,
		public readonly ?string $contentDisposition,
		public readonly SourceUrl $finalUrl,
	) {}

}
