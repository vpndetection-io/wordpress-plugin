<?php

declare(strict_types=1);

namespace VPNDetection\WordPress;

defined('ABSPATH') || exit;

/**
 * The plugin's settings: one option, read once a request and sanitized on every save.
 *
 * The API key is an option of its own, so nothing that copies these settings around carries it, and
 * a VPNDETECTION_API_KEY constant in wp-config.php takes its place for a site that keeps secrets out
 * of the database.
 */
final class Settings
{
    public const OPTION = 'vpndetection_settings';
    public const KEY_OPTION = 'vpndetection_api_key';

    /** Each place the plugin can check, and what it can do there. */
    public const AREAS = [
        'login' => ['off', 'log', 'block'],
        'register' => ['off', 'log', 'block'],
        'lostpassword' => ['off', 'log', 'block'],
        'comment' => ['off', 'log', 'hold', 'spam', 'block'],
        'checkout' => ['off', 'log', 'hold', 'block'],
        'forms' => ['off', 'log', 'spam', 'block'],
        'pages' => ['off', 'log', 'block'],
    ];

    private const DEFAULTS = [
        'signals' => ['vpn', 'tor', 'pubproxy', 'resproxy', 'dcproxy', 'hosting'],
        'countries' => '',
        'min_hits' => 1,
        'areas' => [],
        'admins_sign_in' => true,
        'pages_scope' => 'site',
        'pages_post_types' => [],
        'pages_paths' => '',
        'pages_redirect' => '',
        'pages_uncached' => false,
        'message' => '',
        'allowlist' => '',
        'proxy' => ClientAddress::AUTO,
        'proxy_header' => 'X-Forwarded-For',
        'proxy_trusted' => '',
        'fail_closed' => false,
        'log_days' => 30,
    ];

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    public function get(string $name): mixed
    {
        $this->values ??= self::normalize(get_option(self::OPTION, []));
        return $this->values[$name];
    }

    /** What an area does: one of its actions, `off` when it is not checked at all. */
    public function action(string $area): string
    {
        return $this->get('areas')[$area]['action'];
    }

    /**
     * The signals an area blocks on: its own list when it has one, the site's otherwise.
     *
     * @return list<string>
     */
    public function signals(string $area): array
    {
        return $this->get('areas')[$area]['signals'] ?? $this->get('signals');
    }

    public function apiKey(): ?string
    {
        $key = defined('VPNDETECTION_API_KEY') ? (string) constant('VPNDETECTION_API_KEY') : (string) get_option(self::KEY_OPTION, '');
        $key = trim($key);
        return $key === '' ? null : $key;
    }

    public function keyInConfig(): bool
    {
        return defined('VPNDETECTION_API_KEY') && trim((string) constant('VPNDETECTION_API_KEY')) !== '';
    }

    /** What a refused visitor reads: the site's own message, or one that fits why they were refused. */
    public function message(?Verdict $verdict = null): string
    {
        $message = trim($this->get('message'));
        if ($message !== '') {
            return $message;
        }
        if ($verdict !== null && $verdict->signals === ['country']) {
            return __('This site is not available in your country.', 'vpndetection');
        }
        return __('Your connection comes through a VPN, proxy or hosting network, which this site does not allow. Turn it off and try again.', 'vpndetection');
    }

    /**
     * The countries the Countries signal refuses, as two-letter ISO codes.
     *
     * @return list<string>
     */
    public function countries(): array
    {
        return self::countryCodes($this->get('countries'));
    }

    /** @return list<string> */
    public static function countryCodes(string $text): array
    {
        preg_match_all('/\b[A-Za-z]{2}\b/', $text, $m);
        return array_values(array_unique(array_map('strtoupper', $m[0])));
    }

    /** Forget what was read, after a save in this same request. */
    public function reload(): void
    {
        $this->values = null;
    }

