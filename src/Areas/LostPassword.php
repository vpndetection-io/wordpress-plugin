<?php

declare(strict_types=1);

namespace VPNDetection\WordPress\Areas;

use VPNDetection\WordPress\Detector;
use VPNDetection\WordPress\Settings;
use WP_Error;
use WP_User;

defined('ABSPATH') || exit;

/** Password resets, WordPress's own and WooCommerce's, which both ask through `lostpassword_post`. */
final class LostPassword
{
    public function __construct(private readonly Settings $settings, private readonly Detector $detector)
    {
    }

    public function register(): void
    {
        add_action('lostpassword_post', [$this, 'check'], 99, 2);
    }

    public function check(mixed $errors, mixed $user = null): void
    {
        if (!$errors instanceof WP_Error) {
            return;
        }
        $login = $user instanceof WP_User ? $user->user_login : '';
        $verdict = $this->detector->judge('lostpassword', ['user' => $login]);
        if ($verdict !== null && $verdict->enforced()) {
            $errors->add('vpndetection_blocked', esc_html($this->settings->message()));
        }
    }
}
