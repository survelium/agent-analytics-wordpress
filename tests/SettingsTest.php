<?php

namespace Survelium\WordPressAgentAnalytics\Tests;

use Survelium\AgentAnalytics\Config;
use Survelium\WordPressAgentAnalytics\Settings;

final class SettingsTest extends TestCase
{
    public function test_an_empty_key_field_keeps_the_stored_key(): void
    {
        $this->configure(['api_key' => 'svk_stored']);

        $this->assertSame('svk_stored', Settings::sanitize(['api_key' => ''])['api_key']);
        $this->assertSame('svk_other', Settings::sanitize(['api_key' => ' svk_other '])['api_key']);
        $this->assertSame('', Settings::sanitize(['api_key' => '', 'remove_api_key' => '1'])['api_key']);
    }

    public function test_deny_paths_are_normalised_one_per_line(): void
    {
        $sanitized = Settings::sanitize(['deny_paths' => "members\n/wp-admin\r\n\n/\n/wp-admin"]);

        $this->assertSame(['/members', '/wp-admin'], $sanitized['deny_paths']);
        $this->assertSame($sanitized, Settings::sanitize($sanitized), 'Sanitizing twice changes nothing.');
    }

    public function test_unknown_modes_fall_back_to_ai_and_checkboxes_are_booleans(): void
    {
        $sanitized = Settings::sanitize(['mode' => 'all']);

        $this->assertSame(Settings::MODE_AI, $sanitized['mode']);
        $this->assertFalse($sanitized['count_page_views']);
        $this->assertFalse($sanitized['cache_bypass']);
    }

    public function test_the_core_config_follows_the_settings(): void
    {
        $this->configure(['mode' => Settings::MODE_CRAWLERS, 'count_page_views' => true, 'deny_paths' => ['/x']]);

        $config = Settings::config();

        $this->assertSame('svk_test_key', $config->apiKey);
        $this->assertSame(Config::DEFAULT_ENDPOINT, $config->endpoint);
        $this->assertFalse($config->trackReferrals);
        $this->assertFalse($config->countAllHits);
        $this->assertSame(['/x'], $config->denyPaths);
    }

    public function test_the_settings_page_renders_for_admins(): void
    {
        $this->configure();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        ob_start();
        (new \Survelium\WordPressAgentAnalytics\AdminPage($this->plugin()))->render();
        $html = ob_get_clean();

        $this->assertStringContainsString('Survelium Agent Analytics', $html);
        $this->assertStringContainsString('svk_test_key', $html, 'The key prefix is shown as a placeholder.');
        $this->assertStringContainsString('Nothing sent yet', $html);
        $this->assertStringContainsString('Send now', $html);
    }
}