    /**
     * The settings form's input as the option stores it. Anything unknown or out of range falls back
     * to its default rather than failing the save.
     *
     * @param mixed $input
     * @return array<string, mixed>
     */
    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        // The settings form marks itself: in it an area's own signals count only with their box
        // ticked, where a stored value, which WordPress sanitizes again on the first save, has them
        // or has null.
        $fromForm = !empty($input['form']);
        $out = [];
        $out['signals'] = self::signalList($input['signals'] ?? []) ?? [];
        $out['countries'] = implode(', ', self::countryCodes((string) ($input['countries'] ?? '')));
        $out['min_hits'] = max(1, min(1000, absint($input['min_hits'] ?? 1)));
        $out['areas'] = [];
        foreach (self::AREAS as $area => $actions) {
            $given = is_array($input['areas'][$area] ?? null) ? $input['areas'][$area] : [];
            $action = sanitize_key((string) ($given['action'] ?? 'off'));
            $own = $fromForm ? !empty($given['own_signals']) : is_array($given['signals'] ?? null);
            $out['areas'][$area] = [
                'action' => in_array($action, $actions, true) ? $action : 'off',
                'signals' => $own ? (self::signalList($given['signals'] ?? []) ?? []) : null,
            ];
        }
        $out['admins_sign_in'] = !empty($input['admins_sign_in']);
        $out['pages_scope'] = ($input['pages_scope'] ?? '') === 'selected' ? 'selected' : 'site';
        $types = array_map('sanitize_key', (array) ($input['pages_post_types'] ?? []));
        $out['pages_post_types'] = array_values(array_intersect($types, get_post_types(['public' => true])));
        $out['pages_paths'] = sanitize_textarea_field((string) ($input['pages_paths'] ?? ''));
        $out['pages_redirect'] = esc_url_raw(trim((string) ($input['pages_redirect'] ?? '')));
        $out['pages_uncached'] = !empty($input['pages_uncached']);
        $out['message'] = sanitize_textarea_field((string) ($input['message'] ?? ''));
        $out['allowlist'] = sanitize_textarea_field((string) ($input['allowlist'] ?? ''));
        $proxy = sanitize_key((string) ($input['proxy'] ?? ''));
        $modes = [ClientAddress::AUTO, ClientAddress::DIRECT, ClientAddress::CLOUDFLARE, ClientAddress::CUSTOM];
        $out['proxy'] = in_array($proxy, $modes, true) ? $proxy : ClientAddress::AUTO;
        $header = (string) ($input['proxy_header'] ?? '');
        $out['proxy_header'] = isset(ClientAddress::HEADERS[$header]) ? $header : 'X-Forwarded-For';
        $out['proxy_trusted'] = sanitize_textarea_field((string) ($input['proxy_trusted'] ?? ''));
        $out['fail_closed'] = !empty($input['fail_closed']);
        $out['log_days'] = max(1, min(365, absint($input['log_days'] ?? 30)));
        return $out;
    }

    /**
     * Non-empty lines of a textarea, each without its `#` comment.
     *
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }

    /**
     * Stored settings over the defaults, so a setting added in a later release reads as its default
     * on a site that saved before it existed.
     *
     * @param mixed $stored
     * @return array<string, mixed>
     */
    private static function normalize($stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $values = array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
        $areas = [];
        foreach (array_keys(self::AREAS) as $area) {
            $given = is_array($values['areas'][$area] ?? null) ? $values['areas'][$area] : [];
            $areas[$area] = [
                'action' => (string) ($given['action'] ?? 'off'),
                'signals' => isset($given['signals']) && is_array($given['signals']) ? $given['signals'] : null,
            ];
        }
        $values['areas'] = $areas;
        return $values;
    }

    /**
     * @param mixed $given
     * @return list<string>|null
     */
    private static function signalList($given): ?array
    {
        if (!is_array($given)) {
            return null;
        }
        $given = array_map('sanitize_key', $given);
        return array_values(array_filter(Signals::keys(), static fn (string $s): bool => in_array($s, $given, true)));
    }
}
