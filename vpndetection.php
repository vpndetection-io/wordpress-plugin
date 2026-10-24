<?php
/**
 * Plugin Name: VPNDetection
 * Plugin URI: https://docs.vpndetection.io/integrations/wordpress
 * Description: The official VPNDetection plugin for WordPress.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Author: VPNDetection
 * Author URI: https://vpndetection.io
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: vpndetection
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

const VPNDETECTION_VERSION = '1.0.0';
const VPNDETECTION_FILE = __FILE__;

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
