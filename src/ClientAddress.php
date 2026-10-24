<?php

declare(strict_types=1);

namespace VPNDetection\WordPress;

use VPNDetection\WordPress\Vendor\VPNDetection\Bogon;

defined('ABSPATH') || exit;

/**
 * Which address a request came from.
 *
 * A forwarding header is believed only from a peer the site trusts to write it: Cloudflare's edge for
 * CF-Connecting-IP, the site's own network or the configured proxies for the rest. Anyone can send
 * X-Forwarded-For, so read from anyone else it would let a visitor name any address they liked.
 */
final class ClientAddress
{
    public const AUTO = 'auto';
    public const DIRECT = 'direct';
    public const CLOUDFLARE = 'cloudflare';
    public const CUSTOM = 'custom';

    /** The headers a proxy may name the visitor in, and the server variable each arrives as. */
    public const HEADERS = [
        'X-Forwarded-For' => 'HTTP_X_FORWARDED_FOR',
        'X-Real-IP' => 'HTTP_X_REAL_IP',
        'True-Client-IP' => 'HTTP_TRUE_CLIENT_IP',
        'CF-Connecting-IP' => 'HTTP_CF_CONNECTING_IP',
    ];

    /** @var list<array{0: string, 1: string}>|null */
    private static ?array $cloudflare = null;

    /** @param list<array{0: string, 1: string}> $trusted */
    public function __construct(
        private readonly string $mode,
        private readonly string $header = 'X-Forwarded-For',
        private readonly array $trusted = [],
    ) {
    }

    public static function fromSettings(Settings $settings): self
    {
        return new self(
            $settings->get('proxy'),
            $settings->get('proxy_header'),
            Address::blocks(Settings::lines($settings->get('proxy_trusted'))),
        );
    }

    /** @param array<string, string> $server */
    public function resolve(array $server): ?string
    {
        $peer = Address::normalize($server['REMOTE_ADDR'] ?? '');
        if ($peer === null) {
            return null;
        }
        switch ($this->mode) {
            case self::DIRECT:
                return $peer;
            case self::CLOUDFLARE:
                return $this->named($server, 'CF-Connecting-IP', $peer, self::cloudflare()) ?? $peer;
            case self::CUSTOM:
                return $this->named($server, $this->header, $peer, $this->trusted) ?? $peer;
        }
        // Auto: Cloudflare's edge proves itself by its address, and a proxy on the site's own
        // network by its private one. Anything else connected directly.
        $cloudflare = $this->named($server, 'CF-Connecting-IP', $peer, self::cloudflare());
        if ($cloudflare !== null) {
            return $cloudflare;
        }
        if (!Bogon::isBogon($peer)) {
            return $peer;
        }
        return $this->named($server, 'X-Forwarded-For', $peer, [])
            ?? $this->named($server, 'X-Real-IP', $peer, [])
            ?? $peer;
    }

    /**
     * What a header says the visitor is, when the peer may say so; null when it may not or the header
     * names no address. X-Forwarded-For is read from the right, each hop the site trusts handing over
     * to the address it says it forwarded for: the first hop it does not trust is the visitor.
     *
     * A private address is a hop on the site's own network and is always trusted.
     *
     * @param array<string, string> $server
     * @param list<array{0: string, 1: string}> $trusted
     */
    private function named(array $server, string $header, string $peer, array $trusted): ?string
    {
        $raw = trim($server[self::HEADERS[$header] ?? ''] ?? '');
        if ($raw === '' || !self::trusts($peer, $trusted)) {
            return null;
        }
        if ($header !== 'X-Forwarded-For') {
            return Address::normalize($raw);
        }
        $hops = array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
        $visitor = null;
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            $hop = Address::normalize($hops[$i]);
            if ($hop === null) {
                break;
            }
            $visitor = $hop;
            if (!self::trusts($hop, $trusted)) {
                break;
            }
        }
        return $visitor;
    }

    /** @param list<array{0: string, 1: string}> $trusted */
    private static function trusts(string $ip, array $trusted): bool
    {
        return Bogon::isBogon($ip) || Address::inBlocks($ip, $trusted);
    }

    /** @return list<array{0: string, 1: string}> */
    private static function cloudflare(): array
    {
        return self::$cloudflare ??= Address::blocks(Ranges::CLOUDFLARE);
    }
}
