<?php

declare(strict_types=1);

namespace VPNDetection\WordPress;

defined('ABSPATH') || exit;

/**
 * Every visitor an area flagged, in a table of the plugin's own.
 *
 * One row per address, area and outcome per hour, its `hits` counting the repeats, so a bot knocking
 * a thousand times an hour is one row and the table stays small however hard the site is hit. Rows
 * older than the retention setting go in a daily purge.
 */
final class ActivityLog
{
    public const SCHEMA_VERSION = '1';
    public const VERSION_OPTION = 'vpndetection_schema';
    public const PURGE_HOOK = 'vpndetection_purge_activity';

    public function __construct(private readonly Settings $settings)
    {
    }

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'vpndetection_activity';
    }

    /** Creates or updates the table, once per schema version. */
    public static function install(): void
    {
        if (get_option(self::VERSION_OPTION) === self::SCHEMA_VERSION) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $collate = $wpdb->get_charset_collate();
        // dbDelta's format: two spaces after PRIMARY KEY, one key per line.
        dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  hits int(10) unsigned NOT NULL DEFAULT 1,
  ip varchar(45) NOT NULL,
  area varchar(20) NOT NULL,
  action varchar(10) NOT NULL,
  signals varchar(100) NOT NULL,
  country char(2) NOT NULL DEFAULT '',
  asn int(10) unsigned NOT NULL DEFAULT 0,
  asn_name varchar(191) NOT NULL DEFAULT '',
  provider varchar(100) NOT NULL DEFAULT '',
  detail varchar(191) NOT NULL DEFAULT '',
  bucket char(13) NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY seen (ip,area,action,bucket),
  KEY last_seen (last_seen)
) {$collate};");
        update_option(self::VERSION_OPTION, self::SCHEMA_VERSION, true);
    }

    public static function uninstall(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- the plugin's own table, dropped with the plugin.
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', self::table()));
        delete_option(self::VERSION_OPTION);
    }

    public function record(Verdict $verdict): void
    {
        global $wpdb;
        $result = $verdict->visit->result;
        $now = gmdate('Y-m-d H:i:s');
        $detail = '';
        foreach ($verdict->context as $value) {
            if ($value !== '') {
                $detail = mb_substr($value, 0, 191);
                break;
            }
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; a write.
        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (first_seen, last_seen, hits, ip, area, action, signals, country, asn, asn_name, provider, detail, bucket)
            VALUES (%s, %s, 1, %s, %s, %s, %s, %s, %d, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), signals = VALUES(signals), detail = VALUES(detail)',
            self::table(),
            $now,
            $now,
            (string) $verdict->visit->ip,
            $verdict->area,
            $verdict->action,
            implode(',', $verdict->signals),
            $result?->location?->country ?? '',
            $result?->asn?->asn ?? 0,
            mb_substr($result?->asn?->name ?? '', 0, 191),
            mb_substr($verdict->provider(), 0, 100),
            $detail,
            gmdate('Y-m-d H'),
        ));
    }

    /** Deletes what is older than the retention setting. */
    public function purge(): void
    {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - (int) $this->settings->get('log_days') * DAY_IN_SECONDS);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; a delete.
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE last_seen < %s', self::table(), $cutoff));
    }

    public function clear(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; a delete.
        $wpdb->query($wpdb->prepare('DELETE FROM %i', self::table()));
    }

    /**
     * One page of rows, newest first.
     *
     * @param array{area?: string, action?: string, search?: string} $filter
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function page(array $filter, int $page, int $perPage): array
    {
        global $wpdb;
        $area = $filter['area'] ?? '';
        $action = $filter['action'] ?? '';
        $search = $filter['search'] ?? '';
        $like = '%' . $wpdb->esc_like($search) . '%';
        // An empty filter matches every row, so one fixed query serves every combination.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, read for the admin screen.
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE (%s = '' OR area = %s) AND (%s = '' OR action = %s)
                AND (%s = '' OR ip LIKE %s OR asn_name LIKE %s OR provider LIKE %s OR detail LIKE %s)",
            self::table(),
            $area,
            $area,
            $action,
            $action,
            $search,
            $like,
            $like,
            $like,
            $like,
        ));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i WHERE (%s = '' OR area = %s) AND (%s = '' OR action = %s)
                AND (%s = '' OR ip LIKE %s OR asn_name LIKE %s OR provider LIKE %s OR detail LIKE %s)
                ORDER BY last_seen DESC, id DESC LIMIT %d OFFSET %d",
            self::table(),
            $area,
            $area,
            $action,
            $action,
            $search,
            $like,
            $like,
            $like,
            $like,
            $perPage,
            max(0, ($page - 1) * $perPage),
        ), ARRAY_A);
        // phpcs:enable
        return ['rows' => is_array($rows) ? $rows : [], 'total' => $total];
    }

    /**
     * Rows and hits per area and outcome over the last few days, for the summary above the table.
     *
     * @return list<array{area: string, action: string, visitors: int, hits: int}>
     */
    public function summary(int $days): array
    {
        global $wpdb;
        $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, read for the admin screen.
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT area, action, COUNT(DISTINCT ip) AS visitors, SUM(hits) AS hits FROM %i WHERE last_seen >= %s GROUP BY area, action',
            self::table(),
            $since,
        ), ARRAY_A);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = [
                'area' => (string) $row['area'],
                'action' => (string) $row['action'],
                'visitors' => (int) $row['visitors'],
                'hits' => (int) $row['hits'],
            ];
        }
        return $out;
    }
}
