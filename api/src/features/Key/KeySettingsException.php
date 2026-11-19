<?php

namespace MagratheaExplorer\Key;

/** A refused admin form field (KeySettings::FromForm()). $field is the form field's name. */
class KeySettingsException extends \InvalidArgumentException {

	public function __construct(public readonly string $field, string $message) {
		parent::__construct($message);
	}

}
