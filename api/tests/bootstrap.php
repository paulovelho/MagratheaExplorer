<?php
// PHPUnit bootstrap: the app's classes and an empty "test" environment. configs/magrathea.conf is
// never read and no DB is connected, so a test can't touch a real instance by accident. A test
// that needs config keys or the DB sets them up itself (Config::SetConfig, Database::Mock).
require __DIR__."/../src/_autoload.php";

Magrathea\Config::Instance()
	->SetConfig(["general" => ["use_environment" => "test"], "test" => []])
	->SetEnvironment("test");
