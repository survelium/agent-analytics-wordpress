# Changelog

All notable changes to `agent-analytics-wordpress` will be documented in this file.

## 0.1.1 - 2026-10-14

- The page view ping is no longer delayed by LiteSpeed Cache, WP Rocket or Cloudflare Rocket Loader until the visitor
  interacts, which undercounted the daily total.

## 0.1.0 - 2026-10-14

- First version: AI crawler and AI assistant visitor reporting, database queue, WP-Cron sending, page view ping
  for the daily total, LiteSpeed `.htaccess` cache bypass, settings page with status.
