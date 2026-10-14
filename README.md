# Survelium Agent Analytics for WordPress

[![Tests](https://img.shields.io/github/actions/workflow/status/survelium/agent-analytics-wordpress/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/survelium/agent-analytics-wordpress/actions/workflows/run-tests.yml)

See which AI crawlers read your WordPress site and how many people AI assistants send you, in
[Survelium](https://survelium.com).

AI crawlers (GPTBot, ClaudeBot, PerplexityBot, …) do not run JavaScript, so a JS snippet never sees them. This
plugin recognises them on the server, plus visitors arriving from ChatGPT, Perplexity, Claude, Gemini or Copilot,
and sends them to Survelium in batches from WP-Cron. It is built on
[`survelium/agent-analytics-php`](https://github.com/survelium/agent-analytics-php).

## What leaves your server

| | Sent | Never sent |
|---|---|---|
| AI crawler hit | time, user agent, path, HTTP status, IP (to verify it is really that crawler) | — |
| Visitor from an AI assistant | time, assistant domain (e.g. `chatgpt.com`), path, user agent | IP, full referrer, cookies, session |
| Every other page view | a daily count, used for "share of traffic from AI" | anything about the visitor |

## Requirements

PHP 8.1+, WordPress 6.0+.

## Installation

1. Download `survelium-agent-analytics-x.y.z.zip` from the
   [latest release](https://github.com/survelium/agent-analytics-wordpress/releases) (not "Source code": that has
   no `vendor` folder).
2. WordPress → Plugins → Add New → Upload Plugin, then activate.
3. Settings → Survelium: paste your ingest key (Survelium → AI traffic → Integration) and save.
4. Press **Send now**. "Sent." means Survelium accepted it.

The key can also live in `wp-config.php`, which then takes precedence:

```php
define('SURVELIUM_INGEST_KEY', 'svk_...');
```

### Before you go live, check

- **Page cache.** Cached pages never run PHP, so crawlers reading them would not be seen.
  - **LiteSpeed** (LiteSpeed Cache plugin, most shared hosting): handled. The plugin writes a block at the top of
    `.htaccess` that lets AI crawlers and visitors from AI assistants skip the cache. Settings → Survelium → Status
    shows "Active". The block is removed when the plugin is deactivated.
  - **WP Rocket, W3 Total Cache, Cloudflare "cache everything", Varnish**: exclude AI crawler user agents from the
    cache by hand. Without that, only uncached pages are reported.
- **WP-Cron runs.** Data is sent every five minutes by WP-Cron. If you set `DISABLE_WP_CRON`, call `wp-cron.php` from
  a real cron. The Status table shows "overdue" when it stops.
- **Behind a proxy or Cloudflare**, crawler IPs are the proxy's unless you name the header that carries the real
  one, e.g. `define('SURVELIUM_IP_HEADER', 'HTTP_CF_CONNECTING_IP');`. Only do this if the proxy always sets it.

## How it works

- At `shutdown` of every front-end `GET`/`HEAD` request (not admin, AJAX, REST or cron), the final status code is
  known and the hit is matched against the AI crawler list and the AI assistant domains. Matches go to a database
  table (`wp_survelium_aa_queue`, at most 20,000 rows).
- **Daily total.** People mostly get cached pages, so they are counted by a one-line script in the footer that
  posts to `/wp-json/survelium/v1/hit`. It sends nothing about the visitor; the endpoint only adds 1. AI crawler
  hits are added server-side. Turn the script off and every PHP hit is counted instead (cached pages are then
  missing from the total).
- WP-Cron sends the queue every five minutes (up to 3 × 1,000 items), and the daily total at least every 15
  minutes. If Survelium is unreachable the data stays queued and is retried with back-off; a revoked key or a plan
  without AI traffic pauses sending without losing data. Saving the settings lifts the pause.
- The crawler list is refreshed daily from Survelium (the plugin ships with a copy), and the `.htaccess` block
  follows it.

## Settings

| Setting | Default | |
|---|---|---|
| Ingest key | — | Nothing is recorded while empty. |
| What to report | AI crawlers and visitors from AI assistants | Or AI crawlers only. |
| Page views | on | The footer script for the daily total. |
| Page cache | on | The LiteSpeed `.htaccess` block. |
| Ignored paths | `/wp-admin`, `/wp-login.php`, `/wp-cron.php`, `/xmlrpc.php`, `/wp-json` | Path prefixes neither reported nor counted. |

| Constant (`wp-config.php`) | |
|---|---|
| `SURVELIUM_INGEST_KEY` | Overrides the key from the settings page. |
| `SURVELIUM_IP_HEADER` | Server variable with the real client IP behind a proxy. |
| `SURVELIUM_ENDPOINT` | Another Survelium address, for testing. |

Uninstalling (Plugins → Delete) drops the two tables, the options and the `.htaccess` block.

## Development

Tests run against a real WordPress and MySQL:

```bash
composer install
WP_TESTS_DB_PASSWORD=secret composer test   # also WP_TESTS_DB_NAME, _USER, _HOST
php bin/build.php                            # build/survelium-agent-analytics-x.y.z.zip
```

The test database (default `survelium_wp_tests`) must exist and is wiped on every run. Pushing a `v*` tag runs the
tests and attaches the zip to the GitHub release.

## Changelog

See [CHANGELOG](CHANGELOG.md).

## Security

Please report vulnerabilities through [GitHub security advisories](../../security/advisories/new), not in issues.

## License

The MIT License (MIT). See [License File](LICENSE.md).
