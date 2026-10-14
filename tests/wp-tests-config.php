<?php

/* Database settings come from the environment so CI and local runs share this file. */
define('DB_NAME', getenv('WP_TESTS_DB_NAME') ?: 'survelium_wp_tests');
define('DB_USER', getenv('WP_TESTS_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('WP_TESTS_DB_PASSWORD') !== false ? getenv('WP_TESTS_DB_PASSWORD') : '');
define('DB_HOST', getenv('WP_TESTS_DB_HOST') ?: '127.0.0.1');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';

define('ABSPATH', dirname(__DIR__).'/vendor/roots/wordpress-no-content/');
define('WP_DEBUG', true);
define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'Test Blog');
define('WP_PHP_BINARY', PHP_BINARY);
define('WPLANG', '');
