<?php

// The unit suite runs the plugin's own classes without WordPress: the built plugin's autoloader
// gives them the scoped SDK, and the few WordPress functions they touch are stood in for here.

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

require dirname(__DIR__) . '/vendor/autoload.php';
require glob(dirname(__DIR__) . '/dist/*/vendor/autoload.php')[0];
