<?php

namespace MagratheaExplorer\Processing;

/**
 * What one processor run produced, already checked by ProcessorRunner: nothing in here comes
 * from the processor unvalidated.
 *
 * - `ok` false: the run failed (non-zero exit, killed, timed out, unreadable output); `error`
 *   says why and nothing else is set. A failure never says anything about the file's
 *   renditions: none are used.
 * - `ok` true: the processor finished. `handled` false = this engine has no handler for the
 *   type (processing status `none`, not an error). `renders` false = the handler gives
 *   properties only (SVG). Renditions that failed are in `errors`; the rest are in `renditions`.
 */
final class ProcessorResult {

	/**
	 * @param array<string, mixed> $properties every ProcessorRunner::PROPERTY_TYPES key, null = unknown
	 * @param array<string, array{path: string, width: int, height: int, mime_type: string, extension: string, size: int}> $renditions by name
	 * @param array<string, string> $errors rendition name => reason
	 */
	public function __construct(
		public readonly bool $ok,
		public readonly ?string $error,
		public readonly bool $handled,
		public readonly bool $renders,
		public readonly array $properties,
		public readonly array $renditions,
		public readonly array $errors,
		public readonly ?string $engine,
		public readonly float $seconds,
		public readonly ?int $peakRssKb,
	) {}

	public static function Failed(string $error, float $seconds = 0.0, ?int $peakRssKb = null): self {
		return new self(false, $error, false, false, ProcessorRunner::EmptyProperties(), [], [], null, $seconds, $peakRssKb);
	}

}
