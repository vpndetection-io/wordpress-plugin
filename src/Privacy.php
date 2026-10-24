<?php

declare(strict_types=1);

namespace VPNDetection\WordPress;

defined('ABSPATH') || exit;

/** The paragraph WordPress offers site owners for their privacy policy. */
final class Privacy
{
    public static function suggest(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        $text = '<p>' . esc_html__(
            'This site uses VPNDetection to tell whether a visitor connects through a VPN, a proxy, Tor or a hosting network. When you sign in, create an account, reset a password, comment, send a form, place an order or open a protected page, your IP address is sent to VPNDetection (vpndetection.io), which answers what kind of network it belongs to. The answer is kept for a few hours so a later visit does not ask again, and a visit the site acts on is recorded with the address for a set number of days.',
            'vpndetection',
        ) . '</p><p>' . sprintf(
            /* translators: 1: privacy policy URL; 2: terms URL. */
            esc_html__('VPNDetection\'s privacy policy is at %1$s and its terms at %2$s.', 'vpndetection'),
            '<a href="https://vpndetection.io/privacy">https://vpndetection.io/privacy</a>',
            '<a href="https://vpndetection.io/terms">https://vpndetection.io/terms</a>',
        ) . '</p>';
        wp_add_privacy_policy_content('VPNDetection', wp_kses_post($text));
    }
}
