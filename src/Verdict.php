<?php

declare(strict_types=1);

namespace VPNDetection\WordPress;

defined('ABSPATH') || exit;

/** A visitor one area flagged, and what that area does about it. */
final class Verdict
{
    /**
     * @param list<string> $signals
     * @param array<string, string> $context
     */
    public function __construct(
        public readonly string $area,
        /** `log`, `block`, `hold` or `spam`. */
        public readonly string $action,
        /** The signals that matched, or `unavailable` when the check failed and failing closed. */
        public readonly array $signals,
        public readonly Visit $visit,
        /** What the visitor was doing: the sign-in name, the order, the page. */
        public readonly array $context = [],
    ) {
    }

    /** Whether the area acts on it, rather than only recording it. */
    public function enforced(): bool
    {
        return $this->action !== 'log';
    }

    /** The provider the answer names for the first matched signal that has one. */
    public function provider(): string
    {
        $result = $this->visit->result;
        if ($result === null) {
            return '';
        }
        foreach ($this->signals as $signal) {
            $detail = Signals::ALL[$signal]['detail'] ?? null;
            $object = $detail === null ? null : $result->{$detail};
            $provider = is_object($object) && property_exists($object, 'provider') ? (string) $object->provider : '';
            if ($provider !== '') {
                return $provider;
            }
        }
        return '';
    }

    /** What matched, for people: "VPNs (nordvpn), Hosting and cloud servers". */
    public function describe(): string
    {
        $parts = [];
        $provider = $this->provider();
        foreach ($this->signals as $i => $signal) {
            $label = Signals::label($signal);
            if ($signal === 'country') {
                $country = $this->visit->result?->location?->country ?? '';
                $parts[] = $country === '' ? $label : sprintf('%s (%s)', $label, $country);
                continue;
            }
            $parts[] = $i === 0 && $provider !== '' ? sprintf('%s (%s)', $label, $provider) : $label;
        }
        return implode(', ', $parts);
    }

    /**
     * The verdict as plain data, for the `vpndetection_flagged` action.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'area' => $this->area,
            'action' => $this->action,
            'signals' => $this->signals,
            'ip' => $this->visit->ip,
            'answer' => $this->visit->answer(),
            'context' => $this->context,
        ];
    }
}
