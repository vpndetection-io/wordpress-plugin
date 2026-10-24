<?php

declare(strict_types=1);

namespace VPNDetection\WordPress;

use InvalidArgumentException;
use VPNDetection\WordPress\Vendor\VPNDetection\Bogon;
use VPNDetection\WordPress\Vendor\VPNDetection\Client;
use VPNDetection\WordPress\Vendor\VPNDetection\Middleware\Condition;
use VPNDetection\WordPress\Vendor\VPNDetection\Middleware\Core;
use VPNDetection\WordPress\Vendor\VPNDetection\Middleware\Options as MiddlewareOptions;
use VPNDetection\WordPress\Vendor\VPNDetection\Options;
use VPNDetection\WordPress\Vendor\VPNDetection\Result;

defined('ABSPATH') || exit;

/**
 * Looks the visitor up at most once a request, and decides for each area whether they are someone
 * it should act on.
 *
 * Built on the SDK's middleware core, which every framework adapter shares: the address is resolved
 * by the plugin's own selector, a private one is answered without a lookup, and a lookup that fails
 * lets the visitor through unless the site chose to fail closed.
 */
final class Detector
{
    /** Seconds a lookup may hold a visitor's request: failing open quickly beats waiting. */
    public const TIMEOUT = 2.5;

    private ?Visit $visit = null;
    private ?Client $client = null;
    private ?string $address = null;
    private bool $resolved = false;
    /** @var array<string, Verdict|null> */
    private array $judged = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly ActivityLog $log,
        private readonly Diagnostics $diagnostics,
    ) {
    }

    /** The SDK client the request path uses: the site's key, a short timeout and no retries. */
    public function client(): Client
    {
        return $this->client ??= self::newClient($this->settings->apiKey(), self::TIMEOUT, 0);
    }

    public static function newClient(?string $apiKey, float $timeout, int $retries): Client
    {
        return new Client(new Options(
            apiKey: $apiKey,
            baseUrl: self::baseUrl(),
            retries: $retries,
            timeout: $timeout,
            httpClient: WpHttp::client(),
            cacheStore: new Store(),
            cacheTtlSeconds: (float) apply_filters('vpndetection_cache_ttl', 6 * HOUR_IN_SECONDS),
        ));
    }

    /** The API's origin. A constant points the plugin at another deployment, for testing it. */
    public static function baseUrl(): string
    {
        return defined('VPNDETECTION_BASE_URL') ? (string) constant('VPNDETECTION_BASE_URL') : Client::DEFAULT_BASE_URL;
    }

    /** The visitor behind this request, looked up once however many areas ask. */
    public function visit(): Visit
    {
        return $this->visit ??= $this->lookUp();
    }

    /** The visitor's address, resolved without a lookup. */
    public function address(): ?string
    {
        if (!$this->resolved) {
            $server = Request::server();
            $ip = ClientAddress::fromSettings($this->settings)->resolve($server);
            /**
             * Filters the visitor's address, for a site whose edge names it in a way the settings
             * cannot.
             *
             * @param string|null $ip
             * @param array<string, string> $server
             */
            $ip = apply_filters('vpndetection_client_ip', $ip, $server);
            $this->address = is_string($ip) ? Address::normalize($ip) : null;
            $this->resolved = true;
        }
        return $this->address;
    }

    /**
     * Whether an area should act on this visitor, and how. Null when the area is off, nothing is set
     * to block, or the visitor passes.
     *
     * @param array<string, string> $context What the visitor was doing, for the activity log.
     */
    public function judge(string $area, array $context = []): ?Verdict
    {
        if (array_key_exists($area, $this->judged)) {
            return $this->judged[$area];
        }
        return $this->judged[$area] = $this->decide($area, $context);
    }

    /** @param array<string, string> $context */
    private function decide(string $area, array $context): ?Verdict
    {
        $action = $this->settings->action($area);
        $signals = $this->settings->signals($area);
        if ($action === 'off' || $signals === []) {
            return null;
        }
        $visit = $this->visit();
        if ($visit->ip === null || $visit->allowed) {
            return null;
        }
        if ($visit->error !== null) {
            if (!$this->settings->get('fail_closed')) {
                return null;
            }
            return $this->flag(new Verdict($area, $action, ['unavailable'], $visit, $context));
        }
        $result = $visit->result;
        if ($result === null || $result->isBogon) {
            return null;
        }
        $minHits = (int) $this->settings->get('min_hits');
        $condition = apply_filters('vpndetection_condition', Signals::condition($signals, $minHits), $area, $signals);
        try {
            Condition::validate(is_array($condition) ? $condition : null);
        } catch (InvalidArgumentException) {
            return null;
        }
        $this->notePlanGaps($signals, $minHits, $result);
        if (!is_array($condition) || !Condition::matches($condition, $result)) {
            return null;
        }
        $matched = array_values(array_filter(
            $signals,
            static fn (string $s): bool => Condition::matches([Signals::one($s, $minHits)], $result),
        ));
        return $this->flag(new Verdict($area, $action, $matched === [] ? ['custom'] : $matched, $visit, $context));
    }

    private function flag(Verdict $verdict): ?Verdict
    {
        /**
         * Filters a verdict before the area acts on it. Return null to let the visitor through.
         *
         * @param Verdict|null $verdict
         */
        $verdict = apply_filters('vpndetection_verdict', $verdict);
        if (!$verdict instanceof Verdict) {
            return null;
        }
        $this->log->record($verdict);
        do_action('vpndetection_flagged', $verdict->toArray());
        return $verdict;
    }

    private function lookUp(): Visit
    {
        $ip = $this->address();
        if ($ip === null) {
            return new Visit(null);
        }
        $allowlist = Allowlist::parse($this->settings->get('allowlist'));
        if ($allowlist->coversAddress($ip)) {
            return new Visit($ip, allowed: true);
        }
        if (Bogon::isBogon($ip)) {
            $this->diagnostics->privateAddress($ip);
        }
        // The core's own warnings name the SDK's settings; the plugin says it in its own terms above.
        $core = new Core(
            new MiddlewareOptions(client: $this->client(), onMissingField: 'ignore', onWarn: static function (): void {
            }),
            static fn (): string => $ip,
        );
        $lookup = $core->evaluate(null);
        if ($lookup === null || $lookup->result === null) {
            if ($lookup?->error !== null) {
                $this->diagnostics->lookupFailed($lookup->error);
            }
            return new Visit($ip, error: $lookup?->error);
        }
        // A failure the settings are still showing is over once a lookup answers; one younger than
        // five minutes stays, so a flapping API costs a write every few minutes, not every request.
        $failed = $this->diagnostics->recent()['lookup']['at'] ?? null;
        if ($failed !== null && $failed < time() - 5 * MINUTE_IN_SECONDS) {
            $this->diagnostics->clear('lookup');
        }
        $asn = $lookup->result->asn?->asn ?? 0;
        return new Visit($ip, $lookup->result, allowed: $allowlist->coversAsn($asn));
    }

    /**
     * Notes the signals this answer could never match because the key's plan does not answer them,
     * so the settings can say why a signal blocks nobody.
     *
     * @param list<string> $signals
     */
    private function notePlanGaps(array $signals, int $minHits, Result $result): void
    {
        $gaps = [];
        foreach ($signals as $signal) {
            if (Condition::missingMembers([Signals::one($signal, $minHits)], $result) !== []) {
                $gaps[] = $signal;
            }
        }
        if ($gaps !== []) {
            $this->diagnostics->planGaps($gaps);
        } elseif (isset($this->diagnostics->recent()['gaps'])) {
            $this->diagnostics->clear('gaps');
        }
    }
}
