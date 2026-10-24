<?php

declare(strict_types=1);

namespace VPNDetection\WordPress\Areas;

use VPNDetection\WordPress\Address;
use VPNDetection\WordPress\Detector;
use VPNDetection\WordPress\Ranges;
use VPNDetection\WordPress\Request;
use VPNDetection\WordPress\Settings;
use VPNDetection\WordPress\Verdict;

defined('ABSPATH') || exit;

/**
 * The site's pages, all of them or the post types and paths chosen, for visitors who are not signed
 * in.
 *
 * A page cache serves its copy without running WordPress, so a page checked only here would reach
 * every visitor the cache answers. Two ways round it: a covered page carries a small script that asks
 * the site whether this visitor may see it, from the browser, which works with any cache; or the
 * covered pages are kept out of the cache, so the server checks every visit. A refusal is never
 * cached either way.
 *
 * Search engines crawl from cloud addresses, which a hosting rule would refuse, so their published
 * crawler addresses are never refused here.
 */
final class Pages
{
    public const SCRIPT = 'vpndetection-check';

    /** @var list<array{0: string, 1: string}>|null */
    private static ?array $crawlers = null;

    public function __construct(private readonly Settings $settings, private readonly Detector $detector)
    {
    }

    public function register(): void
    {
        add_action('template_redirect', [$this, 'gate'], 1);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    public function gate(): void
    {
        if (!$this->applies() || !$this->coversCurrent()) {
            return;
        }
        if ($this->settings->get('pages_uncached')) {
            self::uncached();
        }
        $verdict = $this->judge(Request::path());
        if ($verdict !== null && $verdict->enforced()) {
            $this->refuse($verdict);
        }
    }

    /** The script that checks a cached copy of a covered page, in the visitor's browser. */
    public function enqueue(): void
    {
        if ($this->settings->get('pages_uncached') || !$this->applies() || !$this->coversCurrent()) {
            return;
        }
        $file = VPNDETECTION_DIR . 'assets/check.js';
        wp_enqueue_script(
            self::SCRIPT,
            plugins_url('assets/check.js', VPNDETECTION_FILE),
            [],
            (string) filemtime($file),
            ['strategy' => 'defer', 'in_footer' => true],
        );
        wp_localize_script(self::SCRIPT, 'vpndetectionCheck', [
            'url' => esc_url_raw(rest_url('vpndetection/v1/check')),
            'title' => __('Access denied', 'vpndetection'),
        ]);
    }

    /**
     * What the browser check asks: may this visitor see the page at this path?
     *
     * @return array{blocked: bool, message?: string, redirect?: string}
     */
    public function check(string $path): array
    {
        if ($this->settings->action('pages') === 'off' || wp_validate_auth_cookie('', 'logged_in') !== false) {
            return ['blocked' => false];
        }
        if ($this->isRedirectTarget($path) || !$this->coversPath($path)) {
            return ['blocked' => false];
        }
        $verdict = $this->judge($path);
        if ($verdict === null || !$verdict->enforced()) {
            return ['blocked' => false];
        }
        $redirect = $this->redirect();
        return $redirect !== '' ? ['blocked' => true, 'redirect' => $redirect] : ['blocked' => true, 'message' => $this->settings->message($verdict)];
    }

    /** Never let a page cache keep this response. */
    public static function uncached(): void
    {
        if (!defined('DONOTCACHEPAGE')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the constant every page cache plugin reads.
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own hook.
        do_action('litespeed_control_set_nocache', 'vpndetection');
    }

    private function judge(string $path): ?Verdict
    {
        $ip = $this->detector->address();
        if ($ip !== null && Address::inBlocks($ip, self::crawlers())) {
            return null;
        }
        return $this->detector->judge('pages', ['path' => mb_substr($path, 0, 191)]);
    }

    private function refuse(Verdict $verdict): never
    {
        self::uncached();
        $redirect = $this->redirect();
        if ($redirect !== '') {
            // The page the site owner chose, wherever it is.
            $host = (string) wp_parse_url($redirect, PHP_URL_HOST);
            add_filter('allowed_redirect_hosts', static fn (array $hosts): array => [...$hosts, $host]);
            wp_safe_redirect($redirect, 302, 'VPNDetection');
            exit;
        }
        wp_die(
            esc_html($this->settings->message($verdict)),
            esc_html__('Access denied', 'vpndetection'),
            ['response' => 403],
        );
        exit;
    }

    /** Where a refused visitor is sent instead of the refusal, or an empty string for none. */
    private function redirect(): string
    {
        return (string) $this->settings->get('pages_redirect');
    }

    /** Whether the path is the page refused visitors are sent to, which is never refused itself. */
    private function isRedirectTarget(string $path): bool
    {
        $redirect = $this->redirect();
        if ($redirect === '' || wp_parse_url($redirect, PHP_URL_HOST) !== wp_parse_url(home_url(), PHP_URL_HOST)) {
            return false;
        }
        $target = untrailingslashit((string) wp_parse_url($redirect, PHP_URL_PATH));
        return $target === untrailingslashit((string) wp_parse_url($path, PHP_URL_PATH));
    }

    /** Whether this request is one the area checks at all. */
    private function applies(): bool
    {
        if ($this->settings->action('pages') === 'off' || is_user_logged_in()) {
            return false;
        }
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return false;
        }
        return !(is_feed() || is_robots() || is_favicon() || is_trackback() || is_embed() || get_query_var('sitemap'));
    }

    private function coversCurrent(): bool
    {
        if ($this->isRedirectTarget(Request::path())) {
            return false;
        }
        if ($this->settings->get('pages_scope') === 'site') {
            return true;
        }
        $types = $this->settings->get('pages_post_types');
        if ($types !== [] && (is_singular($types) || is_post_type_archive($types))) {
            return true;
        }
        return $this->coversPrefix(Request::path());
    }

    private function coversPath(string $path): bool
    {
        if ($this->settings->get('pages_scope') === 'site' || $this->coversPrefix($path)) {
            return true;
        }
        $types = $this->settings->get('pages_post_types');
        $post = $types === [] ? 0 : url_to_postid(home_url($path));
        return $post > 0 && in_array(get_post_type($post), $types, true);
    }

    private function coversPrefix(string $path): bool
    {
        $path = '/' . ltrim((string) wp_parse_url($path, PHP_URL_PATH), '/');
        foreach (Settings::lines($this->settings->get('pages_paths')) as $prefix) {
            $prefix = '/' . ltrim((string) wp_parse_url($prefix, PHP_URL_PATH), '/');
            if ($prefix !== '/' && str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function crawlers(): array
    {
        return self::$crawlers ??= Address::blocks(array_merge(Ranges::GOOGLE, Ranges::BING, Ranges::APPLE, Ranges::DUCKDUCKGO));
    }
}
