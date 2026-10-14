<?php

namespace Survelium\WordPressAgentAnalytics;

use Survelium\AgentAnalytics\Transport\HttpResponse;
use Survelium\AgentAnalytics\Transport\Transport;

/**
 * Goes through the WordPress HTTP API, so the site's proxy settings and
 * pre_http_request filters apply.
 */
final class WpTransport implements Transport
{
    public function send(string $method, string $url, array $headers = [], ?string $body = null, float $timeoutSeconds = 5.0): HttpResponse
    {
        $response = wp_remote_request($url, [
            'method' => $method,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $timeoutSeconds,
            'redirection' => 0,
        ]);

        if (is_wp_error($response)) {
            return new HttpResponse(0);
        }

        $headers = [];

        foreach (wp_remote_retrieve_headers($response) as $name => $value) {
            $headers[strtolower((string) $name)] = is_array($value) ? (string) end($value) : (string) $value;
        }

        return new HttpResponse(
            (int) wp_remote_retrieve_response_code($response),
            $headers,
            (string) wp_remote_retrieve_body($response),
        );
    }
}
