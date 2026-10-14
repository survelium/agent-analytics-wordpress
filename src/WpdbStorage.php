<?php

namespace Survelium\WordPressAgentAnalytics;

use Survelium\AgentAnalytics\Storage\Storage;

/**
 * The core's buffer in two small tables, with state and the cached
 * signatures in non-autoloaded options. Shared hosts often wipe or
 * share the temp folder, so the database is the safer place on WordPress.
 */
final class WpdbStorage implements Storage
{
    public const QUEUE_TABLE = 'survelium_aa_queue';

    public const TOTALS_TABLE = 'survelium_aa_totals';

    public const STATE_OPTION = 'survelium_aa_state';

    public const SIGNATURES_OPTION = 'survelium_aa_signatures';

    private const TOTALS_KEEP_DAYS = 8;

    private bool $holdsFlushLock = false;

    public static function queueTable(): string
    {
        global $wpdb;

        return $wpdb->prefix.self::QUEUE_TABLE;
    }

    public static function totalsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix.self::TOTALS_TABLE;
    }

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        dbDelta('CREATE TABLE '.self::queueTable()." (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  item longtext NOT NULL,
  created_at int(10) unsigned NOT NULL,
  PRIMARY KEY  (id)
) {$charset};");

        dbDelta('CREATE TABLE '.self::totalsTable()." (
  day date NOT NULL,
  hits bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day)
) {$charset};");
    }

    public static function uninstall(): void
    {
        global $wpdb;

        $wpdb->query('DROP TABLE IF EXISTS '.self::queueTable());
        $wpdb->query('DROP TABLE IF EXISTS '.self::totalsTable());
        delete_option(self::STATE_OPTION);
        delete_option(self::SIGNATURES_OPTION);
    }

    public function push(array $item, int $maxItems): bool
    {
        global $wpdb;

        if ($this->stats()['count'] >= $maxItems) {
            return false;
        }

        unset($item['id']);
        $json = wp_json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json !== false
            && $wpdb->insert(self::queueTable(), ['item' => $json, 'created_at' => time()], ['%s', '%d']) !== false;
    }

    public function stats(): array
    {
        global $wpdb;

        $row = $wpdb->get_row('SELECT COUNT(*) AS total, MIN(created_at) AS oldest FROM '.self::queueTable(), ARRAY_A);

        return [
            'count' => (int) ($row['total'] ?? 0),
            'oldest' => isset($row['oldest']) ? (int) $row['oldest'] : null,
        ];
    }

    public function pending(int $limit): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT id, item FROM '.self::queueTable().' ORDER BY id ASC LIMIT %d', $limit),
            ARRAY_A,
        ) ?: [];

        $items = [];

        foreach ($rows as $row) {
            $item = json_decode((string) $row['item'], true);

            if (is_array($item)) {
                $items[(string) $row['id']] = $item + ['id' => (string) $row['id']];
            }
        }

        return $items;
    }

    public function remove(array $ids): void
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $ids)));

        foreach (array_chunk($ids, 500) as $chunk) {
            $wpdb->query('DELETE FROM '.self::queueTable().' WHERE id IN ('.implode(',', $chunk).')');
        }
    }

    public function incrementTotal(string $day, int $by = 1): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO '.self::totalsTable().' (day, hits) VALUES (%s, %d) ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)',
            $day,
            $by,
        ));
    }

    public function totals(int $days): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT day, hits FROM '.self::totalsTable().' ORDER BY day DESC LIMIT %d', $days),
            ARRAY_A,
        ) ?: [];

        $totals = [];

        foreach (array_reverse($rows) as $row) {
            $totals[(string) $row['day']] = (int) $row['hits'];
        }

        return $totals;
    }

    public function pruneTotals(): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'DELETE FROM '.self::totalsTable().' WHERE day < %s',
            gmdate('Y-m-d', time() - self::TOTALS_KEEP_DAYS * 86400),
        ));
    }

    public function state(): array
    {
        $state = get_option(self::STATE_OPTION, []);

        return is_array($state) ? $state : [];
    }

    public function putState(array $state): void
    {
        update_option(self::STATE_OPTION, $state, false);
    }

    public function signatures(): ?array
    {
        $signatures = get_option(self::SIGNATURES_OPTION, null);

        return is_array($signatures) && is_array($signatures['payload'] ?? null) && isset($signatures['checked_at'])
            ? $signatures
            : null;
    }

    public function putSignatures(array $signatures): void
    {
        update_option(self::SIGNATURES_OPTION, $signatures, false);
    }

    /**
     * A MySQL named lock: atomic, and freed by the server if PHP dies mid-flush.
     */
    public function acquireFlushLock(): bool
    {
        global $wpdb;

        $this->holdsFlushLock = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $this->lockName())) === 1;

        return $this->holdsFlushLock;
    }

    public function releaseFlushLock(): void
    {
        global $wpdb;

        if ($this->holdsFlushLock) {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->lockName()));
            $this->holdsFlushLock = false;
        }
    }

    private function lockName(): string
    {
        global $wpdb;

        return 'survelium_aa_flush_'.$wpdb->prefix;
    }
}
