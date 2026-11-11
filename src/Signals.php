<?php

declare(strict_types=1);

namespace VPNDetection\WordPress;

use VPNDetection\WordPress\Vendor\VPNDetection\Result;

defined('ABSPATH') || exit;

/**
 * What the plugin can block on, in the order the settings show it.
 *
 * Each signal is one member of a lookup answer. Which plans answer it is the API's to say: on
 * Unlimited, the free plan, with a key or without one, a lookup carries `is_vpn`, Tor, relays, the
 * location and the network, and on every paid plan every flag and the detail behind it, a proxy's
 * sightings included. A key on the free plan also gets a daily trial: its account's first 100 lookups
 * each UTC day answer as a paid plan's, and the rest as Unlimited's, which `trial_expired` says.
 */
final class Signals
{
    public const NEEDS_NOTHING = 'none';
    public const NEEDS_PAID = 'paid';

    /** @var array<string, array{member: string, detail: string, needs: string}> */
    public const ALL = [
        'vpn' => ['member' => 'isVpn', 'detail' => 'vpn', 'needs' => self::NEEDS_NOTHING],
        'tor' => ['member' => 'isTor', 'detail' => 'tor', 'needs' => self::NEEDS_NOTHING],
        'pubproxy' => ['member' => 'isPubproxy', 'detail' => 'pubproxy', 'needs' => self::NEEDS_PAID],
        'resproxy' => ['member' => 'isResproxy', 'detail' => 'resproxy', 'needs' => self::NEEDS_PAID],
        'mobproxy' => ['member' => 'isMobproxy', 'detail' => 'mobproxy', 'needs' => self::NEEDS_PAID],
        'dcproxy' => ['member' => 'isDcproxy', 'detail' => 'dcproxy', 'needs' => self::NEEDS_PAID],
        // A paid plan answers is_hosting; without one it is read by its network's type, which every answer carries.
        'hosting' => ['member' => 'isHosting', 'detail' => 'hosting', 'needs' => self::NEEDS_NOTHING],
        'relay' => ['member' => 'isRelay', 'detail' => 'relay', 'needs' => self::NEEDS_NOTHING],
        'country' => ['member' => 'location', 'detail' => 'location', 'needs' => self::NEEDS_NOTHING],
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
            'country' => __('Countries', 'vpndetection'),
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
            'hosting' => __('Servers at hosting and cloud providers, where bots and scripts run. Without a paid plan, judged by the network an address belongs to.', 'vpndetection'),
            'relay' => __('iCloud Private Relay and company web gateways. Mostly ordinary people.', 'vpndetection'),
            'country' => __('Visitors from the countries you list, wherever they connect from.', 'vpndetection'),
            default => '',
        };
    }

    /**
     * The block condition for a set of signals, in the SDK's condition language: a list, so any one
     * signal is enough. Given the answer, it asks what that answer can tell: an Unlimited answer carries
     * no `is_hosting`, so hosting is read off the type of the network the address belongs to.
     *
     * @param list<string> $signals
     * @param list<string> $countries
     * @return list<array<string, mixed>>
     */
    public static function condition(array $signals, int $minHits, ?Result $answer = null, array $countries = []): array
    {
        $condition = [];
        foreach ($signals as $signal) {
            $one = self::one($signal, $minHits, $answer, $countries);
            if ($one !== null) {
                $condition[] = $one;
            }
        }
        return $condition;
    }

    /**
     * One signal's term, or null for one that constrains nothing: countries with none listed.
     *
     * @param list<string> $countries
     * @return array<string, mixed>|null
     */
    public static function one(string $signal, int $minHits, ?Result $answer = null, array $countries = []): ?array
    {
        if ($signal === 'country') {
            return $countries === [] ? null : ['location' => ['country' => $countries]];
        }
        if ($signal === 'hosting' && $answer !== null && $answer->isHosting === null && $answer->asn !== null) {
            return ['asn' => ['type' => 'hosting']];
        }
        $def = self::ALL[$signal];
        $one = [$def['member'] => true];
        if ($minHits > 1 && in_array($signal, self::COUNTED, true)) {
            $one[$def['detail']] = ['hits' => ['gte' => $minHits]];
        }
        return $one;
    }

    /**
     * Whether a plan tier answers a signal on every lookup; null tier is a keyless client, and `free`
     * the free plan's, which answers every signal only for its account's first 100 lookups a day.
     */
    public static function served(string $signal, ?string $tier): bool
    {
        return self::ALL[$signal]['needs'] === self::NEEDS_NOTHING || ($tier !== null && $tier !== 'free');
    }
}
