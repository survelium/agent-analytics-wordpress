<?php

namespace Survelium\WordPressAgentAnalytics;

use Survelium\AgentAnalytics\AgentAnalytics;
use Survelium\AgentAnalytics\Hit;
use Throwable;

final class Plugin
{
    public const VERSION = '0.1.2';

    public const DB_VERSION = '1';

    public const DB_VERSION_OPTION = 'survelium_aa_db_version';

    public const CRON_HOOK = 'survelium_aa_flush';

    public const CRON_SCHEDULE = 'survelium_aa_five_minutes';

    private static ?self $instance = null;

    private ?AgentAnalytics $analytics = null;

    private bool $isFrontEndRequest = false;

    private function __construct(public readonly string $file) {}

    public static function boot(string $file): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        self::$instance = new self($file);
        self::$instance->registerHooks();

        return self::$instance;
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    public static function activate(): void
    {
        self::upgrade();
        self::scheduleCron();

        if (self::$instance !== null) {
            self::$instance->syncHtaccess();
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        Htaccess::remove();
    }

    public function analytics(): AgentAnalytics
    {
        return $this->analytics ??= AgentAnalytics::make(Settings::config(), new WpdbStorage, new WpTransport);
    }

    public function forgetAnalytics(): void
    {
        $this->analytics = null;
    }

    public function markFrontEndRequest(): void
    {
        $this->isFrontEndRequest = true;
    }

    /**
     * Runs at shutdown, so the final status code (404, 301…) is known.
     */
    public function recordRequest(): void
    {
        if (! $this->isFrontEndRequest || ! $this->shouldRecord()) {
            return;
        }

        try {
            $status = http_response_code();
            $this->analytics()->record(Hit::fromGlobals($this->analytics()->config, is_int($status) ? $status : null));
        } catch (Throwable) {
            // Never let analytics break the host site.
        }
    }

    public function runCron(): void
    {
        if (! Settings::isConfigured()) {
            return;
        }

        try {
            $analytics = $this->analytics();

            if ($analytics->shouldFlush()) {
                $analytics->flush();
            }

            (new WpdbStorage)->pruneTotals();
            $this->syncHtaccess();
        } catch (Throwable) {
            // A failing cron run must not break the other WP-Cron events behind it.
        }
    }

    /**
     * From the settings page: refresh the signatures and send everything now.
     */
    public function flushNow(): ?int
    {
        $analytics = $this->analytics();
        $analytics->refreshSignatures();
        $status = $analytics->flush();
        $this->syncHtaccess();

        return $status;
    }

    public function syncHtaccess(): string
    {
        try {
            return Htaccess::sync($this->analytics()->signatures(), Settings::mode() === Settings::MODE_AI);
        } catch (Throwable) {
            return Htaccess::status();
        }
    }

    public function settingsSaved(): void
    {
        $this->forgetAnalytics();

        // A new key (or plan) deserves an immediate try, not the rest of a 401/402 pause.
        $storage = $this->analytics()->storage;
        $state = $storage->state();
        unset($state['paused_until'], $state['next_attempt_at']);
        $storage->putState($state);

        self::scheduleCron();
        $this->syncHtaccess();
    }

    /**
     * @param  array<string, array{interval: int, display: string}>  $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public function addCronSchedule(array $schedules): array
    {
        $schedules[self::CRON_SCHEDULE] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('Every five minutes (Survelium)', 'survelium-agent-analytics'),
        ];

        return $schedules;
    }

    public static function upgrade(): void
    {
        if (get_option(self::DB_VERSION_OPTION) === self::DB_VERSION) {
            return;
        }

        WpdbStorage::install();
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    public static function scheduleCron(): void
    {
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    private function shouldRecord(): bool
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        return Settings::isConfigured()
            && in_array($method, ['GET', 'HEAD'], true)
            && ! is_admin()
            && ! wp_doing_ajax()
            && ! wp_doing_cron()
            && ! (defined('REST_REQUEST') && REST_REQUEST)
            && ! (defined('WP_CLI') && WP_CLI);
    }

    private function registerHooks(): void
    {
        // Plugin updates by file upload never fire the activation hook.
        add_action('plugins_loaded', [self::class, 'upgrade']);
        add_filter('cron_schedules', [$this, 'addCronSchedule']);
        add_action('init', function (): void {
            if (Settings::isConfigured()) {
                self::scheduleCron();
            }
        });
        add_action(self::CRON_HOOK, [$this, 'runCron']);

        add_action('template_redirect', [$this, 'markFrontEndRequest'], 0);
        add_action('shutdown', [$this, 'recordRequest'], 0);

        $ping = new PageViewPing($this);
        add_action('rest_api_init', [$ping, 'registerRoute']);
        add_action('wp_footer', [$ping, 'printScript'], 99);

        add_action('update_option_'.Settings::OPTION, [$this, 'settingsSaved']);
        add_action('add_option_'.Settings::OPTION, [$this, 'settingsSaved']);

        if (is_admin()) {
            (new AdminPage($this))->register();
        }
    }
}
