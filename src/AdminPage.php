<?php

namespace Survelium\WordPressAgentAnalytics;

use Throwable;

/**
 * Settings → Survelium: the key, what to report, and whether it works.
 */
final class AdminPage
{
    public const SLUG = 'survelium-agent-analytics';

    public const FLUSH_ACTION = 'survelium_aa_flush_now';

    public function __construct(private readonly Plugin $plugin) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'registerSetting']);
        add_action('admin_post_'.self::FLUSH_ACTION, [$this, 'handleFlush']);
        add_filter('plugin_action_links_'.plugin_basename($this->plugin->file), [$this, 'addSettingsLink']);
    }

    public function addMenu(): void
    {
        add_options_page(
            __('Survelium Agent Analytics', 'survelium-agent-analytics'),
            __('Survelium', 'survelium-agent-analytics'),
            'manage_options',
            self::SLUG,
            [$this, 'render'],
        );
    }

    public function registerSetting(): void
    {
        register_setting(self::SLUG, Settings::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [Settings::class, 'sanitize'],
            'default' => Settings::defaults(),
        ]);
    }

    /**
     * @param  array<string, string>  $links
     * @return array<string, string>
     */
    public function addSettingsLink(array $links): array
    {
        $url = admin_url('options-general.php?page='.self::SLUG);

        return ['settings' => '<a href="'.esc_url($url).'">'.esc_html__('Settings', 'survelium-agent-analytics').'</a>'] + $links;
    }

    public function handleFlush(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'survelium-agent-analytics'), 403);
        }

        check_admin_referer(self::FLUSH_ACTION);

        try {
            $status = Settings::isConfigured() ? $this->plugin->flushNow() : null;
        } catch (Throwable) {
            $status = 0;
        }

        wp_safe_redirect(add_query_arg(
            ['page' => self::SLUG, 'flushed' => $status ?? 'none'],
            admin_url('options-general.php'),
        ));
        exit;
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $settings = Settings::all();
        $status = $this->status();
        $flushed = isset($_GET['flushed']) ? sanitize_text_field(wp_unslash($_GET['flushed'])) : null;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Survelium Agent Analytics', 'survelium-agent-analytics'); ?></h1>
            <p><?php esc_html_e('Reports AI crawlers (GPTBot, ClaudeBot, PerplexityBot…) and visitors sent by AI assistants to Survelium. Visitor IP addresses never leave this site; crawler IPs are sent so Survelium can verify them.', 'survelium-agent-analytics'); ?></p>

            <?php if ($flushed !== null) { ?>
                <div class="notice notice-<?php echo $flushed === '202' ? 'success' : 'warning'; ?> is-dismissible">
                    <p><?php echo esc_html($this->flushMessage($flushed)); ?></p>
                </div>
            <?php } ?>

            <form method="post" action="options.php">
                <?php settings_fields(self::SLUG); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="survelium-aa-key"><?php esc_html_e('Ingest key', 'survelium-agent-analytics'); ?></label></th>
                        <td>
                            <?php if (Settings::keyFromConstant()) { ?>
                                <p><?php esc_html_e('Set in wp-config.php (SURVELIUM_INGEST_KEY).', 'survelium-agent-analytics'); ?></p>
                            <?php } else { ?>
                                <input type="password" id="survelium-aa-key" class="regular-text" autocomplete="off"
                                       name="<?php echo esc_attr(Settings::OPTION); ?>[api_key]"
                                       placeholder="<?php echo esc_attr($settings['api_key'] !== '' ? substr($settings['api_key'], 0, 12).'…' : 'svk_…'); ?>">
                                <p class="description"><?php esc_html_e('From Survelium → AI traffic → Integration. Leave empty to keep the current key.', 'survelium-agent-analytics'); ?></p>
                                <?php if ($settings['api_key'] !== '') { ?>
                                    <label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[remove_api_key]" value="1">
                                        <?php esc_html_e('Remove the key (stops reporting)', 'survelium-agent-analytics'); ?></label>
                                <?php } ?>
                            <?php } ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="survelium-aa-mode"><?php esc_html_e('What to report', 'survelium-agent-analytics'); ?></label></th>
                        <td>
                            <select id="survelium-aa-mode" name="<?php echo esc_attr(Settings::OPTION); ?>[mode]">
                                <option value="<?php echo esc_attr(Settings::MODE_AI); ?>" <?php selected($settings['mode'], Settings::MODE_AI); ?>>
                                    <?php esc_html_e('AI crawlers and visitors from AI assistants (recommended)', 'survelium-agent-analytics'); ?></option>
                                <option value="<?php echo esc_attr(Settings::MODE_CRAWLERS); ?>" <?php selected($settings['mode'], Settings::MODE_CRAWLERS); ?>>
                                    <?php esc_html_e('AI crawlers only', 'survelium-agent-analytics'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Page views', 'survelium-agent-analytics'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[count_page_views]" value="1" <?php checked($settings['count_page_views']); ?>>
                                <?php esc_html_e('Count page views with a tiny script, for the AI share of traffic', 'survelium-agent-analytics'); ?></label>
                            <p class="description"><?php esc_html_e('Cached pages never run PHP, so a one-line beacon adds 1 to today\'s total. It sends nothing about the visitor.', 'survelium-agent-analytics'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Page cache', 'survelium-agent-analytics'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[cache_bypass]" value="1" <?php checked($settings['cache_bypass']); ?>>
                                <?php esc_html_e('On LiteSpeed, let AI crawlers and AI visitors skip the page cache (adds a block to .htaccess)', 'survelium-agent-analytics'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="survelium-aa-deny"><?php esc_html_e('Ignored paths', 'survelium-agent-analytics'); ?></label></th>
                        <td>
                            <textarea id="survelium-aa-deny" class="large-text code" rows="5"
                                      name="<?php echo esc_attr(Settings::OPTION); ?>[deny_paths]"><?php echo esc_textarea(implode("\n", $settings['deny_paths'])); ?></textarea>
                            <p class="description"><?php esc_html_e('One path prefix per line. Requests under these paths are neither reported nor counted.', 'survelium-agent-analytics'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2><?php esc_html_e('Status', 'survelium-agent-analytics'); ?></h2>
            <table class="widefat striped" style="max-width: 720px">
                <tbody>
                <?php foreach ($status as $label => $value) { ?>
                    <tr><th scope="row" style="width: 40%"><?php echo esc_html($label); ?></th><td><?php echo esc_html($value); ?></td></tr>
                <?php } ?>
                </tbody>
            </table>

            <?php if (Settings::isConfigured()) { ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 1em">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::FLUSH_ACTION); ?>">
                    <?php wp_nonce_field(self::FLUSH_ACTION); ?>
                    <?php submit_button(__('Send now', 'survelium-agent-analytics'), 'secondary', 'submit', false); ?>
                </form>
            <?php } ?>
        </div>
        <?php
    }

    /**
     * @return array<string, string>
     */
    private function status(): array
    {
        if (! Settings::isConfigured()) {
            return [__('Reporting', 'survelium-agent-analytics') => __('Off: add an ingest key', 'survelium-agent-analytics')];
        }

        try {
            $analytics = $this->plugin->analytics();
            $state = $analytics->storage->state();
            $buffered = $analytics->storage->stats()['count'];
            $today = $analytics->storage->totals(1)[gmdate('Y-m-d')] ?? 0;
            $signatures = $analytics->signatures();
        } catch (Throwable) {
            return [__('Reporting', 'survelium-agent-analytics') => __('Storage unavailable: deactivate and reactivate the plugin', 'survelium-agent-analytics')];
        }

        $next = wp_next_scheduled(Plugin::CRON_HOOK);

        return [
            __('Last delivery', 'survelium-agent-analytics') => $this->lastDelivery($state),
            __('Waiting to be sent', 'survelium-agent-analytics') => (string) $buffered,
            __('Hits counted today (UTC)', 'survelium-agent-analytics') => (string) $today,
            __('Known AI crawlers', 'survelium-agent-analytics') => sprintf('%d (%s)', count($signatures->bots), $signatures->version !== null ? substr($signatures->version, 0, 8) : __('bundled', 'survelium-agent-analytics')),
            __('Page cache bypass', 'survelium-agent-analytics') => $this->htaccessLabel(Htaccess::status()),
            __('Next scheduled send', 'survelium-agent-analytics') => $next ? human_time_diff($next).($next < time() ? ' '.__('overdue: is WP-Cron running?', 'survelium-agent-analytics') : '') : __('not scheduled', 'survelium-agent-analytics'),
            __('Plugin version', 'survelium-agent-analytics') => Plugin::VERSION,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function lastDelivery(array $state): string
    {
        if (! isset($state['last_attempt_at'])) {
            return __('Nothing sent yet', 'survelium-agent-analytics');
        }

        $when = sprintf(__('%s ago', 'survelium-agent-analytics'), human_time_diff((int) $state['last_attempt_at']));

        return match ($state['last_error'] ?? null) {
            null => sprintf(__('OK, %s', 'survelium-agent-analytics'), $when),
            'invalid_key' => sprintf(__('Rejected: the ingest key is invalid or revoked (%s)', 'survelium-agent-analytics'), $when),
            'plan_required' => sprintf(__('Rejected: the Survelium plan does not include AI traffic (%s)', 'survelium-agent-analytics'), $when),
            'rate_limited' => sprintf(__('Rate limited, will retry (%s)', 'survelium-agent-analytics'), $when),
            'unreachable' => sprintf(__('Survelium unreachable, will retry (%s)', 'survelium-agent-analytics'), $when),
            default => sprintf(__('Failed with HTTP %1$s, will retry (%2$s)', 'survelium-agent-analytics'), (string) ($state['last_status'] ?? '?'), $when),
        };
    }

    private function htaccessLabel(string $status): string
    {
        return match ($status) {
            Htaccess::WRITTEN => __('Active (.htaccess block written)', 'survelium-agent-analytics'),
            Htaccess::NOT_LITESPEED => __('Not needed: the server is not LiteSpeed. With another page cache, crawlers on cached pages are not seen.', 'survelium-agent-analytics'),
            Htaccess::NOT_WRITABLE => __('.htaccess is not writable: crawlers on cached pages are not seen', 'survelium-agent-analytics'),
            Htaccess::PENDING => __('Will be written on the next scheduled run', 'survelium-agent-analytics'),
            default => __('Off', 'survelium-agent-analytics'),
        };
    }

    private function flushMessage(string $flushed): string
    {
        return match ($flushed) {
            '202' => __('Sent.', 'survelium-agent-analytics'),
            'none' => __('Nothing was sent: another send is running, or deliveries are paused after an error.', 'survelium-agent-analytics'),
            '401' => __('Survelium rejected the ingest key.', 'survelium-agent-analytics'),
            '402' => __('Your Survelium plan does not include AI traffic.', 'survelium-agent-analytics'),
            '0' => __('Survelium could not be reached.', 'survelium-agent-analytics'),
            default => sprintf(__('Survelium answered HTTP %s.', 'survelium-agent-analytics'), $flushed),
        };
    }
}
