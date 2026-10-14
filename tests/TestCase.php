<?php

namespace Survelium\WordPressAgentAnalytics\Tests;

use Survelium\WordPressAgentAnalytics\Plugin;
use Survelium\WordPressAgentAnalytics\Settings;
use WP_UnitTestCase;

abstract class TestCase extends WP_UnitTestCase
{
    public const GPTBOT_UA = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot';

    public const CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36';

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    protected array $httpRequests = [];

    /** @var list<array{status: int, body: string, headers: array<string, string>}> */
    protected array $httpResponses = [];

    /** @var array<string, mixed> */
    private array $serverBackup = [];

    public function set_up(): void
    {
        parent::set_up();

        $this->serverBackup = $_SERVER;
        $this->httpRequests = [];
        $this->httpResponses = [];

        add_filter('pre_http_request', function ($preempt, array $args, string $url) {
            $this->httpRequests[] = [
                'method' => $args['method'],
                'url' => $url,
                'headers' => $args['headers'],
                'body' => $args['body'],
            ];

            $response = array_shift($this->httpResponses) ?? ['status' => 202, 'body' => '{}', 'headers' => []];

            return [
                'headers' => $response['headers'],
                'body' => $response['body'],
                'response' => ['code' => $response['status'], 'message' => ''],
                'cookies' => [],
                'filename' => null,
            ];
        }, 10, 3);

        $this->plugin()->forgetAnalytics();
    }

    public function tear_down(): void
    {
        $_SERVER = $this->serverBackup;
        $this->plugin()->forgetAnalytics();

        parent::tear_down();
    }

    protected function plugin(): Plugin
    {
        return Plugin::instance();
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function configure(array $settings = []): void
    {
        update_option(Settings::OPTION, array_merge(Settings::defaults(), ['api_key' => 'svk_test_key'], $settings));
        $this->plugin()->forgetAnalytics();
    }

    protected function respondWith(int $status, string $body = '{}', array $headers = []): void
    {
        $this->httpResponses[] = ['status' => $status, 'body' => $body, 'headers' => $headers];
    }

    protected function visit(string $path, ?string $userAgent, ?string $referrer = null, string $ip = '20.171.206.10', string $method = 'GET'): void
    {
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REMOTE_ADDR'] = $ip;
        $_SERVER['HTTP_USER_AGENT'] = $userAgent;

        if ($referrer === null) {
            unset($_SERVER['HTTP_REFERER']);
        } else {
            $_SERVER['HTTP_REFERER'] = $referrer;
        }

        $this->plugin()->markFrontEndRequest();
        $this->plugin()->recordRequest();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function queued(): array
    {
        return array_values($this->plugin()->analytics()->storage->pending(100));
    }

    protected function todayTotal(): int
    {
        return $this->plugin()->analytics()->storage->totals(1)[gmdate('Y-m-d')] ?? 0;
    }
}
