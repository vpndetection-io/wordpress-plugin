<?php

declare(strict_types=1);

namespace VPNDetection\WordPress\Areas;

use VPNDetection\WordPress\Detector;
use VPNDetection\WordPress\Settings;
use WP_Error;
use WP_User;

defined('ABSPATH') || exit;

/**
 * Sign-in: wp-login.php, any front-end form that signs in through wp_signon (WooCommerce's My
 * Account among them) and XML-RPC.
 *
 * It runs after the password check, so a refused visitor gets the same answer whether or not the
 * password was right, and an administrator can be let through on the account rather than the
 * connection.
 */
final class SignIn
{
    public function __construct(private readonly Settings $settings, private readonly Detector $detector)
    {
    }

    public function register(): void
    {
        add_filter('authenticate', [$this, 'authenticate'], 99, 3);
    }

    public function authenticate(mixed $user, mixed $username = '', mixed $password = ''): mixed
    {
        $username = is_string($username) ? $username : '';
        if ($username === '' && (!is_string($password) || $password === '')) {
            return $user;
        }
        if ($user instanceof WP_User && $this->settings->get('admins_sign_in') && user_can($user, 'manage_options')) {
            return $user;
        }
        $verdict = $this->detector->judge('login', ['user' => sanitize_user($username)]);
        if ($verdict === null || !$verdict->enforced()) {
            return $user;
        }
        return new WP_Error('vpndetection_blocked', esc_html($this->settings->message()));
    }
}
