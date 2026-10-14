<?php

require dirname(__DIR__).'/vendor/autoload.php';

putenv('WP_PHPUNIT__TESTS_CONFIG='.__DIR__.'/wp-tests-config.php');

require dirname(__DIR__).'/vendor/wp-phpunit/wp-phpunit/includes/functions.php';

tests_add_filter('muplugins_loaded', function (): void {
    require dirname(__DIR__).'/survelium-agent-analytics.php';
});

// Real tables, created once before the per-test transactions start.
tests_add_filter('plugins_loaded', function (): void {
    Survelium\WordPressAgentAnalytics\Plugin::upgrade();
}, 0);

require dirname(__DIR__).'/vendor/wp-phpunit/wp-phpunit/includes/bootstrap.php';
