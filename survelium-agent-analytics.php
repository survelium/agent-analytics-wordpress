<?php
/**
 * Plugin Name:       Survelium Agent Analytics
 * Plugin URI:        https://github.com/survelium/agent-analytics-wordpress
 * Description:       Reports AI crawlers (GPTBot, ClaudeBot, PerplexityBot…) and visitors sent by AI assistants to Survelium, server-side.
 * Version:           0.1.1
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Survelium
 * Author URI:        https://survelium.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       survelium-agent-analytics
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! is_readable(__DIR__.'/vendor/autoload.php')) {
    add_action('admin_notices', function (): void {
        echo '<div class="notice notice-error"><p>'
            .esc_html__('Survelium Agent Analytics is missing its vendor folder. Install the release zip, not the source code.', 'survelium-agent-analytics')
            .'</p></div>';
    });

    return;
}

require_once __DIR__.'/vendor/autoload.php';

register_activation_hook(__FILE__, [Survelium\WordPressAgentAnalytics\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Survelium\WordPressAgentAnalytics\Plugin::class, 'deactivate']);

Survelium\WordPressAgentAnalytics\Plugin::boot(__FILE__);
