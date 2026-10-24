<?php

declare(strict_types=1);

namespace VPNDetection\WordPress;

use VPNDetection\WordPress\Admin\Page;
use VPNDetection\WordPress\Areas\Checkout;
use VPNDetection\WordPress\Areas\Comments;
use VPNDetection\WordPress\Areas\Forms;
use VPNDetection\WordPress\Areas\LostPassword;
use VPNDetection\WordPress\Areas\Pages;
use VPNDetection\WordPress\Areas\SignIn;
use VPNDetection\WordPress\Areas\SignUp;

defined('ABSPATH') || exit;

/** Wires the plugin into WordPress: each area that is on, the admin screens, and the housekeeping. */
final class Plugin
{
    private static ?self $instance = null;

    public readonly Settings $settings;
    public readonly ActivityLog $log;
    public readonly Diagnostics $diagnostics;
    public readonly Detector $detector;

    private function __construct()
    {
        $this->settings = new Settings();
        $this->log = new ActivityLog($this->settings);
        $this->diagnostics = new Diagnostics();
        $this->detector = new Detector($this->settings, $this->log, $this->diagnostics);
    }

    public static function boot(): void
    {
        if (self::$instance !== null) {
            return;
        }
        self::$instance = new self();
        self::$instance->hooks();
    }

    public static function instance(): self
    {
        self::boot();
        return self::$instance;
    }

    public static function activate(): void
    {
        ActivityLog::install();
        if (!wp_next_scheduled(ActivityLog::PURGE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', ActivityLog::PURGE_HOOK);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(ActivityLog::PURGE_HOOK);
    }

    private function hooks(): void
    {
        register_activation_hook(VPNDETECTION_FILE, [self::class, 'activate']);
        register_deactivation_hook(VPNDETECTION_FILE, [self::class, 'deactivate']);
        // Registered after every plugin has loaded, so WooCommerce and the form plugins are there to
        // be found whatever order they load in.
        add_action('plugins_loaded', [$this, 'areas']);
        add_action('before_woocommerce_init', [self::class, 'declareWooCommerce']);
        add_action(ActivityLog::PURGE_HOOK, [$this->log, 'purge']);
        add_action('admin_init', [Privacy::class, 'suggest']);
        if (is_admin()) {
            (new Page($this))->register();
            (new Connect())->register();
        }
    }

    public function areas(): void
    {
        ActivityLog::install();
        $areas = [
            'login' => SignIn::class,
            'register' => SignUp::class,
            'lostpassword' => LostPassword::class,
            'comment' => Comments::class,
            'forms' => Forms::class,
            'checkout' => Checkout::class,
        ];
        foreach ($areas as $area => $class) {
            if ($this->settings->action($area) !== 'off') {
                (new $class($this->settings, $this->detector))->register();
            }
        }
        if ($this->settings->action('pages') !== 'off') {
            $pages = new Pages($this->settings, $this->detector);
            $pages->register();
            (new Rest($pages))->register();
        }
    }

    /** Orders in WooCommerce's own tables, and its block checkout, are both handled. */
    public static function declareWooCommerce(): void
    {
        $features = 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil';
        if (class_exists($features)) {
            $features::declare_compatibility('custom_order_tables', VPNDETECTION_FILE, true);
            $features::declare_compatibility('cart_checkout_blocks', VPNDETECTION_FILE, true);
        }
    }
}
