<?php

declare(strict_types=1);

namespace VPNDetection\WordPress\Admin;

use VPNDetection\WordPress\ActivityLog;
use VPNDetection\WordPress\Allowlist;
use VPNDetection\WordPress\Areas\Checkout;
use VPNDetection\WordPress\Areas\Forms;
use VPNDetection\WordPress\ClientAddress;
use VPNDetection\WordPress\Connect;
use VPNDetection\WordPress\Detector;
use VPNDetection\WordPress\Diagnostics;
use VPNDetection\WordPress\Plugin;
use VPNDetection\WordPress\Request;
use VPNDetection\WordPress\Settings;
use VPNDetection\WordPress\Signals;
use VPNDetection\WordPress\Vendor\VPNDetection\Bogon;
use VPNDetection\WordPress\Vendor\VPNDetection\Entitlement;
use VPNDetection\WordPress\Vendor\VPNDetection\VPNDetectionException;

defined('ABSPATH') || exit;

/** Settings, VPNDetection: the account, what to block and where, and the activity log. */
final class Page
{
    public const SLUG = 'vpndetection';
    private const GROUP = 'vpndetection';
    private const ACCOUNT_CACHE = 'vpndetection_account';
    private const SET_UP = 'vpndetection_set_up';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_notices', [$this, 'notices']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_vpndetection_key', [$this, 'saveKey']);
        add_action('admin_post_vpndetection_disconnect', [$this, 'disconnect']);
        add_action('admin_post_vpndetection_allow', [$this, 'allow']);
        add_action('admin_post_vpndetection_clear', [$this, 'clear']);
        add_filter('plugin_action_links_' . plugin_basename(VPNDETECTION_FILE), [$this, 'links']);
        // Activation schedules the purge, but a network activation runs it for one site alone.
        add_action('admin_init', static function (): void {
            if (!wp_next_scheduled(ActivityLog::PURGE_HOOK)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', ActivityLog::PURGE_HOOK);
            }
        });
    }

    public function menu(): void
    {
        add_options_page('VPNDetection', 'VPNDetection', 'manage_options', self::SLUG, [$this, 'render']);
    }

    public function settings(): void
    {
        register_setting(self::GROUP, Settings::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default' => [],
        ]);
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    public function sanitize($input): array
    {
        $clean = Settings::sanitize($input);
        $bad = array_merge(
            Allowlist::invalid($clean['allowlist']),
            array_filter(Settings::lines($clean['proxy_trusted']), static fn (string $l): bool => \VPNDetection\WordPress\Address::block($l) === null),
        );
        if ($bad !== []) {
            add_settings_error(Settings::OPTION, 'vpndetection_invalid', sprintf(
                /* translators: %s: the entries, comma separated. */
                __('These entries are not an address, a block or an AS number, and are ignored: %s', 'vpndetection'),
                implode(', ', array_slice($bad, 0, 10)),
            ), 'warning');
        }
        $on = array_filter($clean['areas'], static fn (array $a): bool => $a['action'] !== 'off');
        $empty = array_filter($on, static fn (array $a): bool => ($a['signals'] ?? $clean['signals']) === []);
        if ($empty !== []) {
            add_settings_error(Settings::OPTION, 'vpndetection_nothing', __(
                'An area is on with nothing ticked to block, so it catches nobody.',
                'vpndetection',
            ), 'warning');
        }
        update_option(self::SET_UP, '1', false);
        $this->plugin->settings->reload();
        return $clean;
    }

    /**
     * @param array<string, string> $links
     * @return array<string, string>
     */
    public function links(array $links): array
    {
        $url = admin_url('options-general.php?page=' . self::SLUG);
        array_unshift($links, sprintf('<a href="%s">%s</a>', esc_url($url), esc_html__('Settings', 'vpndetection')));
        return $links;
    }

    public function assets(string $hook): void
    {
        if ($hook !== 'settings_page_' . self::SLUG) {
            return;
        }
        $version = VPNDETECTION_VERSION;
        wp_enqueue_style('vpndetection-admin', plugins_url('assets/admin.css', VPNDETECTION_FILE), [], $version);
        wp_enqueue_script('vpndetection-admin', plugins_url('assets/admin.js', VPNDETECTION_FILE), [], $version, ['in_footer' => true]);
        wp_localize_script('vpndetection-admin', 'vpndetectionAdmin', [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(Connect::NONCE),
            'text' => [
                'waiting' => __('Approve the sign-in in the tab that opened. If it asks for a code, it is', 'vpndetection'),
                'denied' => __('The sign-in was declined. Nothing changed.', 'vpndetection'),
                'expired' => __('The sign-in code ran out. Start again when you are ready.', 'vpndetection'),
                'nokey' => __('Signed in, but that key cannot be handed over: its secret can no longer be shown. Pick another key, or paste one below.', 'vpndetection'),
                'failed' => __('Something went wrong talking to VPNDetection. Try again, or paste a key below.', 'vpndetection'),
                'popup' => __('Your browser blocked the new tab. Open this link to approve:', 'vpndetection'),
            ],
        ]);
    }

    public function notices(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $screen = get_current_screen();
        $here = $screen !== null && $screen->id === 'settings_page_' . self::SLUG;
        $where = $here || ($screen !== null && in_array($screen->id, ['dashboard', 'plugins'], true));
        $lookup = $this->plugin->diagnostics->recent()['lookup'] ?? null;
        if ($where && is_array($lookup) && Diagnostics::needsOwner((string) $lookup['kind'])) {
            $failClosed = (bool) $this->plugin->settings->get('fail_closed');
            $message = match ((string) $lookup['kind']) {
                'quota_exceeded' => $failClosed
                    ? __('VPNDetection: this site has used its allowance of checks, so visitors are being refused until it resets.', 'vpndetection')
                    : __('VPNDetection: this site has used its allowance of checks, so visitors are let through unchecked until it resets.', 'vpndetection'),
                default => __('VPNDetection refused this site\'s API key, so visitors are not being checked. Connect again, or check the key in the console.', 'vpndetection'),
            };
            printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($message));
        }
        if ($screen !== null && $screen->id === 'plugins' && get_option(self::SET_UP) !== '1') {
            printf(
                '<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
                esc_html__('VPNDetection is active, and checks nothing until you choose what to protect.', 'vpndetection'),
                esc_url(admin_url('options-general.php?page=' . self::SLUG)),
                esc_html__('Set it up', 'vpndetection'),
            );
        }
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which tab to show; nothing is changed.
        $tab = isset($_GET['tab']) && sanitize_key(wp_unslash($_GET['tab'])) === 'activity' ? 'activity' : 'settings';
        $base = admin_url('options-general.php?page=' . self::SLUG);
        echo '<div class="wrap vpnd">';
        echo '<h1>VPNDetection</h1>';
        echo '<nav class="nav-tab-wrapper">';
        foreach (['settings' => __('Settings', 'vpndetection'), 'activity' => __('Activity', 'vpndetection')] as $id => $label) {
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url($id === 'settings' ? $base : add_query_arg('tab', $id, $base)),
                $tab === $id ? ' nav-tab-active' : '',
                esc_html($label),
            );
        }
        echo '</nav>';
        $this->flash();
        if ($tab === 'activity') {
            (new ActivityScreen($this->plugin))->render();
        } else {
            $this->renderSettings();
        }
        echo '</div>';
    }

    private function renderSettings(): void
    {
        settings_errors(Settings::OPTION);
        $settings = $this->plugin->settings;
        $account = $this->account();
        $tier = $account instanceof Entitlement ? $account->plan->tier : null;
        $this->renderAccount($account);
        $this->renderConnection($tier);
        echo '<form method="post" action="options.php" class="vpnd-form">';
        settings_fields(self::GROUP);
        printf('<input type="hidden" name="%s[form]" value="1">', esc_attr(Settings::OPTION));
        $this->renderSignals($settings, $tier);
        $this->renderAreas($settings, $tier);
        $this->renderMessage($settings);
        $this->renderAllowlist($settings);
        $this->renderAdvanced($settings);
        submit_button();
        echo '</form>';
    }

    /** @param Entitlement|VPNDetectionException|null $account */
    private function renderAccount($account): void
    {
        $settings = $this->plugin->settings;
        echo '<div class="vpnd-card" id="vpnd-account">';
        echo '<h2>' . esc_html__('Account', 'vpndetection') . '</h2>';
        if ($account instanceof Entitlement) {
            $usage = $account->usage;
            printf(
                '<p><strong>%s</strong> %s</p>',
                esc_html(sprintf(
                    /* translators: %s: the plan's name, such as "Free". */
                    __('Connected, on the %s plan.', 'vpndetection'),
                    ucfirst($account->plan->key),
                )),
                esc_html(sprintf(
                    /* translators: 1: checks made; 2: checks included; 3: a date. */
                    __('%1$s of %2$s checks used this period, which resets on %3$s.', 'vpndetection'),
                    number_format_i18n($usage->requests),
                    number_format_i18n($usage->quota),
                    wp_date(get_option('date_format'), $usage->windowEnd->getTimestamp()),
                )),
            );
            echo '<div class="vpnd-actions">';
            printf(
                '<a class="button" href="%s" target="_blank" rel="noopener">%s</a>',
                esc_url(self::console('/billing/plan', 'plan')),
                $account->plan->tier === 'free' ? esc_html__('Upgrade for every signal', 'vpndetection') : esc_html__('Manage plan', 'vpndetection'),
            );
            if (!$settings->keyInConfig()) {
                $this->postButton('vpndetection_disconnect', __('Disconnect', 'vpndetection'), 'button-link-delete', __('Disconnect this site? It goes back to checking for VPNs only, with the shared allowance.', 'vpndetection'));
            }
            echo '</div>';
            if ($settings->keyInConfig()) {
                echo '<p class="description">' . esc_html__('Using the API key set as VPNDETECTION_API_KEY in wp-config.php.', 'vpndetection') . '</p>';
            }
            echo '</div>';
            return;
        }
        if ($account instanceof VPNDetectionException) {
            printf(
                '<div class="notice notice-error inline"><p>%s</p></div>',
                esc_html(sprintf(
                    /* translators: %s: the reason, as VPNDetection gave it. */
                    __('VPNDetection did not accept this site\'s API key: %s', 'vpndetection'),
                    $account->getMessage(),
                )),
            );
        }
        if ($settings->keyInConfig()) {
            echo '<p>' . esc_html__('This site uses the API key set as VPNDETECTION_API_KEY in wp-config.php.', 'vpndetection') . '</p></div>';
            return;
        }
        echo '<p>' . esc_html__('Connect a free VPNDetection account to check for Tor and private relays as well as VPNs, with 50,000 checks a month of your own. Paid plans add every proxy and hosting signal.', 'vpndetection') . '</p>';
        echo '<p class="description">' . esc_html__('Without an account the plugin checks for VPNs only, and shares 1,000 checks a day with everything else on this server\'s address.', 'vpndetection') . '</p>';
        printf(
            '<p><button type="button" class="button button-primary" id="vpnd-connect">%s</button> <span id="vpnd-connect-status" aria-live="polite"></span></p>',
            esc_html__('Connect with VPNDetection', 'vpndetection'),
        );
        echo '<details class="vpnd-key"><summary>' . esc_html__('Or paste an API key', 'vpndetection') . '</summary>';
        printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
        echo '<input type="hidden" name="action" value="vpndetection_key">';
        wp_nonce_field('vpndetection_key');
        printf(
            '<p><label for="vpnd-api-key" class="screen-reader-text">%1$s</label><input type="password" id="vpnd-api-key" name="api_key" class="regular-text" autocomplete="off" placeholder="%1$s"> ',
            esc_attr__('API key', 'vpndetection'),
        );
        submit_button(__('Save key', 'vpndetection'), 'secondary', 'submit', false);
        printf(
            '</p><p class="description">%s <a href="%s" target="_blank" rel="noopener">%s</a></p>',
            esc_html__('Your keys are in the console.', 'vpndetection'),
            esc_url(self::console('/api/keys', 'api-keys')),
            esc_html__('Open the console', 'vpndetection'),
        );
        echo '</form></details></div>';
    }

    /** What the plugin sees of the person looking at this page: the self-check. */
    private function renderConnection(?string $tier): void
    {
        $detector = $this->plugin->detector;
        $server = Request::server();
        $ip = $detector->address();
        echo '<div class="vpnd-card" id="vpnd-connection">';
        echo '<h2>' . esc_html__('Your connection', 'vpndetection') . '</h2>';
        if ($ip === null) {
            echo '<p>' . esc_html__('This request carried no address the plugin could read.', 'vpndetection') . '</p></div>';
            return;
        }
        $visit = $detector->visit();
        $result = $visit->result;
        $where = [];
        if ($result?->location !== null && $result->location->countryName !== '') {
            $where[] = $result->location->countryName;
        }
        if ($result?->asn !== null && $result->asn->asn > 0) {
            $where[] = sprintf('AS%d %s', $result->asn->asn, $result->asn->name);
        }
        printf(
            '<p><code>%s</code>%s</p>',
            esc_html($ip),
            $where === [] ? '' : ' ' . esc_html(implode(', ', $where)),
        );
        if (Bogon::isBogon($ip)) {
            echo '<p class="vpnd-warn">' . esc_html__('That is a private address, so a proxy or load balancer sits in front of this site and the plugin cannot see past it. Every visitor looks like this one and nobody is flagged. Set Visitor address under Advanced to the proxy in front of you.', 'vpndetection') . '</p>';
        } elseif ($visit->allowed) {
            echo '<p>' . esc_html__('Your allowlist covers this address, so it is never checked.', 'vpndetection') . '</p>';
        } elseif ($visit->error !== null) {
            printf('<p class="vpnd-warn">%s</p>', esc_html(sprintf(
                /* translators: %s: the reason, as VPNDetection gave it. */
                __('The check failed: %s', 'vpndetection'),
                $visit->error->getMessage(),
            )));
        } elseif ($result !== null) {
            $flagged = [];
            foreach (Signals::keys() as $signal) {
                $member = Signals::ALL[$signal]['member'];
                if ($result->{$member} === true) {
                    $flagged[] = Signals::label($signal);
                }
            }
            if ($flagged === []) {
                echo '<p>' . esc_html__('Not a VPN, proxy, Tor or hosting address, as far as your plan answers.', 'vpndetection') . '</p>';
            } else {
                printf('<p class="vpnd-warn">%s</p>', esc_html(sprintf(
                    /* translators: %s: what the connection is, such as "VPNs, Hosting and cloud servers". */
                    __('Your connection counts as: %s. An area set to block these blocks you too, except sign-in while administrators can always sign in. Put your address on the allowlist to be safe.', 'vpndetection'),
                    implode(', ', $flagged),
                )));
            }
        }
        $private = $this->plugin->diagnostics->recent()['address']['ip'] ?? '';
        if (is_string($private) && $private !== '' && !Bogon::isBogon($ip)) {
            printf('<p class="vpnd-warn">%s</p>', esc_html(sprintf(
                /* translators: %s: an IP address. */
                __('In the last day some visitors reached the plugin as %s, a private address, so nobody behind it was checked. A proxy sits in front of those requests: set Visitor address under Advanced to trust it.', 'vpndetection'),
                $private,
            )));
        }
        $peer = $server['REMOTE_ADDR'] ?? '';
        $forwarded = array_filter(ClientAddress::HEADERS, static fn (string $var): bool => ($server[$var] ?? '') !== '');
        if ($forwarded !== [] && $ip === \VPNDetection\WordPress\Address::normalize($peer) && !Bogon::isBogon($ip)) {
            printf('<p class="description">%s</p>', esc_html(sprintf(
                /* translators: 1: an IP address; 2: header names. */
                __('Your request came from %1$s and carried %2$s, which the plugin does not believe from that address. If %1$s is your CDN or proxy, set Visitor address under Advanced to trust it.', 'vpndetection'),
                $peer,
                implode(', ', array_keys($forwarded)),
            )));
        }
        echo '</div>';
    }

    private function renderSignals(Settings $settings, ?string $tier): void
    {
        echo '<h2>' . esc_html__('What to block', 'vpndetection') . '</h2>';
        echo '<p class="description">' . esc_html__('Every area below acts on these, unless you give it signals of its own.', 'vpndetection') . '</p>';
        $gaps = $this->plugin->diagnostics->recent()['gaps']['signals'] ?? [];
        if (is_array($gaps) && $gaps !== []) {
            printf('<div class="notice notice-warning inline"><p>%s</p></div>', esc_html(sprintf(
                /* translators: %s: signals, such as "Residential proxies, Hosting and cloud servers". */
                __('Ticked, but not answered on this site\'s plan, so they block nobody: %s.', 'vpndetection'),
                implode(', ', array_map([Signals::class, 'label'], $gaps)),
            )));
        }
        $this->signalBoxes(Settings::OPTION . '[signals][]', $settings->get('signals'), $tier, 'vpnd-signals', $settings);
    }

    /**
     * @param list<string> $checked
     * @param Settings|null $countries The settings to read the country list from, where it is edited.
     */
    private function signalBoxes(string $name, array $checked, ?string $tier, string $class, ?Settings $countries = null): void
    {
        printf('<fieldset class="%s">', esc_attr($class));
        foreach (Signals::keys() as $signal) {
            $badge = '';
            if (!Signals::served($signal, $tier)) {
                $badge = Signals::ALL[$signal]['needs'] === Signals::NEEDS_KEY
                    ? __('Needs a free account', 'vpndetection')
                    : __('Needs a paid plan', 'vpndetection');
            }
            $list = '';
            if ($signal === 'country' && $countries !== null) {
                $list = sprintf(
                    '<input type="text" name="%s[countries]" value="%s" class="regular-text code vpnd-countries" placeholder="%s" aria-label="%s">',
                    esc_attr(Settings::OPTION),
                    esc_attr($countries->get('countries')),
                    esc_attr__('CN, RU', 'vpndetection'),
                    esc_attr__('Two-letter country codes, separated by commas', 'vpndetection'),
                );
            }
            printf(
                '<label class="vpnd-signal"><input type="checkbox" name="%s" value="%s"%s> <strong>%s</strong>%s<span class="description">%s</span>%s</label>',
                esc_attr($name),
                esc_attr($signal),
                checked(in_array($signal, $checked, true), true, false),
                esc_html(Signals::label($signal)),
                $badge === '' ? '' : ' <span class="vpnd-badge">' . esc_html($badge) . '</span>',
                esc_html(Signals::hint($signal)),
                $list, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
            );
        }
        echo '</fieldset>';
    }

    private function renderAreas(Settings $settings, ?string $tier): void
    {
        echo '<h2>' . esc_html__('Where to check', 'vpndetection') . '</h2>';
        echo '<p class="description">' . esc_html__('Start an area on Log only to see who it would catch on the Activity tab before it blocks anyone.', 'vpndetection') . '</p>';
        echo '<table class="form-table vpnd-areas" role="presentation"><tbody>';
        foreach (array_keys(Settings::AREAS) as $area) {
            if ($area === 'checkout' && !Checkout::available()) {
                continue;
            }
            if ($area === 'forms' && !Forms::available()) {
                continue;
            }
            $this->renderArea($settings, $area, $tier);
        }
        echo '</tbody></table>';
    }

    private function renderArea(Settings $settings, string $area, ?string $tier): void
    {
        [$title, $about] = self::areaText($area);
        $base = Settings::OPTION . '[areas][' . $area . ']';
        $own = $settings->get('areas')[$area]['signals'];
        printf('<tr class="vpnd-area" data-area="%s"><th scope="row"><label for="vpnd-%s">%s</label></th><td>', esc_attr($area), esc_attr($area), esc_html($title));
        printf('<select id="vpnd-%s" name="%s[action]" class="vpnd-action">', esc_attr($area), esc_attr($base));
        foreach (Settings::AREAS[$area] as $action) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($action),
                selected($settings->action($area), $action, false),
                esc_html(self::actionLabel($area, $action)),
            );
        }
        echo '</select>';
        printf('<p class="description">%s</p>', esc_html($about));
        echo '<div class="vpnd-when-on">';
        if ($area === 'login') {
            printf(
                '<p><label><input type="checkbox" name="%s[admins_sign_in]" value="1"%s> %s</label></p>',
                esc_attr(Settings::OPTION),
                checked((bool) $settings->get('admins_sign_in'), true, false),
                esc_html__('Administrators can always sign in, whatever their connection', 'vpndetection'),
            );
        }
        if ($area === 'pages') {
            $this->renderPagesScope($settings);
        }
        printf(
            '<p><label><input type="checkbox" class="vpnd-own" name="%s[own_signals]" value="1"%s> %s</label></p>',
            esc_attr($base),
            checked($own !== null, true, false),
            esc_html__('Block on different signals here', 'vpndetection'),
        );
        $this->signalBoxes($base . '[signals][]', $own ?? $settings->get('signals'), $tier, 'vpnd-signals vpnd-own-signals');
        echo '</div></td></tr>';
    }

    private function renderPagesScope(Settings $settings): void
    {
        $name = Settings::OPTION;
        $scope = $settings->get('pages_scope');
        echo '<fieldset class="vpnd-scope">';
        printf(
            '<p><label><input type="radio" name="%s[pages_scope]" value="site"%s> %s</label><br>',
            esc_attr($name),
            checked($scope, 'site', false),
            esc_html__('The whole site', 'vpndetection'),
        );
        printf(
            '<label><input type="radio" name="%s[pages_scope]" value="selected"%s> %s</label></p>',
            esc_attr($name),
            checked($scope, 'selected', false),
            esc_html__('Only these:', 'vpndetection'),
        );
        echo '<div class="vpnd-selected"><p>';
        foreach (get_post_types(['public' => true], 'objects') as $type) {
            if ($type->name === 'attachment') {
                continue;
            }
            printf(
                '<label class="vpnd-inline"><input type="checkbox" name="%s[pages_post_types][]" value="%s"%s> %s</label> ',
                esc_attr($name),
                esc_attr($type->name),
                checked(in_array($type->name, $settings->get('pages_post_types'), true), true, false),
                esc_html($type->labels->name),
            );
        }
        printf(
            '</p><p><label for="vpnd-paths">%s</label><br><textarea id="vpnd-paths" name="%s[pages_paths]" rows="3" class="large-text code" placeholder="/members/">%s</textarea></p></div>',
            esc_html__('Pages whose address starts with any of these paths, one per line:', 'vpndetection'),
            esc_attr($name),
            esc_textarea($settings->get('pages_paths')),
        );
        printf(
            '<p><label for="vpnd-redirect">%s</label><br><input type="url" id="vpnd-redirect" name="%s[pages_redirect]" value="%s" class="regular-text" placeholder="https://"></p>',
            esc_html__('Send refused visitors to this page instead of showing the message (optional):', 'vpndetection'),
            esc_attr($name),
            esc_attr($settings->get('pages_redirect')),
        );
        printf(
            '<p><label><input type="checkbox" name="%s[pages_uncached]" value="1"%s> %s</label><br><span class="description">%s</span></p>',
            esc_attr($name),
            checked((bool) $settings->get('pages_uncached'), true, false),
            esc_html__('Keep these pages out of the page cache, so the server checks every visit', 'vpndetection'),
            esc_html__('Otherwise a cached page is checked in the visitor\'s browser, which works with any cache but not for a visitor who turns scripts off. Signed-in users and search engine crawlers are never refused.', 'vpndetection'),
        );
        echo '</fieldset>';
    }

    private function renderMessage(Settings $settings): void
    {
        echo '<h2>' . esc_html__('What a refused visitor sees', 'vpndetection') . '</h2>';
        printf(
            '<p><label for="vpnd-message" class="screen-reader-text">%s</label><textarea id="vpnd-message" name="%s[message]" rows="3" class="large-text" placeholder="%s">%s</textarea></p>',
            esc_html__('Message', 'vpndetection'),
            esc_attr(Settings::OPTION),
            esc_attr((new Settings())->message()),
            esc_textarea($settings->get('message')),
        );
    }

    private function renderAllowlist(Settings $settings): void
    {
        echo '<h2>' . esc_html__('Allowlist', 'vpndetection') . '</h2>';
        printf(
            '<p><label for="vpnd-allowlist">%s</label></p><p><textarea id="vpnd-allowlist" name="%s[allowlist]" rows="5" class="large-text code" placeholder="203.0.113.7&#10;198.51.100.0/24&#10;AS64500 # our office VPN">%s</textarea></p>',
            esc_html__('Never refuse these. One per line: an address, a block in CIDR form, or a network by its AS number. Text after # is a note.', 'vpndetection'),
            esc_attr(Settings::OPTION),
            esc_textarea($settings->get('allowlist')),
        );
    }

    private function renderAdvanced(Settings $settings): void
    {
        $name = Settings::OPTION;
        echo '<details class="vpnd-advanced"><summary><h2>' . esc_html__('Advanced', 'vpndetection') . '</h2></summary>';
        echo '<table class="form-table" role="presentation"><tbody>';
        printf('<tr><th scope="row"><label for="vpnd-proxy">%s</label></th><td>', esc_html__('Visitor address', 'vpndetection'));
        printf('<select id="vpnd-proxy" name="%s[proxy]">', esc_attr($name));
        $modes = [
            ClientAddress::AUTO => __('Work it out (recommended)', 'vpndetection'),
            ClientAddress::DIRECT => __('Visitors connect to this server directly', 'vpndetection'),
            ClientAddress::CLOUDFLARE => __('Behind Cloudflare', 'vpndetection'),
            ClientAddress::CUSTOM => __('Behind another proxy, CDN or load balancer', 'vpndetection'),
        ];
        foreach ($modes as $mode => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($mode), selected($settings->get('proxy'), $mode, false), esc_html($label));
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Working it out believes Cloudflare from Cloudflare\'s own addresses, and a proxy on your server\'s private network. Anything else in front of the site has to be named here, or every visitor looks like it.', 'vpndetection') . '</p>';
        echo '<div class="vpnd-custom-proxy">';
        printf('<p><label for="vpnd-header">%s</label> <select id="vpnd-header" name="%s[proxy_header]">', esc_html__('It names the visitor in', 'vpndetection'), esc_attr($name));
        foreach (array_keys(ClientAddress::HEADERS) as $header) {
            printf('<option value="%s"%s>%s</option>', esc_attr($header), selected($settings->get('proxy_header'), $header, false), esc_html($header));
        }
        printf(
            '</select></p><p><label for="vpnd-trusted">%s</label><br><textarea id="vpnd-trusted" name="%s[proxy_trusted]" rows="3" class="large-text code" placeholder="192.0.2.0/24">%s</textarea></p></div></td></tr>',
            esc_html__('Its addresses, one address or CIDR block per line:', 'vpndetection'),
            esc_attr($name),
            esc_textarea($settings->get('proxy_trusted')),
        );
        printf(
            '<tr><th scope="row"><label for="vpnd-hits">%s</label></th><td><input type="number" min="1" max="1000" id="vpnd-hits" name="%s[min_hits]" value="%d" class="small-text"><p class="description">%s</p></td></tr>',
            esc_html__('Proxy sightings', 'vpndetection'),
            esc_attr($name),
            (int) $settings->get('min_hits'),
            esc_html__('Residential, mobile and datacenter proxies count only once an address has been seen in a proxy pool this many times. 1 counts every sighting; a higher number spares home addresses a proxy used once.', 'vpndetection'),
        );
        printf('<tr><th scope="row">%s</th><td><fieldset>', esc_html__('When a check fails', 'vpndetection'));
        printf(
            '<label><input type="radio" name="%s[fail_closed]" value="0"%s> %s</label><br><label><input type="radio" name="%s[fail_closed]" value="1"%s> %s</label>',
            esc_attr($name),
            checked((bool) $settings->get('fail_closed'), false, false),
            esc_html__('Let the visitor through (recommended)', 'vpndetection'),
            esc_attr($name),
            checked((bool) $settings->get('fail_closed'), true, false),
            esc_html__('Refuse the visitor', 'vpndetection'),
        );
        echo '<p class="description">' . esc_html__('A check fails when VPNDetection cannot be reached in time, or the allowance is spent.', 'vpndetection') . '</p></fieldset></td></tr>';
        printf(
            '<tr><th scope="row"><label for="vpnd-days">%s</label></th><td><input type="number" min="1" max="365" id="vpnd-days" name="%s[log_days]" value="%d" class="small-text"> %s</td></tr>',
            esc_html__('Keep activity for', 'vpndetection'),
            esc_attr($name),
            (int) $settings->get('log_days'),
            esc_html__('days', 'vpndetection'),
        );
        echo '</tbody></table></details>';
    }

    public function saveKey(): void
    {
        $this->guard();
        check_admin_referer('vpndetection_key');
        $key = isset($_POST['api_key']) ? trim(sanitize_text_field(wp_unslash($_POST['api_key']))) : '';
        if ($key === '') {
            $this->back('nokey');
        }
        try {
            Detector::newClient($key, 10.0, 1)->myEntitlement();
        } catch (VPNDetectionException $e) {
            $this->back('badkey', $e->getMessage());
        }
        update_option(Settings::KEY_OPTION, $key, true);
        delete_transient(self::ACCOUNT_CACHE);
        $this->plugin->diagnostics->clear('lookup');
        $this->back('connected');
    }

    public function disconnect(): void
    {
        $this->guard();
        check_admin_referer('vpndetection_disconnect');
        delete_option(Settings::KEY_OPTION);
        delete_transient(self::ACCOUNT_CACHE);
        $this->plugin->diagnostics->clear('lookup');
        $this->plugin->diagnostics->clear('gaps');
        $this->back('disconnected');
    }

    public function allow(): void
    {
        $this->guard();
        check_admin_referer('vpndetection_allow');
        $ip = isset($_GET['ip']) ? \VPNDetection\WordPress\Address::normalize(sanitize_text_field(wp_unslash($_GET['ip']))) : null;
        if ($ip !== null) {
            $stored = get_option(Settings::OPTION, []);
            $stored = is_array($stored) ? $stored : [];
            $list = (string) ($stored['allowlist'] ?? '');
            if (!Allowlist::parse($list)->coversAddress($ip)) {
                $stored['allowlist'] = rtrim($list) . ($list === '' ? '' : "\n") . $ip . ' # ' . wp_date('Y-m-d');
                update_option(Settings::OPTION, $stored);
            }
        }
        $this->back('allowed', (string) $ip, 'activity');
    }

    public function clear(): void
    {
        $this->guard();
        check_admin_referer('vpndetection_clear');
        $this->plugin->log->clear();
        $this->back('cleared', '', 'activity');
    }

    /**
     * The account behind the key, read at most every five minutes.
     *
     * @return Entitlement|VPNDetectionException|null
     */
    private function account()
    {
        $key = $this->plugin->settings->apiKey();
        if ($key === null) {
            return null;
        }
        $cached = get_transient(self::ACCOUNT_CACHE);
        if (is_array($cached) && ($cached['key'] ?? '') === hash('sha256', $key) && $cached['account'] instanceof Entitlement) {
            return $cached['account'];
        }
        try {
            $account = Detector::newClient($key, 10.0, 1)->myEntitlement();
        } catch (VPNDetectionException $e) {
            return $e;
        }
        set_transient(self::ACCOUNT_CACHE, ['key' => hash('sha256', $key), 'account' => $account], 5 * MINUTE_IN_SECONDS);
        return $account;
    }

    /** A one-click form posting to admin-post.php, with its nonce and an optional confirmation. */
    public function postButton(string $action, string $label, string $class, string $confirm = '', array $fields = []): void
    {
        printf(
            '<form method="post" action="%s" class="vpnd-inline-form"%s>',
            esc_url(admin_url('admin-post.php')),
            $confirm === '' ? '' : ' data-confirm="' . esc_attr($confirm) . '"',
        );
        printf('<input type="hidden" name="action" value="%s">', esc_attr($action));
        foreach ($fields as $field => $value) {
            printf('<input type="hidden" name="%s" value="%s">', esc_attr($field), esc_attr($value));
        }
        wp_nonce_field($action);
        printf('<button type="submit" class="button %s">%s</button></form>', esc_attr($class), esc_html($label));
    }

    private function guard(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to change this.', 'vpndetection'), '', ['response' => 403]);
        }
    }

    private function back(string $done, string $detail = '', string $tab = 'settings'): never
    {
        $url = add_query_arg(
            array_filter(['page' => self::SLUG, 'tab' => $tab === 'settings' ? '' : $tab, 'vpnd' => $done, 'vpnd_detail' => $detail]),
            admin_url('options-general.php'),
        );
        wp_safe_redirect($url);
        exit;
    }

    /** The result of an action this page posted, shown once after its redirect. */
    private function flash(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- shows the result of an action already checked.
        $done = isset($_GET['vpnd']) ? sanitize_key(wp_unslash($_GET['vpnd'])) : '';
        $detail = isset($_GET['vpnd_detail']) ? sanitize_text_field(wp_unslash($_GET['vpnd_detail'])) : '';
        // phpcs:enable
        $messages = [
            'connected' => ['success', __('Connected. The plugin now checks with your own allowance.', 'vpndetection')],
            'disconnected' => ['success', __('Disconnected. The plugin is back to checking for VPNs only.', 'vpndetection')],
            'nokey' => ['error', __('Paste a key first.', 'vpndetection')],
            /* translators: %s: the reason, as VPNDetection gave it. */
            'badkey' => ['error', sprintf(__('VPNDetection did not accept that key: %s', 'vpndetection'), $detail)],
            /* translators: %s: an IP address. */
            'allowed' => ['success', sprintf(__('%s is on the allowlist and will not be refused again.', 'vpndetection'), $detail)],
            'cleared' => ['success', __('Activity cleared.', 'vpndetection')],
        ];
        if (isset($messages[$done])) {
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($messages[$done][0]), esc_html($messages[$done][1]));
        }
    }

    /** A console link, tagged so a sign-up that starts from the plugin is counted as the plugin's. */
    public static function console(string $path, string $content): string
    {
        return self::tagged('https://app.vpndetection.io' . $path, $content);
    }

    public static function tagged(string $url, string $content): string
    {
        return add_query_arg([
            'utm_source' => 'wordpress',
            'utm_medium' => 'referral',
            'utm_campaign' => 'plugin',
            'utm_content' => $content,
        ], $url);
    }

    /** @return array{0: string, 1: string} */
    public static function areaText(string $area): array
    {
        return match ($area) {
            'login' => [__('Sign-in', 'vpndetection'), __('The login page, front-end login forms such as WooCommerce\'s, and XML-RPC.', 'vpndetection')],
            'register' => [__('New accounts', 'vpndetection'), __('WordPress\'s registration page, a network\'s sign-up and WooCommerce\'s My Account.', 'vpndetection')],
            'lostpassword' => [__('Password resets', 'vpndetection'), __('Asking for a reset email, on WordPress and WooCommerce.', 'vpndetection')],
            'comment' => [__('Comments', 'vpndetection'), __('Comments from visitors who are not signed in.', 'vpndetection')],
            'forms' => [__('Contact forms', 'vpndetection'), __('Contact Form 7 and WPForms submissions.', 'vpndetection')],
            'checkout' => [__('Checkout', 'vpndetection'), __('WooCommerce orders, classic and block checkout. A held order is paid as usual and then waits On hold, with a note saying why.', 'vpndetection')],
            'pages' => [__('Pages', 'vpndetection'), __('Viewing the site, for visitors who are not signed in.', 'vpndetection')],
            default => [$area, ''],
        };
    }

    public static function actionLabel(string $area, string $action): string
    {
        return match ($action) {
            'off' => __('Off', 'vpndetection'),
            'log' => __('Log only', 'vpndetection'),
            'hold' => $area === 'checkout' ? __('Hold the order for review', 'vpndetection') : __('Hold for moderation', 'vpndetection'),
            'spam' => __('Mark as spam', 'vpndetection'),
            'block' => $area === 'checkout' ? __('Refuse the order', 'vpndetection') : __('Block', 'vpndetection'),
            default => $action,
        };
    }
}
