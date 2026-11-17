<?php
// Shared bootstrap: setup only. It prints nothing and catches nothing -- the entry
// points log a failure and answer a generic error. Also loaded by vendor/bin/magrathea
// (composer.json's extra.magrathea.bootstrap), so every path here is absolute.
// The code folders live in _autoload.php, which the tests load without this file.
require __DIR__."/_autoload.php";

Magrathea\MagratheaPHP::Instance()
	->Prod()
	->Load()
	->Connect();
