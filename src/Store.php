<?php

declare(strict_types=1);

namespace VPNDetection\WordPress;

use DateInterval;
use DateTimeImmutable;
use VPNDetection\WordPress\Vendor\Psr\SimpleCache\CacheInterface;

defined('ABSPATH') || exit;

/**
 * The SDK's answer cache, kept where WordPress keeps things between requests: the persistent object
 * cache when the site has one (Redis, Memcached), transients otherwise.
 *
 * PHP builds the plugin afresh for every request, so the client's own in-memory cache would last one
 * request; this one makes a returning visitor cost no lookup.
 */
final class Store implements CacheInterface
{
    private const GROUP = 'vpndetection';

    public function get(string $key, mixed $default = null): mixed
    {
        $found = false;
        $value = wp_using_ext_object_cache()
            ? wp_cache_get($key, self::GROUP, false, $found)
            : get_transient(self::name($key));
        if (wp_using_ext_object_cache() ? !$found : $value === false) {
            return $default;
        }
        return $value;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $seconds = self::seconds($ttl);
        if (wp_using_ext_object_cache()) {
            return wp_cache_set($key, $value, self::GROUP, $seconds);
        }
        return set_transient(self::name($key), $value, $seconds);
    }

    public function delete(string $key): bool
    {
        if (wp_using_ext_object_cache()) {
            return wp_cache_delete($key, self::GROUP);
        }
        return delete_transient(self::name($key));
    }

    public function clear(): bool
    {
        // Answers expire on their own, and wiping every transient a site holds to clear ours is not
        // a trade worth making.
        return false;
    }

    /** @return iterable<string, mixed> */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $default);
        }
        return $out;
    }

    /** @param iterable<string, mixed> $values */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }
        return $ok;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->delete($key) && $ok;
        }
        return $ok;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    private static function name(string $key): string
    {
        return 'vpndetection_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($key));
    }

    private static function seconds(null|int|DateInterval $ttl): int
    {
        if ($ttl instanceof DateInterval) {
            $now = new DateTimeImmutable();
            return max(1, $now->add($ttl)->getTimestamp() - $now->getTimestamp());
        }
        return $ttl === null ? 0 : max(1, $ttl);
    }
}
