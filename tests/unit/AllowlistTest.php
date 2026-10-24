<?php

declare(strict_types=1);

namespace VPNDetection\WordPress\Tests;

use PHPUnit\Framework\TestCase;
use VPNDetection\WordPress\Allowlist;

final class AllowlistTest extends TestCase
{
    private const TEXT = "203.0.113.7\n198.51.100.0/24 # office\n  AS64500\nas 64501\n\n# a note\nnonsense\nAS0";

    public function testEntries(): void
    {
        $list = Allowlist::parse(self::TEXT);
        $this->assertTrue($list->coversAddress('203.0.113.7'));
        $this->assertTrue($list->coversAddress('198.51.100.200'));
        $this->assertFalse($list->coversAddress('198.51.101.1'));
        $this->assertTrue($list->coversAsn(64500));
        $this->assertTrue($list->coversAsn(64501));
        $this->assertFalse($list->coversAsn(0), 'AS0 is no network');
        $this->assertTrue($list->hasAsns());
    }

    public function testInvalidEntriesAreNamed(): void
    {
        $this->assertSame(['nonsense', 'AS0'], Allowlist::invalid(self::TEXT));
    }
}
