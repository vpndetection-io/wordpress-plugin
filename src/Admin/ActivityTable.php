<?php

declare(strict_types=1);

namespace VPNDetection\WordPress\Admin;

use VPNDetection\WordPress\Plugin;
use VPNDetection\WordPress\Settings;
use WP_List_Table;

defined('ABSPATH') || exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/** The activity log as WordPress lists things: paged, filterable by area and outcome, searchable. */
final class ActivityTable extends WP_List_Table
{
    private const PER_PAGE = 50;

    public function __construct(private readonly Plugin $plugin)
    {
        parent::__construct(['singular' => 'visitor', 'plural' => 'visitors', 'ajax' => false]);
    }

    /** @return array<string, string> */
    public function get_columns(): array
    {
        return [
            'last_seen' => __('Last seen', 'vpndetection'),
            'ip' => __('Visitor', 'vpndetection'),
            'signals' => __('Connection', 'vpndetection'),
            'area' => __('Where', 'vpndetection'),
            'action' => __('Outcome', 'vpndetection'),
            'hits' => __('Attempts', 'vpndetection'),
        ];
    }

    public function prepare_items(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- filters for a read-only list.
        $filter = [
            'area' => isset($_GET['area']) ? sanitize_key(wp_unslash($_GET['area'])) : '',
            'action' => isset($_GET['outcome']) ? sanitize_key(wp_unslash($_GET['outcome'])) : '',
            'search' => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
        ];
        // phpcs:enable
        $page = $this->get_pagenum();
        $found = $this->plugin->log->page($filter, $page, self::PER_PAGE);
        $this->items = $found['rows'];
        $this->_column_headers = [$this->get_columns(), [], []];
        $this->set_pagination_args(['total_items' => $found['total'], 'per_page' => self::PER_PAGE]);
    }

    public function no_items(): void
    {
        esc_html_e('No visitors caught yet. Areas set to Log only, Hold, Spam or Block record who they catch here.', 'vpndetection');
    }

    /** @param string $which */
    protected function extra_tablenav($which): void
    {
        if ($which !== 'top') {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- filters for a read-only list.
        $area = isset($_GET['area']) ? sanitize_key(wp_unslash($_GET['area'])) : '';
        $outcome = isset($_GET['outcome']) ? sanitize_key(wp_unslash($_GET['outcome'])) : '';
        // phpcs:enable
        echo '<div class="alignleft actions">';
        printf('<label for="vpnd-filter-area" class="screen-reader-text">%s</label>', esc_html__('Where', 'vpndetection'));
        echo '<select id="vpnd-filter-area" name="area"><option value="">' . esc_html__('Everywhere', 'vpndetection') . '</option>';
        foreach (ActivityScreen::areas() as $id => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($id), selected($area, $id, false), esc_html($label));
        }
        echo '</select>';
        printf('<label for="vpnd-filter-outcome" class="screen-reader-text">%s</label>', esc_html__('Outcome', 'vpndetection'));
        echo '<select id="vpnd-filter-outcome" name="outcome"><option value="">' . esc_html__('Any outcome', 'vpndetection') . '</option>';
        foreach (['log', 'hold', 'spam', 'block'] as $action) {
            printf('<option value="%s"%s>%s</option>', esc_attr($action), selected($outcome, $action, false), esc_html(Page::actionLabel('', $action)));
        }
        echo '</select>';
        submit_button(__('Filter', 'vpndetection'), '', 'filter_action', false);
        echo '</div>';
    }

    /**
     * @param array<string, mixed> $item
     * @param string $column_name
     */
    protected function column_default($item, $column_name): string
    {
        $area = (string) $item['area'];
        return match ($column_name) {
            'last_seen' => esc_html(wp_date(
                get_option('date_format') . ' ' . get_option('time_format'),
                (int) strtotime((string) $item['last_seen'] . ' UTC'),
            )),
            'signals' => esc_html(ActivityScreen::signals(array_filter(explode(',', (string) $item['signals']))))
                . ($item['provider'] !== '' ? '<br><span class="description">' . esc_html((string) $item['provider']) . '</span>' : ''),
            'area' => esc_html(Page::areaText($area)[0])
                . ($item['detail'] !== '' ? '<br><span class="description">' . esc_html(ActivityScreen::detail($area, (string) $item['detail'])) . '</span>' : ''),
            'action' => esc_html(Page::actionLabel($area, (string) $item['action'])),
            'hits' => esc_html(number_format_i18n((int) $item['hits'])),
            default => '',
        };
    }

    /** @param array<string, mixed> $item */
    protected function column_ip($item): string
    {
        $network = [];
        if ($item['country'] !== '') {
            $network[] = (string) $item['country'];
        }
        if ((int) $item['asn'] > 0) {
            $network[] = sprintf('AS%d %s', (int) $item['asn'], (string) $item['asn_name']);
        }
        $out = '<code>' . esc_html((string) $item['ip']) . '</code>';
        if ($network !== []) {
            $out .= '<br><span class="description">' . esc_html(implode(', ', $network)) . '</span>';
        }
        // A link, as WordPress's own row actions are: the table already sits inside the filter form.
        $allow = wp_nonce_url(
            add_query_arg(['action' => 'vpndetection_allow', 'ip' => (string) $item['ip']], admin_url('admin-post.php')),
            'vpndetection_allow',
        );
        return $out . sprintf(
            '<div class="row-actions visible"><a href="%s">%s</a></div>',
            esc_url($allow),
            esc_html__('Allow this address', 'vpndetection'),
        );
    }
}
