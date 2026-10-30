<?php
/**
 * Plugin Name: VPNDetection
 * Plugin URI: https://docs.vpndetection.io/integrations/wordpress
 * Description: Block, hold or flag sign-ins, sign-ups, comments, contact forms, WooCommerce orders and pages from VPNs, proxies, Tor, hosting networks and chosen countries.
 * Version: 1.0.2
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Author: VPNDetection
 * Author URI: https://vpndetection.io
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: vpndetection
 * WC requires at least: 9.0
 * WC tested up to: 11.2
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

const VPNDETECTION_VERSION = '1.0.2';
const VPNDETECTION_FILE = __FILE__;
define('VPNDETECTION_DIR', plugin_dir_path(__FILE__));

// WordPress refuses to activate the plugin below its PHP, but a site moved to an older PHP afterwards
// would still load it: say so instead of failing every request.
if (PHP_VERSION_ID < 80200) {
    add_action('admin_notices', static function (): void {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('VPNDetection needs PHP 8.2 or newer, and is doing nothing until the site runs it.', 'vpndetection')
        );
    });
    return;
}

require __DIR__ . '/vendor/autoload.php';

VPNDetection\WordPress\Plugin::boot();

/**
 * What VPNDetection answers about the visitor behind this request, for a theme or another plugin:
 * the lookup as the API sends it (`is_vpn`, `is_tor`, `vpn`, `location`, `asn`, ...), or null when the
 * visitor's address could not be read, the allowlist covers it, or the lookup failed. Looked up once a
 * request, and counted against the site's allowance like any check.
 *
 * @return array<string, mixed>|null
 */
function vpndetection_visitor(): ?array
{
    return VPNDetection\WordPress\Plugin::instance()->detector->visit()->answer();
}
