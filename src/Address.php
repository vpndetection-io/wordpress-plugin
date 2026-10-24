<?php

declare(strict_types=1);

namespace VPNDetection\WordPress;

defined('ABSPATH') || exit;

/**
 * Addresses and the blocks they fall in.
 *
 * The same arithmetic the SDK's bogon check uses: `inet_pton`, and a bytewise AND of two binary
 * strings, so an IPv6 mask needs no 128-bit integer.
 */
final class Address
{
    /** The address in its canonical spelling, an IPv4-mapped IPv6 one as the IPv4 it carries. */
    public static function normalize(string $ip): ?string
    {
        $ip = trim($ip);
        // A port or brackets some proxies add: "[2001:db8::1]:443", "203.0.113.7:8080".
        if (preg_match('/^\[([0-9a-fA-F:.]+)\](?::\d+)?$/', $ip, $m) === 1) {
            $ip = $m[1];
        } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $ip, $m) === 1) {
            $ip = $m[1];
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        if (strlen($packed) === 16 && substr($packed, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
            $packed = substr($packed, 12);
        }
        $text = inet_ntop($packed);
        return $text === false ? null : $text;
    }

    /**
     * A block as [network, mask], both binary strings of the address's length, or null for text
     * that is no address or block. A bare address is its /32 or /128.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function block(string $cidr): ?array
    {
        [$ip, $bits] = array_pad(explode('/', trim($cidr), 2), 2, null);
        $ip = self::normalize((string) $ip);
        if ($ip === null) {
            return null;
        }
        $packed = (string) inet_pton($ip);
        $width = strlen($packed) * 8;
        if ($bits === null) {
            $prefix = $width;
        } elseif (ctype_digit($bits) && (int) $bits <= $width) {
            $prefix = (int) $bits;
        } else {
            return null;
        }
        $mask = str_repeat("\xff", intdiv($prefix, 8));
        if ($prefix % 8 !== 0) {
            $mask .= chr((0xff << (8 - $prefix % 8)) & 0xff);
        }
        $mask = str_pad($mask, strlen($packed), "\0");
        return [$packed & $mask, $mask];
    }

    /**
     * Whether an address falls in any of the blocks.
     *
     * @param list<array{0: string, 1: string}> $blocks
     */
    public static function inBlocks(string $ip, array $blocks): bool
    {
        $ip = self::normalize($ip);
        if ($ip === null) {
            return false;
        }
        $packed = (string) inet_pton($ip);
        foreach ($blocks as [$net, $mask]) {
            if (strlen($net) === strlen($packed) && ($packed & $mask) === $net) {
                return true;
            }
        }
        return false;
    }

    /**
     * Blocks from a list of CIDR strings, skipping any that does not parse.
     *
     * @param iterable<string> $cidrs
     * @return list<array{0: string, 1: string}>
     */
    public static function blocks(iterable $cidrs): array
    {
        $out = [];
        foreach ($cidrs as $cidr) {
            $block = self::block($cidr);
            if ($block !== null) {
                $out[] = $block;
            }
        }
        return $out;
    }
}
