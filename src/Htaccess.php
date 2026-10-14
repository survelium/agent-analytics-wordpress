<?php

namespace Survelium\WordPressAgentAnalytics;

use Survelium\AgentAnalytics\Signatures;

/**
 * LiteSpeed serves cached pages without running PHP, so the plugin would
 * never see AI crawlers there. This block, at the top of .htaccess, marks
 * their requests (and visitors arriving from AI assistants) as uncacheable.
 */
final class Htaccess
{
    public const MARKER = 'Survelium Agent Analytics';

    public const HASH_OPTION = 'survelium_aa_htaccess';

    public const WRITTEN = 'written';

    public const DISABLED = 'disabled';

    public const NOT_LITESPEED = 'not_litespeed';

    public const NOT_WRITABLE = 'not_writable';

    public const PENDING = 'pending';

    /**
     * @return list<string>
     */
    public static function rules(Signatures $signatures, bool $withReferrals): array
    {
        $bots = array_map(fn (array $bot): string => self::quote($bot['pattern']), $signatures->bots);
        $domains = array_map(fn (array $referrer): string => self::quote($referrer['domain']), $signatures->referrers);

        if ($bots === []) {
            return [];
        }

        $conditions = ['RewriteCond %{HTTP_USER_AGENT} ('.implode('|', $bots).') [NC'];

        if ($withReferrals && $domains !== []) {
            $alternatives = implode('|', $domains);
            $conditions[] = 'RewriteCond %{HTTP_REFERER} ^https?://([^/]+\.)?('.$alternatives.')(/|:|$) [NC';
            $conditions[] = 'RewriteCond %{QUERY_STRING} (^|&)utm_source=(www\.)?('.$alternatives.')(&|$) [NC';
        }

        $last = count($conditions) - 1;

        foreach ($conditions as $index => $condition) {
            $conditions[$index] = $condition.($index === $last ? ']' : ',OR]');
        }

        return [
            '# Sends AI crawlers and visitors from AI assistants past the LiteSpeed page cache,',
            '# so the plugin can see them. Removed when the plugin is deactivated.',
            '<IfModule LiteSpeed>',
            'RewriteEngine On',
            ...$conditions,
            'RewriteRule .* - [E=Cache-Control:no-cache]',
            '</IfModule>',
        ];
    }

    public static function path(): string
    {
        if (! function_exists('get_home_path')) {
            require_once ABSPATH.'wp-admin/includes/file.php';
        }

        return apply_filters('survelium_aa_htaccess_path', get_home_path().'.htaccess');
    }

    public static function isLiteSpeed(): bool
    {
        $software = $_SERVER['SERVER_SOFTWARE'] ?? '';

        return (bool) apply_filters('survelium_aa_is_litespeed', is_string($software) && stripos($software, 'litespeed') !== false);
    }

    /**
     * Writes or refreshes the block when it should be there, removes it when not.
     */
    public static function sync(Signatures $signatures, bool $withReferrals): string
    {
        if (! Settings::bypassesCache() || ! Settings::isConfigured()) {
            self::remove();

            return self::DISABLED;
        }

        if (! self::isLiteSpeed()) {
            return self::NOT_LITESPEED;
        }

        $lines = self::rules($signatures, $withReferrals);
        $hash = md5(self::path().implode("\n", $lines));

        if (get_option(self::HASH_OPTION) === $hash && self::contains()) {
            return self::WRITTEN;
        }

        if (! self::write($lines)) {
            return self::NOT_WRITABLE;
        }

        update_option(self::HASH_OPTION, $hash, false);

        return self::WRITTEN;
    }

    public static function status(): string
    {
        if (! Settings::bypassesCache() || ! Settings::isConfigured()) {
            return self::DISABLED;
        }

        if (! self::isLiteSpeed()) {
            return self::NOT_LITESPEED;
        }

        if (self::contains()) {
            return self::WRITTEN;
        }

        return self::isWritable() ? self::PENDING : self::NOT_WRITABLE;
    }

    /**
     * @param  list<string>  $lines
     */
    public static function write(array $lines): bool
    {
        if (! self::isWritable()) {
            return false;
        }

        $path = self::path();
        $rest = self::withoutBlock(is_file($path) ? (string) file_get_contents($path) : '');

        $block = $lines === []
            ? ''
            : implode("\n", ['# BEGIN '.self::MARKER, ...$lines, '# END '.self::MARKER])."\n\n";

        return file_put_contents($path, $block.ltrim($rest, "\r\n"), LOCK_EX) !== false;
    }

    public static function remove(): bool
    {
        delete_option(self::HASH_OPTION);

        if (! self::contains()) {
            return true;
        }

        return self::write([]);
    }

    public static function contains(): bool
    {
        $path = self::path();

        return is_file($path) && str_contains((string) file_get_contents($path), '# BEGIN '.self::MARKER);
    }

    private static function isWritable(): bool
    {
        $path = self::path();

        return is_file($path) ? is_writable($path) : is_writable(dirname($path));
    }

    private static function withoutBlock(string $contents): string
    {
        $marker = preg_quote(self::MARKER, '/');

        return (string) preg_replace('/^# BEGIN '.$marker.'\R.*?^# END '.$marker.'\R*/ms', '', $contents);
    }

    private static function quote(string $value): string
    {
        return str_replace(' ', '\s', preg_quote($value, null));
    }
}
