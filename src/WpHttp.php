<?php

declare(strict_types=1);

namespace VPNDetection\WordPress;

use VPNDetection\WordPress\Vendor\GuzzleHttp\Client as GuzzleClient;
use VPNDetection\WordPress\Vendor\GuzzleHttp\Exception\ConnectException;
use VPNDetection\WordPress\Vendor\GuzzleHttp\HandlerStack;
use VPNDetection\WordPress\Vendor\GuzzleHttp\Promise\Create;
use VPNDetection\WordPress\Vendor\GuzzleHttp\Promise\PromiseInterface;
use VPNDetection\WordPress\Vendor\GuzzleHttp\Psr7\Response;
use VPNDetection\WordPress\Vendor\Psr\Http\Message\RequestInterface;

defined('ABSPATH') || exit;

/**
 * A Guzzle handler that sends through WordPress's own HTTP API.
 *
 * So the SDK's requests honor what the site configured for every other one: a WP_PROXY_HOST, a
 * WP_HTTP_BLOCK_EXTERNAL allowlist, the `pre_http_request` and `http_request_args` filters, and the
 * tools that list a page's HTTP calls.
 */
final class WpHttp
{
    public function __construct(private readonly string $product)
    {
    }

    public static function client(): GuzzleClient
    {
        $product = sprintf('vpndetection-wordpress/%s wordpress/%s', VPNDETECTION_VERSION, get_bloginfo('version'));
        return new GuzzleClient(['handler' => HandlerStack::create(new self($product))]);
    }

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        // Guzzle's own handlers wait out a retry's delay; one of ours has to as well.
        $delay = (float) ($options['delay'] ?? 0);
        if ($delay > 0) {
            usleep((int) min($delay, 60_000) * 1000);
        }
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }
        $agent = trim($this->product . ' ' . $request->getHeaderLine('User-Agent'));
        $headers['User-Agent'] = $agent;
        $timeout = (float) ($options['timeout'] ?? 0);
        $response = wp_remote_request((string) $request->getUri(), [
            'method' => $request->getMethod(),
            'headers' => $headers,
            'body' => $request->getMethod() === 'GET' ? null : (string) $request->getBody(),
            'timeout' => $timeout > 0 ? $timeout : 30,
            'redirection' => ($options['allow_redirects'] ?? true) === false ? 0 : 5,
            'user-agent' => $agent,
            'httpversion' => '1.1',
        ]);
        if (is_wp_error($response)) {
            return Create::rejectionFor(new ConnectException($response->get_error_message(), $request));
        }
        $received = wp_remote_retrieve_headers($response);
        $received = is_object($received) && method_exists($received, 'getAll') ? $received->getAll() : (array) $received;
        return Create::promiseFor(new Response(
            (int) wp_remote_retrieve_response_code($response),
            $received,
            wp_remote_retrieve_body($response),
            '1.1',
            wp_remote_retrieve_response_message($response),
        ));
    }
}
