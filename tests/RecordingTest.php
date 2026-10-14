<?php

namespace Survelium\WordPressAgentAnalytics\Tests;

use ReflectionProperty;
use Survelium\WordPressAgentAnalytics\Settings;

final class RecordingTest extends TestCase
{
    public function test_it_queues_an_ai_crawler_hit_with_its_ip_and_counts_it(): void
    {
        $this->configure();

        $this->visit('/pricing/?ref=x', self::GPTBOT_UA);

        $queued = $this->queued();

        $this->assertCount(1, $queued);
        $this->assertSame('event', $queued[0]['kind']);
        $this->assertSame('/pricing/?ref=x', $queued[0]['path']);
        $this->assertSame('20.171.206.10', $queued[0]['ip']);
        $this->assertSame(1, $this->todayTotal());
    }

    public function test_people_are_left_to_the_page_view_ping_so_uncached_pages_are_not_counted_twice(): void
    {
        $this->configure();

        $this->visit('/', self::CHROME_UA, 'https://www.google.com/');

        $this->assertSame([], $this->queued());
        $this->assertSame(0, $this->todayTotal());
    }

    public function test_without_the_ping_php_counts_every_hit(): void
    {
        $this->configure(['count_page_views' => false]);

        $this->visit('/', self::CHROME_UA);
        $this->visit('/', self::GPTBOT_UA);

        $this->assertSame(2, $this->todayTotal());
    }

    public function test_it_queues_a_visitor_from_an_ai_assistant_without_their_ip(): void
    {
        $this->configure();

        $this->visit('/blog/', self::CHROME_UA, 'https://chatgpt.com/', '81.196.1.1');

        $queued = $this->queued();

        $this->assertCount(1, $queued);
        $this->assertSame('referral', $queued[0]['kind']);
        $this->assertSame('chatgpt.com', $queued[0]['source']);
        $this->assertArrayNotHasKey('ip', $queued[0]);
    }

    public function test_crawlers_only_mode_skips_ai_visitors(): void
    {
        $this->configure(['mode' => Settings::MODE_CRAWLERS]);

        $this->visit('/', self::CHROME_UA, 'https://chatgpt.com/');
        $this->visit('/', self::GPTBOT_UA);

        $this->assertSame(['event'], array_column($this->queued(), 'kind'));
    }

    public function test_nothing_is_recorded_without_a_key(): void
    {
        $this->visit('/', self::GPTBOT_UA);

        $this->assertSame([], $this->queued());
    }

    public function test_denied_paths_and_non_get_requests_are_ignored(): void
    {
        $this->configure(['deny_paths' => ['/members']]);

        $this->visit('/members/area', self::GPTBOT_UA);
        $this->visit('/contact', self::GPTBOT_UA, null, '20.171.206.10', 'POST');

        $this->assertSame([], $this->queued());
    }

    public function test_requests_that_never_reach_the_theme_are_ignored(): void
    {
        $this->configure();
        (new ReflectionProperty($this->plugin(), 'isFrontEndRequest'))->setValue($this->plugin(), false);

        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_USER_AGENT'] = self::GPTBOT_UA;
        $this->plugin()->recordRequest();

        $this->assertSame([], $this->queued());
    }
}
