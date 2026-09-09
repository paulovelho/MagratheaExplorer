<?php

// die; // remove this line once configuration below is correct for your environment

require "../vendor/autoload.php";

Magrathea2\MagratheaPHP::Instance()
    ->AppPath(realpath(dirname(__FILE__)))
    ->Dev()
    ->Load();
Magrathea2\Bootstrap\Start::Instance()->Load();


