<?php

namespace Survelium\WordPressAgentAnalytics\Tests;

use Survelium\WordPressAgentAnalytics\PageViewPing;
use WP_REST_Request;

final class PageViewPingTest extends TestCase
{
    public function test_a_browser_ping_adds_one_to_today(): void
    {
        $this->configure();

        $response = $this->ping(self::CHROME_UA);

        $this->assertSame(204, $response->get_status());
        $this->assertSame(1, $this->todayTotal());
    }

    public function test_crawlers_that_run_javascript_are_not_counted_twice(): void
    {
        $this->configure();

        $this->ping(self::GPTBOT_UA);
        $this->ping('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');
        $this->ping('Mozilla/5.0 HeadlessChrome/120.0');
        $this->ping('');

        $this->assertSame(0, $this->todayTotal());
    }

    public function test_the_ping_counts_nothing_when_turned_off_or_without_a_key(): void
    {
        $this->ping(self::CHROME_UA);

        $this->configure(['count_page_views' => false]);
        $this->ping(self::CHROME_UA);

        $this->assertSame(0, $this->todayTotal());
    }

    public function test_the_footer_script_points_at_the_ping_endpoint(): void
    {
        $this->configure();

        $html = get_echo([new PageViewPing($this->plugin()), 'printScript']);

        $this->assertStringContainsString('navigator.sendBeacon', $html);
        $this->assertStringContainsString('survelium\/v1\/hit', $html);
    }

    public function test_no_footer_script_without_a_key_or_with_the_ping_off(): void
    {
        $this->assertStringNotContainsString('sendBeacon', get_echo([new PageViewPing($this->plugin()), 'printScript']));

        $this->configure(['count_page_views' => false]);
        $this->assertStringNotContainsString('sendBeacon', get_echo([new PageViewPing($this->plugin()), 'printScript']));
    }

    private function ping(string $userAgent): \WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/survelium/v1/hit');
        $request->set_header('User-Agent', $userAgent);

        return rest_get_server()->dispatch($request);
    }
}
