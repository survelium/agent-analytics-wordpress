<?php

namespace Survelium\WordPressAgentAnalytics;

use Survelium\AgentAnalytics\Config;

/**
 * The plugin's options, with wp-config.php constants taking precedence
 * (SURVELIUM_INGEST_KEY, SURVELIUM_ENDPOINT, SURVELIUM_IP_HEADER).
 */
final class Settings
{
    public const OPTION = 'survelium_aa_settings';

    public const MODE_AI = 'ai';

    public const MODE_CRAWLERS = 'crawlers';

    public const DEFAULT_DENY_PATHS = ['/wp-admin', '/wp-login.php', '/wp-cron.php', '/xmlrpc.php', '/wp-json'];

    /**
     * @return array{api_key: string, mode: string, deny_paths: list<string>, count_page_views: bool, cache_bypass: bool}
     */
    public static function defaults(): array
    {
        return [
            'api_key' => '',
            'mode' => self::MODE_AI,
            'deny_paths' => self::DEFAULT_DENY_PATHS,
            'count_page_views' => true,
            'cache_bypass' => true,
        ];
    }

    /**
     * @return array{api_key: string, mode: string, deny_paths: list<string>, count_page_views: bool, cache_bypass: bool}
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);

        return array_merge(self::defaults(), is_array($stored) ? $stored : []);
    }

    public static function apiKey(): string
    {
        return defined('SURVELIUM_INGEST_KEY') ? (string) SURVELIUM_INGEST_KEY : self::all()['api_key'];
    }

    public static function keyFromConstant(): bool
    {
        return defined('SURVELIUM_INGEST_KEY');
    }

    public static function isConfigured(): bool
    {
        return self::apiKey() !== '';
    }

    public static function mode(): string
    {
        return self::all()['mode'] === self::MODE_CRAWLERS ? self::MODE_CRAWLERS : self::MODE_AI;
    }

    public static function countsPageViews(): bool
    {
        return (bool) self::all()['count_page_views'];
    }

    public static function bypassesCache(): bool
    {
        return (bool) self::all()['cache_bypass'];
    }

    public static function config(): Config
    {
        return new Config(
            apiKey: self::apiKey(),
            endpoint: defined('SURVELIUM_ENDPOINT') ? (string) SURVELIUM_ENDPOINT : Config::DEFAULT_ENDPOINT,
            trackReferrals: self::mode() === self::MODE_AI,
            denyPaths: self::all()['deny_paths'],
            ipHeader: defined('SURVELIUM_IP_HEADER') ? (string) SURVELIUM_IP_HEADER : null,
            countAllHits: ! self::countsPageViews(),
        );
    }

    /**
     * @param  mixed  $input  Raw form data from options.php.
     * @return array{api_key: string, mode: string, deny_paths: list<string>, count_page_views: bool, cache_bypass: bool}
     */
    public static function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $current = self::all();

        $apiKey = trim(sanitize_text_field((string) ($input['api_key'] ?? '')));

        if ($apiKey === '' && empty($input['remove_api_key'])) {
            // An empty field keeps the stored key, so saving other settings never wipes it.
            $apiKey = $current['api_key'];
        }

        // WordPress runs the sanitizer twice when the option is first added, the second time on our own output.
        $denyPaths = $input['deny_paths'] ?? '';
        $denyPaths = is_array($denyPaths) ? array_map('strval', $denyPaths) : (preg_split('/\R/', (string) $denyPaths) ?: []);
        $denyPaths = array_values(array_unique(array_filter(array_map(
            fn (string $path): string => '/'.ltrim(trim(sanitize_text_field($path)), '/'),
            $denyPaths,
        ), fn (string $path): bool => $path !== '/')));

        return [
            'api_key' => $apiKey,
            'mode' => ($input['mode'] ?? '') === self::MODE_CRAWLERS ? self::MODE_CRAWLERS : self::MODE_AI,
            'deny_paths' => $denyPaths,
            'count_page_views' => ! empty($input['count_page_views']),
            'cache_bypass' => ! empty($input['cache_bypass']),
        ];
    }
}
