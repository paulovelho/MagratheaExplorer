<?php

namespace MagratheaExplorer\Processing;

use Magrathea\MagratheaModel;

/**
 * One processing run of one file, queued at upload or by a regenerate. Lifecycle:
 *   queued → running (claimed, `attempts` + 1, leased) → done | failed, or back to queued
 *   (a failed run, attempts left); queued/running → superseded (a regenerate replaced it).
 * A lease that runs out (the worker died) makes a running job claimable again. The specs and
 * the rendition tokens are fixed at enqueue, so a retry writes the same objects and a profile
 * edit can't reach a job already queued.
 */
class ProcessingJob extends MagratheaModel {

	const QUEUED = "queued";
	const RUNNING = "running";
	const DONE = "done";
	const FAILED = "failed";
	const SUPERSEDED = "superseded";

	protected $dbTable = "processing_jobs";
	protected $dbPk = "id";
	protected $dbValues = [
		"id" => "int",
		"file_id" => "int",
		"status" => "string",
		"attempts" => "int",
		"lease_until" => "datetime",
		// JSON: the RenditionProfile specs, copied at enqueue
		"profile" => "text",
		// JSON: {rendition name: token}
		"tokens" => "text",
		"error" => "string",
	];

	public $id, $file_id, $status, $attempts, $lease_until, $profile, $tokens, $error;

	/** The specs, checked again: the column is JSON anyone with SQL access can edit. */
	public function Specs(): array {
		return RenditionProfile::Parse((string)$this->profile);
	}

	/** @return array<string, string> rendition name => token */
	public function Tokens(): array {
		$decoded = json_decode((string)$this->tokens, true);
		if(!is_array($decoded)) return [];
		$tokens = [];
		foreach($decoded as $name => $token) {
			if(is_string($token)) $tokens[(string)$name] = $token;
		}
		return $tokens;
	}

}
