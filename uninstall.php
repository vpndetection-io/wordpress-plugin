<?php
/**
 * Removes everything the plugin kept: its settings, the API key, the activity table, its scheduled
 * purge, and the answers it cached, on every site of a network.
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

require __DIR__ . '/vendor/autoload.php';

function vpndetection_uninstall_site(): void
{
    global $wpdb;
    foreach (['vpndetection_settings', 'vpndetection_api_key', 'vpndetection_diagnostics', 'vpndetection_set_up'] as $option) {
        delete_option($option);
    }
    delete_transient('vpndetection_account');
    VPNDetection\WordPress\ActivityLog::uninstall();
    wp_clear_scheduled_hook(VPNDetection\WordPress\ActivityLog::PURGE_HOOK);
    // Cached answers expire on their own within hours; with no object cache they are transients.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- removing the plugin's own transients at uninstall.
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('_transient_vpndetection_') . '%',
        $wpdb->esc_like('_transient_timeout_vpndetection_') . '%',
    ));
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $vpndetection_site) {
        switch_to_blog((int) $vpndetection_site);
        vpndetection_uninstall_site();
        restore_current_blog();
    }
} else {
    vpndetection_uninstall_site();
}
