<?php

declare(strict_types=1);

namespace VPNDetection\WordPress\Admin;

use VPNDetection\WordPress\ActivityLog;
use VPNDetection\WordPress\Plugin;
use VPNDetection\WordPress\Settings;
use VPNDetection\WordPress\Signals;

defined('ABSPATH') || exit;

/** The Activity tab: who each area caught, and the one click that lets an address through for good. */
final class ActivityScreen
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function render(): void
    {
        $this->renderSummary();
        $table = new ActivityTable($this->plugin);
        $table->prepare_items();
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="' . esc_attr(Page::SLUG) . '"><input type="hidden" name="tab" value="activity">';
        $table->search_box(__('Search activity', 'vpndetection'), 'vpnd-search');
        $table->display();
        echo '</form>';
        echo '<div class="vpnd-actions">';
        (new Page($this->plugin))->postButton(
            'vpndetection_clear',
            __('Clear activity', 'vpndetection'),
            'button-link-delete',
            __('Clear every row of activity? This cannot be undone.', 'vpndetection'),
        );
        printf(
            '<span class="description">%s</span></div>',
            esc_html(sprintf(
                /* translators: %d: a number of days. */
                _n('Activity is kept for %d day.', 'Activity is kept for %d days.', (int) $this->plugin->settings->get('log_days'), 'vpndetection'),
                (int) $this->plugin->settings->get('log_days'),
            )),
        );
    }

    private function renderSummary(): void
    {
        $rows = $this->plugin->log->summary(7);
        echo '<h2>' . esc_html__('The last 7 days', 'vpndetection') . '</h2>';
        if ($rows === []) {
            echo '<p>' . esc_html__('Nobody caught.', 'vpndetection') . '</p>';
            return;
        }
        usort($rows, static fn (array $a, array $b): int => $b['hits'] <=> $a['hits']);
        printf(
            '<table class="widefat striped vpnd-summary"><thead><tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>',
            esc_html__('Where', 'vpndetection'),
            esc_html__('Outcome', 'vpndetection'),
            esc_html__('Visitors', 'vpndetection'),
            esc_html__('Attempts', 'vpndetection'),
        );
        foreach ($rows as $row) {
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html(Page::areaText($row['area'])[0]),
                esc_html(Page::actionLabel($row['area'], $row['action'])),
                esc_html(number_format_i18n($row['visitors'])),
                esc_html(number_format_i18n($row['hits'])),
            );
        }
        echo '</tbody></table>';
    }

    /** What a row's detail column holds, for people. */
    public static function detail(string $area, string $detail): string
    {
        if ($detail === '') {
            return '';
        }
        return match ($area) {
            /* translators: %s: a user name. */
            'login', 'register', 'lostpassword' => sprintf(__('as %s', 'vpndetection'), $detail),
            'comment' => str_starts_with($detail, 'post:') ? sprintf(
                /* translators: %s: a post title. */
                __('on %s', 'vpndetection'),
                get_the_title((int) substr($detail, 5)),
            ) : $detail,
            /* translators: %s: an order number. */
            'checkout' => sprintf(__('order %s', 'vpndetection'), $detail),
            default => $detail,
        };
    }

    /** @param list<string> $signals */
    public static function signals(array $signals): string
    {
        return implode(', ', array_map([Signals::class, 'label'], $signals));
    }

    public static function areas(): array
    {
        $out = [];
        foreach (array_keys(Settings::AREAS) as $area) {
            $out[$area] = Page::areaText($area)[0];
        }
        return $out;
    }

    public static function table(): string
    {
        return ActivityLog::table();
    }
}
