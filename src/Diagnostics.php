<?php

declare(strict_types=1);

namespace VPNDetection\WordPress;

use VPNDetection\WordPress\Vendor\VPNDetection\ErrorKind;
use VPNDetection\WordPress\Vendor\VPNDetection\VPNDetectionException;

defined('ABSPATH') || exit;

/**
 * What went wrong on the request path, kept for the admin screens: the plugin cannot tell the site
 * owner anything from inside a visitor's request, and logging it there would write the same line on
 * every request.
 *
 * A note is written when it changes, or when the one kept is an hour old, so a failure that lasts a
 * day costs a write an hour rather than one a request.
 */
final class Diagnostics
{
    public const OPTION = 'vpndetection_diagnostics';
    private const REWRITE_AFTER = HOUR_IN_SECONDS;

    /** A lookup that failed: the key, the allowance or the API. */
    public function lookupFailed(VPNDetectionException $error): void
    {
        // Compared by kind alone: a timeout's message names its milliseconds, which differ every time.
        $this->note('lookup', ['kind' => $error->kind->value, 'message' => $error->getMessage()], ['kind']);
    }

    /**
     * Signals the visitor's answer did not carry, because the key's plan does not include them.
     *
     * @param list<string> $signals
     */
    public function planGaps(array $signals): void
    {
        sort($signals);
        $this->note('gaps', ['signals' => $signals]);
    }

    /**
     * A visitor whose address resolved to a private one: a proxy in front of the site that the
     * Visitor address setting does not know.
     */
    public function privateAddress(string $ip): void
    {
        // Behind several balancers the address changes from one request to the next.
        $this->note('address', ['ip' => $ip], []);
    }

    /**
     * Every note younger than a day.
     *
     * @return array<string, array<string, mixed>>
     */
    public function recent(): array
    {
        $notes = get_option(self::OPTION, []);
        $notes = is_array($notes) ? $notes : [];
        return array_filter($notes, static fn ($n): bool => is_array($n) && ($n['at'] ?? 0) > time() - DAY_IN_SECONDS);
    }

    public function clear(string $name): void
    {
        $notes = get_option(self::OPTION, []);
        if (is_array($notes) && isset($notes[$name])) {
            unset($notes[$name]);
            update_option(self::OPTION, $notes, true);
        }
    }

    /** Whether the failure is the site's to fix: a key refused, or an allowance spent. */
    public static function needsOwner(string $kind): bool
    {
        return in_array($kind, [ErrorKind::Unauthorized->value, ErrorKind::Forbidden->value, ErrorKind::QuotaExceeded->value], true);
    }

    /**
     * @param array<string, mixed> $note
     * @param list<string>|null $compare The members that make it a different note; null for all.
     */
    private function note(string $name, array $note, ?array $compare = null): void
    {
        $notes = get_option(self::OPTION, []);
        $notes = is_array($notes) ? $notes : [];
        $kept = $notes[$name] ?? null;
        $keys = array_flip($compare ?? array_keys($note));
        if (
            is_array($kept)
            && array_intersect_key($kept, $keys) == array_intersect_key($note, $keys)
            && ($kept['at'] ?? 0) > time() - self::REWRITE_AFTER
        ) {
            return;
        }
        $notes[$name] = $note + ['at' => time()];
        update_option(self::OPTION, $notes, true);
    }
}
