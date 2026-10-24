<?php

declare(strict_types=1);

namespace VPNDetection\WordPress;

use VPNDetection\WordPress\Vendor\VPNDetection\Result;
use VPNDetection\WordPress\Vendor\VPNDetection\VPNDetectionException;

defined('ABSPATH') || exit;

/** Who this request came from, as far as the plugin could tell. */
final class Visit
{
    public function __construct(
        /** The visitor's address, or null when the request named none the plugin could read. */
        public readonly ?string $ip,
        /** The lookup's answer; null when no lookup was made or it failed. */
        public readonly ?Result $result = null,
        /** Why the lookup failed, when it did. */
        public readonly ?VPNDetectionException $error = null,
        /** Whether the allowlist covers the visitor, by address or by network. */
        public readonly bool $allowed = false,
    ) {
    }

    /**
     * The answer as the API sent it, which is what the plugin hands to code of other plugins and
     * themes: plain arrays, named as the API docs name them.
     *
     * @return array<string, mixed>|null
     */
    public function answer(): ?array
    {
        if ($this->result === null) {
            return null;
        }
        if ($this->result->isBogon) {
            return ['ip' => $this->result->ip, 'is_vpn' => false, 'is_bogon' => true];
        }
        return $this->result->raw;
    }
}
