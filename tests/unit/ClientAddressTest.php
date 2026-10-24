<?php

declare(strict_types=1);

namespace VPNDetection\WordPress\Tests;

use PHPUnit\Framework\TestCase;
use VPNDetection\WordPress\Address;
use VPNDetection\WordPress\ClientAddress;

final class ClientAddressTest extends TestCase
{
    private const VISITOR = '45.83.91.1';
    private const CLOUDFLARE_EDGE = '172.70.1.1';

    public function testDirectBelievesNoHeader(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => self::VISITOR];
        $this->assertSame('10.0.0.2', (new ClientAddress(ClientAddress::DIRECT))->resolve($server));
    }

    public function testAutoBelievesCloudflareFromCloudflare(): void
    {
        $server = ['REMOTE_ADDR' => self::CLOUDFLARE_EDGE, 'HTTP_CF_CONNECTING_IP' => self::VISITOR];
        $this->assertSame(self::VISITOR, (new ClientAddress(ClientAddress::AUTO))->resolve($server));
    }

    public function testAutoRefusesCloudflareHeaderFromAnyoneElse(): void
    {
        $server = ['REMOTE_ADDR' => '81.2.69.142', 'HTTP_CF_CONNECTING_IP' => self::VISITOR];
        $this->assertSame('81.2.69.142', (new ClientAddress(ClientAddress::AUTO))->resolve($server));
    }

    public function testAutoRefusesForwardedForFromAPublicPeer(): void
    {
        $server = ['REMOTE_ADDR' => '81.2.69.142', 'HTTP_X_FORWARDED_FOR' => self::VISITOR];
        $this->assertSame('81.2.69.142', (new ClientAddress(ClientAddress::AUTO))->resolve($server));
    }

    public function testAutoWalksForwardedForFromThePrivateNetwork(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, ' . self::VISITOR . ', 10.0.0.9'];
        $this->assertSame(self::VISITOR, (new ClientAddress(ClientAddress::AUTO))->resolve($server));
    }

    /** What a visitor writes into the header themselves sits left of what the proxy appended. */
    public function testAForgedLeftEntryIsNeverReached(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '81.2.69.142, ' . self::VISITOR];
        $this->assertSame(self::VISITOR, (new ClientAddress(ClientAddress::AUTO))->resolve($server));
    }

    public function testAutoFallsBackToRealIpBehindAPrivateProxy(): void
    {
        $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_REAL_IP' => self::VISITOR];
        $this->assertSame(self::VISITOR, (new ClientAddress(ClientAddress::AUTO))->resolve($server));
    }

    // Public addresses throughout: a documentation range (192.0.2.0/24, 198.51.100.0/24) is a bogon,
    // which the resolver trusts as a private hop whatever the settings say.
    public function testCustomTrustsOnlyTheNamedProxies(): void
    {
        $resolver = new ClientAddress(ClientAddress::CUSTOM, 'True-Client-IP', Address::blocks(['130.211.0.0/22']));
        $this->assertSame(
            self::VISITOR,
            $resolver->resolve(['REMOTE_ADDR' => '130.211.0.10', 'HTTP_TRUE_CLIENT_IP' => self::VISITOR]),
        );
        $this->assertSame(
            '81.2.69.142',
            $resolver->resolve(['REMOTE_ADDR' => '81.2.69.142', 'HTTP_TRUE_CLIENT_IP' => self::VISITOR]),
        );
    }

    public function testCustomForwardedForStopsAtTheFirstUntrustedHop(): void
    {
        $resolver = new ClientAddress(ClientAddress::CUSTOM, 'X-Forwarded-For', Address::blocks(['130.211.0.0/22']));
        $server = ['REMOTE_ADDR' => '130.211.0.10', 'HTTP_X_FORWARDED_FOR' => '81.2.69.142, ' . self::VISITOR . ', 130.211.0.11'];
        $this->assertSame(self::VISITOR, $resolver->resolve($server));
    }

    public function testCloudflareModeIgnoresForwardedFor(): void
    {
        $server = ['REMOTE_ADDR' => self::CLOUDFLARE_EDGE, 'HTTP_X_FORWARDED_FOR' => self::VISITOR];
        $this->assertSame(self::CLOUDFLARE_EDGE, (new ClientAddress(ClientAddress::CLOUDFLARE))->resolve($server));
    }

    public function testAHeaderNamingNoAddressIsIgnored(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_REAL_IP' => 'unknown'];
        $this->assertSame('10.0.0.2', (new ClientAddress(ClientAddress::AUTO))->resolve($server));
    }

    public function testNoPeerIsNoAddress(): void
    {
        $this->assertNull((new ClientAddress(ClientAddress::AUTO))->resolve([]));
    }
}
