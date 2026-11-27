<?php

declare(strict_types=1);

namespace VPNDetection\WordPress\Tests;

use PHPUnit\Framework\TestCase;
use VPNDetection\WordPress\Areas\Pages;
use VPNDetection\WordPress\Vendor\VPNDetection\BotDetail;
use VPNDetection\WordPress\Vendor\VPNDetection\Result;

final class PagesTest extends TestCase
{
    /** A paid answer says whose bot an address is and whether its operator vouches for it. */
    public function testAVouchedSearchCrawlerPasses(): void
    {
        $this->assertTrue(Pages::vouchedCrawler(self::bot(['search_engine'], true)));
        $this->assertTrue(Pages::vouchedCrawler(self::bot(['ai_search', 'search_engine'], true)));
    }

    public function testOtherBotsAreJudgedAsAnyVisitor(): void
    {
        $this->assertFalse(Pages::vouchedCrawler(self::bot(['search_engine'], false)), 'a claim nobody vouches for');
        $this->assertFalse(Pages::vouchedCrawler(self::bot(['ai_training'], true)), 'a vouched bot that is no search engine');
        $this->assertFalse(Pages::vouchedCrawler(self::bot(['scanner'], true)), 'a declared scanner');
    }

    /** is_bot alone says nothing of whose bot it is, and an answer without a key, or past the trial, has neither. */
    public function testAKeylessAnswerLetsNoCrawlerThrough(): void
    {
        $this->assertFalse(Pages::vouchedCrawler(new Result(ip: '5.255.253.1', isVpn: false, isBogon: false, isBot: true)));
        $keyless = new Result(ip: '5.255.253.1', isVpn: false, isBogon: false, isTor: false, isRelay: false);
        $this->assertFalse(Pages::vouchedCrawler($keyless));
        $this->assertFalse(Pages::vouchedCrawler(new Result(ip: '81.2.69.142', isVpn: false, isBogon: false, isBot: false)));
    }

    /** @param list<string> $categories */
    private static function bot(array $categories, bool $verified): Result
    {
        return new Result(ip: '5.255.253.1', isVpn: false, isBogon: false, isBot: true, bot: new BotDetail(
            provider: 'yandex',
            providerName: 'Yandex',
            agents: ['YandexBot'],
            categories: $categories,
            verified: $verified,
            identity: $verified ? 'verified' : 'declared',
            verifiedBy: $verified ? 'rdns' : null,
            confidence: 'high',
        ));
    }
}
