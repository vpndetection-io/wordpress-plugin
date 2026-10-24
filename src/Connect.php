<?php

declare(strict_types=1);

namespace VPNDetection\WordPress;

use VPNDetection\WordPress\Vendor\VPNDetection\OauthAccessDeniedException;
use VPNDetection\WordPress\Vendor\VPNDetection\OauthException;
use VPNDetection\WordPress\Vendor\VPNDetection\OauthExpiredTokenException;
use VPNDetection\WordPress\Vendor\VPNDetection\VPNDetectionException;

defined('ABSPATH') || exit;

/**
 * Connect with VPNDetection: the site owner signs in on vpndetection.io in another tab and picks one
 * of their API keys, and the plugin is handed that key, with nothing to copy.
 *
 * It is the OAuth device flow, the one the CLI signs in with, under the plugin's own client: the site
 * asks for a code, the browser opens the approval page with it, and the settings page asks the site to
 * check every few seconds until the person approves. The device code never leaves the server. Once the
 * key arrives the grant is revoked, since the key is all the plugin keeps.
 */
final class Connect
{
    public const CLIENT_ID = 'vpndetection-wordpress';
    public const NONCE = 'vpndetection_connect';
    private const SCOPE = 'apikeys.read apikeys.reveal';
    private const STATE = 'vpndetection_device_';

    public function register(): void
    {
        add_action('wp_ajax_vpndetection_connect_start', [$this, 'start']);
        add_action('wp_ajax_vpndetection_connect_poll', [$this, 'poll']);
    }

    public function start(): void
    {
        $this->guard();
        try {
            $device = $this->client()->oauth->deviceAuthorization(self::CLIENT_ID, ['scope' => self::SCOPE]);
        } catch (VPNDetectionException $e) {
            wp_send_json_error(['message' => self::failure($e)], 502);
        }
        set_transient(self::STATE . get_current_user_id(), $device->deviceCode, max(60, $device->expiresIn));
        wp_send_json_success([
            'code' => $device->userCode,
            'url' => \VPNDetection\WordPress\Admin\Page::tagged($device->verificationUriComplete ?? $device->verificationUri, 'connect'),
            'interval' => max(5, $device->interval),
            'expires' => $device->expiresIn,
        ]);
    }

    public function poll(): void
    {
        $this->guard();
        $state = self::STATE . get_current_user_id();
        $deviceCode = get_transient($state);
        if (!is_string($deviceCode) || $deviceCode === '') {
            wp_send_json_success(['status' => 'expired']);
        }
        $client = $this->client();
        try {
            $token = $client->oauth->exchangeDeviceCode(self::CLIENT_ID, $deviceCode);
        } catch (OauthAccessDeniedException) {
            delete_transient($state);
            wp_send_json_success(['status' => 'denied']);
        } catch (OauthExpiredTokenException) {
            delete_transient($state);
            wp_send_json_success(['status' => 'expired']);
        } catch (OauthException $e) {
            if ($e->errorCode === 'authorization_pending' || $e->errorCode === 'slow_down') {
                wp_send_json_success(['status' => 'pending', 'slower' => $e->errorCode === 'slow_down']);
            }
            delete_transient($state);
            wp_send_json_error(['message' => self::failure($e)], 502);
        } catch (VPNDetectionException) {
            // A dropped connection is no answer: the code stays good until it expires.
            wp_send_json_success(['status' => 'pending', 'slower' => true]);
        }
        delete_transient($state);
        if ($token->refreshToken !== null) {
            try {
                $client->oauth->revoke(self::CLIENT_ID, $token->refreshToken);
            } catch (VPNDetectionException) {
                // The grant lapses on its own; the key is what matters.
            }
        }
        if ($token->apikey === null || trim($token->apikey) === '') {
            wp_send_json_success(['status' => 'nokey']);
        }
        update_option(Settings::KEY_OPTION, trim($token->apikey), true);
        $diagnostics = new Diagnostics();
        $diagnostics->clear('lookup');
        $diagnostics->clear('gaps');
        wp_send_json_success(['status' => 'connected']);
    }

    private function client(): \VPNDetection\WordPress\Vendor\VPNDetection\Client
    {
        return Detector::newClient(null, 15.0, 2);
    }

    private function guard(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to connect this site.', 'vpndetection')], 403);
        }
        check_ajax_referer(self::NONCE);
    }

    private static function failure(VPNDetectionException $e): string
    {
        return sprintf(
            /* translators: %s: the reason, as VPNDetection gave it. */
            __('VPNDetection could not start the sign-in: %s', 'vpndetection'),
            $e->getMessage(),
        );
    }
}
