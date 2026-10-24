<?php

// The unit suite runs the plugin's own classes without WordPress: the built plugin's autoloader
// gives them the scoped SDK, and the few WordPress functions they touch are stood in for here.

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/dist/vpndetection/vendor/autoload.php';

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function sanitize_key(string $key): string
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
}
