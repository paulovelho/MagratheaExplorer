<?php

namespace MagratheaExplorer\Processing;

/**
 * What an upload does about processing, decided before its row is inserted
 * (FileProcessing::Prepare()) and carried out after (FileProcessing::Start()).
 */
final class ProcessingPlan {

	/**
	 * @param string $status the row's processing_status at insert: none, pending (a job follows) or failed
	 * @param ?array $properties header properties to store at insert (null = none read)
	 * @param array $specs the job's rendition specs (pending only)
	 * @param bool $inline run the job now, in the upload request
	 */
	public function __construct(
		public readonly string $status,
		public readonly ?string $error = null,
		public readonly ?array $properties = null,
		public readonly array $specs = [],
		public readonly bool $inline = false,
		public readonly ?ProcessorRunner $runner = null,
	) {}

	public static function None(?array $properties = null): self {
		return new self("none", null, $properties);
	}

	public static function Failed(string $error): self {
		return new self("failed", ProcessingJobControl::Truncate($error));
	}

	public function PropertiesJson(): ?string {
		return $this->properties === null ? null : json_encode((object)$this->properties, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

}
