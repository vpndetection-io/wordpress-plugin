<?php

declare(strict_types=1);

namespace VPNDetection\WordPress;

use VPNDetection\WordPress\Vendor\VPNDetection\Middleware\Core;

defined('ABSPATH') || exit;

/**
 * The crawler agents each address was looked up with, kept beside the answers. Only a crawler's agent
 * rides a lookup, and one address is looked up with at most MAX_AGENTS of them while its answers are
 * cached; past that, a claim is judged spoofed where one of the address's earlier ones was.
 */
final class Claims
{
    public const MAX_AGENTS = Core::MAX_AGENTS_PER_ADDRESS;

    public function __construct(private readonly Store $store, private readonly int $ttl)
    {
    }

    /** Whether an agent names a crawler, by the SDK's terms: the core sends no other for a verdict. */
    public static function namesCrawler(string $agent): bool
    {
        return Core::namesCrawler($agent);
    }

    /**
     * Whether the lookup may carry this crawler agent, and whether a claim the address was looked up with
     * earlier read spoofed.
     *
     * @return array{bool, bool}
     */
    public function plan(string $ip, string $agent): array
    {
        $seen = $this->seen($ip);
        $tag = self::tag($agent);
        $sent = in_array($tag, $seen['agents'], true) || count($seen['agents']) < self::MAX_AGENTS;
        return [$sent, $seen['spoofed']];
    }

    /** Keeps an agent the address was looked up with, and whether its answer read spoofed. */
    public function note(string $ip, string $agent, ?string $verdict): void
    {
        $seen = $this->seen($ip);
        $tag = self::tag($agent);
        $known = in_array($tag, $seen['agents'], true);
        $spoofed = $seen['spoofed'] || $verdict === 'spoofed';
        if ($known && $spoofed === $seen['spoofed']) {
            return;
        }
        $agents = $known ? $seen['agents'] : [...$seen['agents'], $tag];
        $this->store->set(self::key($ip), ['agents' => $agents, 'spoofed' => $spoofed], $this->ttl);
    }

    /** @return array{agents: list<string>, spoofed: bool} */
    private function seen(string $ip): array
    {
        $seen = $this->store->get(self::key($ip));
        if (!is_array($seen)) {
            return ['agents' => [], 'spoofed' => false];
        }
        $agents = is_array($seen['agents'] ?? null) ? array_values(array_filter($seen['agents'], 'is_string')) : [];
        return ['agents' => $agents, 'spoofed' => ($seen['spoofed'] ?? false) === true];
    }

    private static function key(string $ip): string
    {
        return 'agents_' . md5($ip);
    }

    private static function tag(string $agent): string
    {
        return substr(hash('sha256', $agent), 0, 16);
    }
}
