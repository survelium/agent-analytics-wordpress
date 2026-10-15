# Changelog

All notable changes to `agent-analytics-wordpress` will be documented in this file.

## 0.1.2 - 2026-10-15

- The daily total counts people plus AI crawlers only: the ping uses the same bot filter as the core, which now also
  keeps search engines, SEO tools and scripts out of the server-side count. PetalBot (Huawei search) is no longer
  reported as an AI crawler.

## 0.1.1 - 2026-10-14

- The page view ping is no longer delayed by LiteSpeed Cache, WP Rocket or Cloudflare Rocket Loader until the visitor
  interacts, which undercounted the daily total.

## 0.1.0 - 2026-10-14

- First version: AI crawler and AI assistant visitor reporting, database queue, WP-Cron sending, page view ping
  for the daily total, LiteSpeed `.htaccess` cache bypass, settings page with status.
