<?php

namespace Survelium\WordPressAgentAnalytics\Tests;

use Survelium\AgentAnalytics\Signatures;
use Survelium\WordPressAgentAnalytics\Htaccess;
use Survelium\WordPressAgentAnalytics\Settings;

final class HtaccessTest extends TestCase
{
    private const WORDPRESS_BLOCK = "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule . /index.php [L]\n</IfModule>\n# END WordPress\n";

    private string $path;

    private bool $isLiteSpeed = true;

    public function set_up(): void
    {
        parent::set_up();

        $this->path = get_temp_dir().'survelium-htaccess-'.wp_generate_password(8, false);
        file_put_contents($this->path, self::WORDPRESS_BLOCK);

        add_filter('survelium_aa_htaccess_path', fn (): string => $this->path);
        add_filter('survelium_aa_is_litespeed', fn (): bool => $this->isLiteSpeed);
    }

    public function tear_down(): void
    {
        @unlink($this->path);

        parent::tear_down();
    }

    public function test_the_rules_send_crawlers_and_ai_visitors_past_the_cache(): void
    {
        $rules = implode("\n", Htaccess::rules($this->signatures(), true));

        $this->assertStringContainsString('<IfModule LiteSpeed>', $rules);
        $this->assertStringContainsString('RewriteCond %{HTTP_USER_AGENT} (Applebot\-Extended|GPTBot) [NC,OR]', $rules);
        $this->assertStringContainsString('RewriteCond %{HTTP_REFERER} ^https?://([^/]+\.)?(chatgpt\.com|perplexity\.ai)(/|:|$) [NC,OR]', $rules);
        $this->assertStringContainsString('RewriteCond %{QUERY_STRING} (^|&)utm_source=(www\.)?(chatgpt\.com|perplexity\.ai)(&|$) [NC]', $rules);
        $this->assertStringContainsString('RewriteRule .* - [E=Cache-Control:no-cache]', $rules);
    }

    public function test_crawlers_only_mode_leaves_visitors_cached(): void
    {
        $rules = Htaccess::rules($this->signatures(), false);

        $this->assertStringContainsString('(Applebot\-Extended|GPTBot) [NC]', implode("\n", $rules));
        $this->assertStringNotContainsString('HTTP_REFERER', implode("\n", $rules));
    }

    public function test_the_block_goes_above_the_wordpress_rules_and_is_rewritten_in_place(): void
    {
        $this->configure();

        $this->assertSame(Htaccess::WRITTEN, Htaccess::sync($this->signatures(), true));

        $contents = file_get_contents($this->path);
        $this->assertStringStartsWith('# BEGIN Survelium Agent Analytics', $contents);
        $this->assertStringEndsWith(self::WORDPRESS_BLOCK, $contents);

        Htaccess::sync(new Signatures([['pattern' => 'ClaudeBot', 'name' => 'ClaudeBot']], []), true);

        $contents = file_get_contents($this->path);
        $this->assertSame(1, substr_count($contents, '# BEGIN Survelium Agent Analytics'));
        $this->assertStringContainsString('(ClaudeBot) [NC]', $contents);
        $this->assertStringNotContainsString('GPTBot', $contents);
    }

    public function test_removing_restores_the_original_file(): void
    {
        $this->configure();
        Htaccess::sync($this->signatures(), true);

        Htaccess::remove();

        $this->assertSame(self::WORDPRESS_BLOCK, file_get_contents($this->path));
    }

    public function test_turning_the_bypass_off_removes_the_block(): void
    {
        $this->configure();
        Htaccess::sync($this->signatures(), true);

        $this->configure(['cache_bypass' => false]);

        $this->assertSame(self::WORDPRESS_BLOCK, file_get_contents($this->path));
        $this->assertSame(Htaccess::DISABLED, Htaccess::status());
    }

    public function test_nothing_is_written_on_other_servers(): void
    {
        $this->isLiteSpeed = false;
        $this->configure();

        $this->assertSame(Htaccess::NOT_LITESPEED, Htaccess::sync($this->signatures(), true));
        $this->assertSame(self::WORDPRESS_BLOCK, file_get_contents($this->path));
    }

    public function test_saving_the_settings_writes_the_block_from_the_bundled_signatures(): void
    {
        $this->configure(['mode' => Settings::MODE_AI]);

        $contents = file_get_contents($this->path);
        $this->assertStringContainsString('GPTBot', $contents);
        $this->assertStringContainsString('chatgpt\.com', $contents);
        $this->assertSame(Htaccess::WRITTEN, Htaccess::status());
    }

    private function signatures(): Signatures
    {
        return new Signatures(
            [['pattern' => 'Applebot-Extended', 'name' => 'Applebot-Extended'], ['pattern' => 'GPTBot', 'name' => 'GPTBot']],
            [['domain' => 'chatgpt.com', 'name' => 'ChatGPT'], ['domain' => 'perplexity.ai', 'name' => 'Perplexity']],
        );
    }
}
