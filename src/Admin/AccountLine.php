<?php

declare(strict_types=1);

namespace VPNDetection\WordPress\Admin;

use VPNDetection\WordPress\Vendor\VPNDetection\Entitlement;

defined('ABSPATH') || exit;

/** What the account card says of an account's usage, apart from the page so a test can read it. */
final class AccountLine
{
    /** The plan an account with no subscription is on. */
    public const FREE_PLAN = 'free';

    public static function of(Entitlement $account, int $now): string
    {
        $usage = $account->usage;
        $at = wp_date(get_option('time_format'), $usage->windowEnd->getTimestamp());
        if ($account->plan->key !== self::FREE_PLAN) {
            return sprintf(
                /* translators: 1: checks made; 2: checks included; 3: a date. */
                __('%1$s of %2$s checks used this period, which resets on %3$s.', 'vpndetection'),
                number_format_i18n($usage->requests),
                number_format_i18n($usage->quota),
                wp_date(get_option('date_format'), $usage->windowEnd->getTimestamp()),
            );
        }
        // A free key's day: the first checks answer every signal and the rest the core ones, until the
        // window the API names ends and its count starts over. The figures are the API's, never the plugin's.
        if ($usage->windowEnd->getTimestamp() <= $now) {
            return __('A new day\'s checks have every signal again.', 'vpndetection');
        }
        if ($usage->quota > 0 && $usage->requests >= $usage->quota) {
            return sprintf(
                /* translators: %s: a time of day. */
                __('Today\'s checks with every signal are used, so checks go on with the core signals until %s.', 'vpndetection'),
                $at,
            );
        }
        return sprintf(
            /* translators: 1: checks made with every signal; 2: how many get every signal; 3: a time of day. */
            __('%1$s of %2$s checks with every signal today, resetting at %3$s.', 'vpndetection'),
            number_format_i18n($usage->requests),
            number_format_i18n($usage->quota),
            $at,
        );
    }
}
