<?php

declare(strict_types=1);

namespace VPNDetection\WordPress\Tests;

use PHPUnit\Framework\TestCase;
use VPNDetection\WordPress\Claims;
use VPNDetection\WordPress\Vendor\VPNDetection\Middleware\Core;

final class ClaimsTest extends TestCase
{
    /** Every agent the plugin plans to send is one the SDK's core sends, or its verdict never comes back. */
    public function testNamesTheCrawlersTheCoreSends(): void
    {
        $agents = [
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'Mozilla/5.0 (compatible; ChatGPT-User/1.0; +https://openai.com/bot)',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Google Web Preview',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/142.0.0.0 Safari/537.36',
            'Datadog Agent/7.60.0',
            'Mozilla/5.0 (compatible; scanner)',
            'Stripe/1.0 (+https://stripe.com/docs/webhooks)',
        ];
        foreach ($agents as $agent) {
            $this->assertSame(Core::namesCrawler($agent), Claims::namesCrawler($agent), $agent);
        }
    }

    public function testBoundsAnAddressAsTheCoreDoes(): void
    {
        $this->assertSame(Core::MAX_AGENTS_PER_ADDRESS, Claims::MAX_AGENTS);
    }
}
