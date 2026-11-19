<?php
// PHPUnit bootstrap: the app's classes and an empty "test" environment. configs/magrathea.conf is
// never read and no DB is connected, so a test can't touch a real instance by accident. A test
// that needs config keys or the DB sets them up itself (TestConfig::Set, Database::Mock) and
// puts the empty environment back in tearDown().
require __DIR__."/../src/_autoload.php";
require __DIR__."/Support/TestConfig.php";
require __DIR__."/Support/ScratchDir.php";
require __DIR__."/Support/FakeIdempotencyStore.php";
require __DIR__."/Support/TlsTestServer.php";
require __DIR__."/Support/FakeDatabase.php";
require __DIR__."/Support/FakeStorage.php";

MagratheaExplorer\Tests\Support\TestConfig::Set();
