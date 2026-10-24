<?php

declare(strict_types=1);

namespace VPNDetection\WordPress\Areas;

use VPNDetection\WordPress\Detector;
use VPNDetection\WordPress\Settings;
use VPNDetection\WordPress\Verdict;
use WP_Error;

defined('ABSPATH') || exit;

/** New accounts: WordPress's own registration, a network's sign-up, and WooCommerce's My Account. */
final class SignUp
{
    public function __construct(private readonly Settings $settings, private readonly Detector $detector)
    {
    }

    public function register(): void
    {
        add_filter('registration_errors', [$this, 'check'], 99, 2);
        add_filter('woocommerce_registration_errors', [$this, 'check'], 99, 2);
        add_filter('wpmu_validate_user_signup', [$this, 'network'], 99);
    }

    public function check(mixed $errors, mixed $login = ''): mixed
    {
        $verdict = $errors instanceof WP_Error ? $this->refused(is_string($login) ? $login : '') : null;
        if ($verdict !== null) {
            $errors->add('vpndetection_blocked', esc_html($this->settings->message($verdict)));
        }
        return $errors;
    }

    /**
     * @param mixed $result
     * @return mixed
     */
    public function network($result)
    {
        // A network admin adding a user from wp-admin is not someone signing up.
        if (is_admin() || !is_array($result) || !($result['errors'] ?? null) instanceof WP_Error) {
            return $result;
        }
        $verdict = $this->refused((string) ($result['user_name'] ?? ''));
        if ($verdict !== null) {
            $result['errors']->add('user_name', esc_html($this->settings->message($verdict)));
        }
        return $result;
    }

    /** The verdict that refuses this sign-up, or null when it goes ahead. */
    private function refused(string $login): ?Verdict
    {
        $verdict = $this->detector->judge('register', ['user' => sanitize_user($login)]);
        return $verdict !== null && $verdict->enforced() ? $verdict : null;
    }
}
