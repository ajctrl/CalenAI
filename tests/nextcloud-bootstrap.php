<?php

declare(strict_types=1);

// Use the public API and calendar library shipped in the server under test.
$loader = require __DIR__ . '/../vendor/autoload.php';
$loader->setPsr4('OCP\\', '/var/www/html/lib/public');
$loader->setPsr4('Sabre\\VObject\\', '/var/www/html/3rdparty/sabre/vobject/lib');
