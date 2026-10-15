<?php

namespace Survelium\WordPressAgentAnalytics;

use Survelium\AgentAnalytics\Signatures;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

/**
 * People mostly get cached pages, which never run PHP, so the daily total
 * (the denominator of the AI traffic share) comes from a one-line beacon
 * instead. It carries nothing about the visitor: the endpoint only adds 1.
 */
final class PageViewPing
{
    public const NAMESPACE = 'survelium/v1';

    public const ROUTE = '/hit';

    public function __construct(private readonly Plugin $plugin) {}

    public function registerRoute(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        if (Settings::isConfigured() && Settings::countsPageViews() && $this->looksHuman((string) $request->get_header('user-agent'))) {
            try {
                $this->plugin->analytics()->storage->incrementTotal(gmdate('Y-m-d'));
            } catch (Throwable) {
                // Counting is best effort.
            }
        }

        $response = new WP_REST_Response(null, 204);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    public function printScript(): void
    {
        if (! Settings::isConfigured() || ! Settings::countsPageViews() || is_admin() || is_feed() || is_embed()) {
            return;
        }

        $url = wp_json_encode(rest_url(self::NAMESPACE.self::ROUTE));

        // Optimisers that delay scripts until the visitor interacts would drop every page view without one:
        // LiteSpeed Cache honours data-no-optimize/data-no-defer, WP Rocket nowprocket, Cloudflare Rocket Loader data-cfasync.
        echo "<script data-no-optimize=\"1\" data-no-defer=\"1\" data-cfasync=\"false\" nowprocket>(function(u){try{if(navigator.sendBeacon){navigator.sendBeacon(u)}else{fetch(u,{method:'POST',keepalive:true,credentials:'omit'})}}catch(e){}})({$url});</script>\n";
    }

    /**
     * Crawlers that render JavaScript (Googlebot, headless browsers) must not count: the total is people plus
     * the AI crawlers, which are already counted server-side. Same rule as the server-side counting in the core.
     */
    private function looksHuman(string $userAgent): bool
    {
        return ! Signatures::looksAutomated($userAgent)
            && $this->plugin->analytics()->signatures()->matchBot($userAgent) === null;
    }
}
