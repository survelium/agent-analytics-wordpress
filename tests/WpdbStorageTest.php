<?php

namespace Survelium\WordPressAgentAnalytics\Tests;

use mysqli;
use Survelium\WordPressAgentAnalytics\WpdbStorage;

final class WpdbStorageTest extends TestCase
{
    public function test_items_come_back_oldest_first_and_can_be_removed(): void
    {
        $storage = new WpdbStorage;

        $storage->push(['kind' => 'event', 'path' => '/a'], 10);
        $storage->push(['kind' => 'event', 'path' => '/b'], 10);
        $storage->push(['kind' => 'event', 'path' => '/c'], 10);

        $pending = $storage->pending(2);

        $this->assertSame(['/a', '/b'], array_column(array_values($pending), 'path'));
        $this->assertSame(array_map('strval', array_keys($pending)), array_column(array_values($pending), 'id'));

        $storage->remove(array_map('strval', array_keys($pending)));

        $this->assertSame(['/c'], array_column(array_values($storage->pending(10)), 'path'));
        $this->assertSame(1, $storage->stats()['count']);
        $this->assertNotNull($storage->stats()['oldest']);
    }

    public function test_a_full_buffer_drops_new_items(): void
    {
        $storage = new WpdbStorage;

        $this->assertTrue($storage->push(['kind' => 'event'], 2));
        $this->assertTrue($storage->push(['kind' => 'event'], 2));
        $this->assertFalse($storage->push(['kind' => 'event'], 2));
        $this->assertSame(2, $storage->stats()['count']);
    }

    public function test_totals_are_cumulative_per_day_and_old_days_are_pruned(): void
    {
        $storage = new WpdbStorage;
        $old = gmdate('Y-m-d', time() - 10 * 86400);
        $yesterday = gmdate('Y-m-d', time() - 86400);
        $today = gmdate('Y-m-d');

        $storage->incrementTotal($old, 5);
        $storage->incrementTotal($yesterday, 3);
        $storage->incrementTotal($today);
        $storage->incrementTotal($today);

        $this->assertSame([$yesterday => 3, $today => 2], $storage->totals(2));

        $storage->pruneTotals();

        $this->assertSame([$yesterday => 3, $today => 2], $storage->totals(10));
    }

    public function test_state_and_signatures_round_trip_through_options(): void
    {
        $storage = new WpdbStorage;

        $storage->putState(['failures' => 2]);
        $storage->putSignatures(['payload' => ['bots' => []], 'etag' => '"v1"', 'checked_at' => 123]);

        $this->assertSame(['failures' => 2], $storage->state());
        $this->assertSame('"v1"', $storage->signatures()['etag']);
    }

    public function test_the_flush_lock_blocks_another_connection(): void
    {
        global $wpdb;

        $storage = new WpdbStorage;
        $this->assertTrue($storage->acquireFlushLock());

        $other = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        $name = 'survelium_aa_flush_'.$wpdb->prefix;
        $this->assertSame('0', $other->query("SELECT GET_LOCK('{$name}', 0)")->fetch_row()[0]);

        $storage->releaseFlushLock();
        $this->assertSame('1', $other->query("SELECT GET_LOCK('{$name}', 0)")->fetch_row()[0]);

        $other->close();
    }
}
