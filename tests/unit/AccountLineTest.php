<?php

declare(strict_types=1);

namespace VPNDetection\WordPress\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VPNDetection\WordPress\Admin\AccountLine;
use VPNDetection\WordPress\Vendor\VPNDetection\Entitlement;
use VPNDetection\WordPress\Vendor\VPNDetection\EntitlementApikey;
use VPNDetection\WordPress\Vendor\VPNDetection\EntitlementPlan;
use VPNDetection\WordPress\Vendor\VPNDetection\EntitlementUsage;

final class AccountLineTest extends TestCase
{
    private const NOW = '2026-11-28T12:00:00Z';

    private function account(string $plan, int $requests, int $quota, string $windowEnd): Entitlement
    {
        return new Entitlement(
            orgId: '85bb51e4-2eb6-4a31-8e4d-02ba8b98fe61',
            apikey: new EntitlementApikey(id: '0ab424cc-7619-4dad-b027-afacdc2cedb0', expires: null, allowedCidrs: []),
            plan: new EntitlementPlan(key: $plan, tier: $plan === 'free' ? 'free' : 'max', name: ucfirst($plan)),
            usage: new EntitlementUsage(
                requests: $requests,
                quota: $quota,
                hardLimit: null,
                windowStart: new DateTimeImmutable('2026-11-28T00:00:00Z'),
                windowEnd: new DateTimeImmutable($windowEnd),
            ),
        );
    }

    private function line(Entitlement $account): string
    {
        return AccountLine::of($account, strtotime(self::NOW));
    }

    /** A free key's usage is the day's checks with every signal, which resets when the window ends. */
    public function testAFreeKeyCountsTheDaysChecksWithEverySignalToTheReset(): void
    {
        $this->assertSame(
            '37 of 600 checks with every signal today, resetting at 00:00.',
            $this->line($this->account('free', 37, 600, '2026-11-29T00:00:00Z')),
        );
    }

    public function testASpentDayGoesOnWithTheCoreSignalsUntilTheReset(): void
    {
        $this->assertSame(
            'Today\'s checks with every signal are used, so checks go on with the core signals until 00:00.',
            $this->line($this->account('free', 600, 600, '2026-11-29T00:00:00Z')),
        );
    }

    /** An entitlement cached past its window's end describes a day that is over. */
    public function testAnEntitlementReadBeforeTheResetSaysTheChecksHaveEverySignalAgain(): void
    {
        $this->assertSame(
            'A new day\'s checks have every signal again.',
            $this->line($this->account('free', 600, 600, '2026-11-28T00:00:00Z')),
        );
    }

    public function testAPaidPlanCountsItsPeriod(): void
    {
        $this->assertSame(
            '1,240 of 5,000,000 checks used this period, which resets on 2026-11-29.',
            $this->line($this->account('max-2026', 1240, 5000000, '2026-11-29T00:00:00Z')),
        );
    }
}
