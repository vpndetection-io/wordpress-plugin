<?php

declare(strict_types=1);

namespace VPNDetection\WordPress;

defined('ABSPATH') || exit;

/** The parts of the current request the plugin reads, unslashed and sanitized once. */
final class Request
{
    private const SERVER = [
        'REMOTE_ADDR',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_TRUE_CLIENT_IP',
        'HTTP_CF_CONNECTING_IP',
        'HTTP_USER_AGENT',
        'REQUEST_URI',
    ];

    /** @return array<string, string> */
    public static function server(): array
    {
        $out = [];
        foreach (self::SERVER as $name) {
            if (isset($_SERVER[$name]) && is_string($_SERVER[$name])) {
                $out[$name] = sanitize_text_field(wp_unslash($_SERVER[$name]));
            }
        }
        return $out;
    }

    /** The path and query asked for, as the browser sent it. */
    public static function path(): string
    {
        $uri = self::server()['REQUEST_URI'] ?? '/';
        return $uri === '' ? '/' : $uri;
    }
}
