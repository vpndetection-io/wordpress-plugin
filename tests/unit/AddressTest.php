<?php

declare(strict_types=1);

namespace VPNDetection\WordPress\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VPNDetection\WordPress\Address;

final class AddressTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function spellings(): iterable
    {
        yield 'IPv4' => ['203.0.113.7', '203.0.113.7'];
        yield 'IPv4 with a port' => ['203.0.113.7:8080', '203.0.113.7'];
        yield 'IPv6, compressed' => ['2001:DB8:0:0::1', '2001:db8::1'];
        yield 'IPv6 in brackets with a port' => ['[2001:db8::1]:443', '2001:db8::1'];
        yield 'IPv4-mapped IPv6' => ['::ffff:45.83.91.1', '45.83.91.1'];
        yield 'surrounding space' => [' 45.83.91.1 ', '45.83.91.1'];
        yield 'a host name' => ['example.com', null];
        yield 'an octet out of range' => ['256.1.1.1', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('spellings')]
    public function testNormalize(string $given, ?string $want): void
    {
        $this->assertSame($want, Address::normalize($given));
    }

    public function testBlocks(): void
    {
        $blocks = Address::blocks(['198.51.100.0/24', '2001:db8::/32', '45.83.91.1', 'not a block', '10.0.0.0/33']);
        $this->assertCount(3, $blocks);
        $this->assertTrue(Address::inBlocks('198.51.100.255', $blocks));
        $this->assertFalse(Address::inBlocks('198.51.101.0', $blocks));
        $this->assertTrue(Address::inBlocks('2001:db8:ffff::1', $blocks));
        $this->assertFalse(Address::inBlocks('2001:db9::1', $blocks));
        $this->assertTrue(Address::inBlocks('45.83.91.1', $blocks));
        $this->assertFalse(Address::inBlocks('45.83.91.2', $blocks));
        $this->assertTrue(Address::inBlocks('::ffff:198.51.100.9', $blocks), 'a mapped IPv4 is judged as the IPv4');
    }

    /** A prefix that is not a whole number of bytes masks inside the byte. */
    public function testPartialByteMask(): void
    {
        $blocks = Address::blocks(['172.16.0.0/12']);
        $this->assertTrue(Address::inBlocks('172.31.255.255', $blocks));
        $this->assertFalse(Address::inBlocks('172.32.0.0', $blocks));
        $this->assertFalse(Address::inBlocks('172.15.255.255', $blocks));
    }

    public function testZeroPrefixCoversTheFamily(): void
    {
        $blocks = Address::blocks(['0.0.0.0/0']);
        $this->assertTrue(Address::inBlocks('8.8.8.8', $blocks));
        $this->assertFalse(Address::inBlocks('2001:db8::1', $blocks));
    }
}
