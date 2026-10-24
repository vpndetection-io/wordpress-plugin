<?php

declare(strict_types=1);

namespace VPNDetection\WordPress;

defined('ABSPATH') || exit;

/**
 * What the plugin can block on, in the order the settings show it.
 *
 * Each signal is one member of a lookup answer. Which plans answer it is the API's to say: without a
 * key a lookup carries `is_vpn` alone, a free key adds Tor and relays, and every paid plan answers
 * every member.
 */
final class Signals
{
    public const NEEDS_NOTHING = 'none';
    public const NEEDS_KEY = 'key';
    public const NEEDS_PAID = 'paid';

    /** @var array<string, array{member: string, detail: string, needs: string}> */
    public const ALL = [
        'vpn' => ['member' => 'isVpn', 'detail' => 'vpn', 'needs' => self::NEEDS_NOTHING],
        'tor' => ['member' => 'isTor', 'detail' => 'tor', 'needs' => self::NEEDS_KEY],
        'pubproxy' => ['member' => 'isPubproxy', 'detail' => 'pubproxy', 'needs' => self::NEEDS_PAID],
        'resproxy' => ['member' => 'isResproxy', 'detail' => 'resproxy', 'needs' => self::NEEDS_PAID],
        'mobproxy' => ['member' => 'isMobproxy', 'detail' => 'mobproxy', 'needs' => self::NEEDS_PAID],
        'dcproxy' => ['member' => 'isDcproxy', 'detail' => 'dcproxy', 'needs' => self::NEEDS_PAID],
        'hosting' => ['member' => 'isHosting', 'detail' => 'hosting', 'needs' => self::NEEDS_PAID],
        'relay' => ['member' => 'isRelay', 'detail' => 'relay', 'needs' => self::NEEDS_KEY],
    ];

    /** Signals a pool sighting count applies to, where one sighting can be a shared address. */
    public const COUNTED = ['resproxy', 'mobproxy', 'dcproxy'];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function label(string $signal): string
    {
        return match ($signal) {
            'vpn' => __('VPNs', 'vpndetection'),
            'tor' => __('Tor', 'vpndetection'),
            'pubproxy' => __('Public proxies', 'vpndetection'),
            'resproxy' => __('Residential proxies', 'vpndetection'),
            'mobproxy' => __('Mobile proxies', 'vpndetection'),
            'dcproxy' => __('Datacenter proxies', 'vpndetection'),
            'hosting' => __('Hosting and cloud servers', 'vpndetection'),
            'relay' => __('Privacy relays', 'vpndetection'),
            'unavailable' => __('Check unavailable', 'vpndetection'),
            'custom' => __('Custom rule', 'vpndetection'),
            default => $signal,
        };
    }

    public static function hint(string $signal): string
    {
        return match ($signal) {
            'vpn' => __('Commercial and self-hosted VPN servers.', 'vpndetection'),
            'tor' => __('Tor exit and relay nodes.', 'vpndetection'),
            'pubproxy' => __('Open proxies anyone can connect through.', 'vpndetection'),
            'resproxy' => __('Home connections rented out as proxies.', 'vpndetection'),
            'mobproxy' => __('Phone connections rented out as proxies. Carriers share these addresses with ordinary customers.', 'vpndetection'),
            'dcproxy' => __('Proxies running in datacenters.', 'vpndetection'),
            'hosting' => __('Servers at hosting and cloud providers, where bots and scripts run.', 'vpndetection'),
            'relay' => __('iCloud Private Relay and company web gateways. Mostly ordinary people.', 'vpndetection'),
            default => '',
        };
    }

    /**
     * The block condition for a set of signals, in the SDK's condition language: a list, so any one
     * signal is enough.
     *
     * @param list<string> $signals
     * @return list<array<string, mixed>>
     */
    public static function condition(array $signals, int $minHits): array
    {
        $condition = [];
        foreach ($signals as $signal) {
            $condition[] = self::one($signal, $minHits);
        }
        return $condition;
    }

    /** @return array<string, mixed> */
    public static function one(string $signal, int $minHits): array
    {
        $def = self::ALL[$signal];
        $one = [$def['member'] => true];
        if ($minHits > 1 && in_array($signal, self::COUNTED, true)) {
            $one[$def['detail']] = ['hits' => ['gte' => $minHits]];
        }
        return $one;
    }

    /** Whether a plan tier answers a signal; null tier is a keyless client. */
    public static function served(string $signal, ?string $tier): bool
    {
        return match (self::ALL[$signal]['needs']) {
            self::NEEDS_NOTHING => true,
            self::NEEDS_KEY => $tier !== null,
            default => $tier !== null && $tier !== 'free',
        };
    }
}
