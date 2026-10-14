<?php

namespace Survelium\WordPressAgentAnalytics\Tests;

use Survelium\WordPressAgentAnalytics\Plugin;

final class FlushTest extends TestCase
{
    public function test_the_cron_run_refreshes_signatures_then_sends_the_queue_with_totals(): void
    {
        $this->configure();
        $this->visit('/pricing', self::GPTBOT_UA);
        $this->visit('/blog', self::CHROME_UA, 'https://perplexity.ai/');

        $this->respondWith(304);
        $this->respondWith(202, '{"accepted":{}}');

        do_action(Plugin::CRON_HOOK);

        $this->assertCount(2, $this->httpRequests);
        [$signatures, $ingest] = $this->httpRequests;

        $this->assertSame('GET', $signatures['method']);
        $this->assertSame('https://survelium.com/api/v1/signatures', $signatures['url']);

        $this->assertSame('POST', $ingest['method']);
        $this->assertSame('https://survelium.com/api/v1/ingest/crawlers', $ingest['url']);
        $this->assertSame('Bearer svk_test_key', $ingest['headers']['Authorization']);

        $body = json_decode($ingest['body'], true);
        $this->assertSame('/pricing', $body['events'][0]['path']);
        $this->assertSame('perplexity.ai', $body['referrals'][0]['source']);
        $this->assertSame([['day' => gmdate('Y-m-d'), 'hits' => 1]], $body['totals']);

        $this->assertSame([], $this->queued());
        $this->assertSame(202, get_option('survelium_aa_state')['last_status']);
    }

    public function test_a_rejected_key_keeps_the_queue_and_pauses_deliveries(): void
    {
        $this->configure();
        $this->visit('/pricing', self::GPTBOT_UA);

        $this->respondWith(304);
        $this->respondWith(401, '{"message":"Invalid key"}');

        do_action(Plugin::CRON_HOOK);

        $state = get_option('survelium_aa_state');
        $this->assertSame('invalid_key', $state['last_error']);
        $this->assertGreaterThan(time(), $state['paused_until']);
        $this->assertCount(1, $this->queued());

        $this->httpRequests = [];
        do_action(Plugin::CRON_HOOK);
        $this->assertSame([], $this->httpRequests);
    }

    public function test_saving_the_settings_lifts_the_pause_so_a_new_key_is_tried_at_once(): void
    {
        $this->configure();
        update_option('survelium_aa_state', ['paused_until' => time() + 3600, 'last_error' => 'invalid_key']);

        $this->configure(['api_key' => 'svk_new_key']);

        $this->assertArrayNotHasKey('paused_until', get_option('survelium_aa_state'));
    }

    public function test_an_unreachable_server_backs_off(): void
    {
        $this->configure();
        $this->visit('/pricing', self::GPTBOT_UA);

        $this->respondWith(304);
        $this->respondWith(503);

        do_action(Plugin::CRON_HOOK);

        $state = get_option('survelium_aa_state');
        $this->assertSame(1, $state['failures']);
        $this->assertGreaterThan(time(), $state['next_attempt_at']);
        $this->assertCount(1, $this->queued());
    }

    public function test_the_cron_does_nothing_without_a_key(): void
    {
        do_action(Plugin::CRON_HOOK);

        $this->assertSame([], $this->httpRequests);
    }

    public function test_the_cron_event_is_scheduled_every_five_minutes(): void
    {
        $this->configure();
        wp_clear_scheduled_hook(Plugin::CRON_HOOK);

        Plugin::scheduleCron();

        $this->assertSame(Plugin::CRON_SCHEDULE, wp_get_schedule(Plugin::CRON_HOOK));
        $this->assertSame(300, wp_get_schedules()[Plugin::CRON_SCHEDULE]['interval']);
    }
}
