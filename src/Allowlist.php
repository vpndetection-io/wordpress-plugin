<?php

declare(strict_types=1);

namespace VPNDetection\WordPress;

defined('ABSPATH') || exit;

/**
 * Visitors who are never blocked: an address, a block of them, or a whole network by its AS number,
 * one per line, each optionally followed by a `#` comment.
 *
 * An address or a block is matched before any lookup, so it costs nothing. A network is matched on
 * the answer's AS, which every keyed lookup carries.
 */
final class Allowlist
{
    /**
     * @param list<array{0: string, 1: string}> $blocks
     * @param array<int, true> $asns
     */
    private function __construct(private readonly array $blocks, private readonly array $asns)
    {
    }

    public static function parse(string $text): self
    {
        $blocks = [];
        $asns = [];
        foreach (Settings::lines($text) as $entry) {
            $asn = self::asn($entry);
            if ($asn !== null) {
                $asns[$asn] = true;
                continue;
            }
            $block = Address::block($entry);
            if ($block !== null) {
                $blocks[] = $block;
            }
        }
        return new self($blocks, $asns);
    }

    /**
     * The entries that are neither an address, a block nor an AS number.
     *
     * @return list<string>
     */
    public static function invalid(string $text): array
    {
        $bad = [];
        foreach (Settings::lines($text) as $entry) {
            if (self::asn($entry) === null && Address::block($entry) === null) {
                $bad[] = $entry;
            }
        }
        return $bad;
    }

    public function coversAddress(string $ip): bool
    {
        return Address::inBlocks($ip, $this->blocks);
    }

    public function coversAsn(int $asn): bool
    {
        return $asn > 0 && isset($this->asns[$asn]);
    }

    public function hasAsns(): bool
    {
        return $this->asns !== [];
    }

    private static function asn(string $entry): ?int
    {
        if (preg_match('/^AS\s?(\d{1,10})$/i', $entry, $m) !== 1) {
            return null;
        }
        $asn = (int) $m[1];
        return $asn > 0 && $asn <= 4294967295 ? $asn : null;
    }
}
