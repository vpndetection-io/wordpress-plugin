<?php

declare(strict_types=1);

namespace VPNDetection\WordPress;

use VPNDetection\WordPress\Areas\Pages;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

/**
 * The route a cached page's script asks: may the visitor behind this request see the page at this
 * path? It answers only about the caller's own address, so it tells nobody anything about anyone else.
 */
final class Rest
{
    public function __construct(private readonly Pages $pages)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('vpndetection/v1', '/check', [
                'methods' => 'POST',
                'callback' => [$this, 'check'],
                'permission_callback' => '__return_true',
                'args' => [
                    'path' => [
                        'type' => 'string',
                        'required' => true,
                        'sanitize_callback' => static fn ($v): string => '/' . ltrim(sanitize_text_field((string) $v), '/'),
                    ],
                ],
            ]);
        });
    }

    public function check(WP_REST_Request $request): WP_REST_Response
    {
        Pages::uncached();
        $response = new WP_REST_Response($this->pages->check((string) $request->get_param('path')));
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }
}
