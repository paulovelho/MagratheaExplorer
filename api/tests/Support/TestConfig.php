<?php

namespace MagratheaExplorer\Tests\Support;

use Magrathea\Config;

/** magrathea.conf for tests: a "test" environment with $values as its section. */
class TestConfig {

	public static function Set(array $values = []): void {
		// Config::Get() treats an empty section as a missing environment and throws: never empty.
		$values["test_environment"] = "1";
		Config::Instance()
			->SetConfig(["general" => ["use_environment" => "test"], "test" => $values])
			->SetEnvironment("test");
	}

}
