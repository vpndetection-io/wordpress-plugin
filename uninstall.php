<?php
/** Removes what the plugin kept. */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('vpndetection_settings');
