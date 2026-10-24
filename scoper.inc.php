<?php

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

// Every vendored package moves under the plugin's own namespace, so another plugin's copy of
// Guzzle, PSR or the SDK can never be the one this plugin loads. Composer's runtime classes stay
// global: the SDK reads its own version from Composer\InstalledVersions for its User-Agent.
return [
    'prefix' => 'VPNDetection\\WordPress\\Vendor',
    'finders' => [
        Finder::create()->files()->in('vendor')->exclude(['composer', 'bin'])
            ->name(['*.php', 'composer.json', 'LICENSE']),
        Finder::create()->append(['vendor/composer/installed.json']),
    ],
    'exclude-namespaces' => ['Composer'],
];
