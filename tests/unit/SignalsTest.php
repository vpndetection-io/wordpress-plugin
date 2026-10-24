<?php

declare(strict_types=1);

namespace VPNDetection\WordPress\Tests;

use PHPUnit\Framework\TestCase;
use VPNDetection\WordPress\Signals;
use VPNDetection\WordPress\Vendor\VPNDetection\Middleware\Condition;
use VPNDetection\WordPress\Vendor\VPNDetection\Result;
use VPNDetection\WordPress\Vendor\VPNDetection\ProxyDetail;

final class SignalsTest extends TestCase
{
    public function testConditionIsAnyOf(): void
    {
        $this->assertSame(
            [['isVpn' => true], ['isTor' => true]],
            Signals::condition(['vpn', 'tor'], 1),
        );
    }

    public function testSightingsBoundOnlyTheCountedProxies(): void
    {
        $this->assertSame(
            [['isResproxy' => true, 'resproxy' => ['hits' => ['gte' => 3]]], ['isHosting' => true]],
            Signals::condition(['resproxy', 'hosting'], 3),
        );
    }

    /** The condition is the SDK's own language, so the SDK's matcher decides it. */
    public function testTheSdkMatchesIt(): void
    {
        $seenTwice = new Result(
            ip: '5.6.7.8',
            isVpn: false,
            isBogon: false,
            isResproxy: true,
            resproxy: new ProxyDetail(provider: 'ippeak', hits: 2),
        );
        $this->assertTrue(Condition::matches(Signals::condition(['resproxy'], 2), $seenTwice));
        $this->assertFalse(Condition::matches(Signals::condition(['resproxy'], 3), $seenTwice));
        $this->assertFalse(Condition::matches(Signals::condition(['vpn'], 1), $seenTwice));
    }

    public function testAKeylessAnswerLacksAPaidSignal(): void
    {
        $keyless = new Result(ip: '45.83.91.1', isVpn: true, isBogon: false);
        $this->assertSame(['isHosting'], Condition::missingMembers(Signals::condition(['hosting'], 1), $keyless));
    }

    public function testWhichPlansServeWhat(): void
    {
        $this->assertTrue(Signals::served('vpn', null));
        $this->assertFalse(Signals::served('tor', null));
        $this->assertTrue(Signals::served('tor', 'free'));
        $this->assertFalse(Signals::served('hosting', 'free'));
        $this->assertTrue(Signals::served('hosting', 'starter'));
    }

    public function testEverySignalIsAResultMember(): void
    {
        foreach (Signals::ALL as $signal => $def) {
            $this->assertTrue(property_exists(Result::class, $def['member']), "{$signal}'s member");
            $this->assertTrue(property_exists(Result::class, $def['detail']), "{$signal}'s detail");
        }
    }
}
