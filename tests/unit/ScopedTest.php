<?php

declare(strict_types=1);

namespace VPNDetection\WordPress\Tests;

use PHPUnit\Framework\TestCase;
use VPNDetection\WordPress\Plugin;

final class ScopedTest extends TestCase
{
    public function testTheSdkLoadsUnderThePluginsNamespace(): void
    {
        $this->assertSame(
            'VPNDetection\\WordPress\\Vendor\\VPNDetection\\Client',
            Plugin::client()::class,
        );
    }
}
