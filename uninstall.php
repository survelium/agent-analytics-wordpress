<?php

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__.'/vendor/autoload.php';

Survelium\WordPressAgentAnalytics\Htaccess::remove();
Survelium\WordPressAgentAnalytics\WpdbStorage::uninstall();

delete_option(Survelium\WordPressAgentAnalytics\Settings::OPTION);
delete_option(Survelium\WordPressAgentAnalytics\Plugin::DB_VERSION_OPTION);
wp_clear_scheduled_hook(Survelium\WordPressAgentAnalytics\Plugin::CRON_HOOK);
