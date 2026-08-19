<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Integrations\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\DTOs\Report;
use TheMattos\Leakless\Integrations\Laravel\Listeners\OctaneReceivedListener;
use TheMattos\Leakless\Integrations\Laravel\Listeners\OctaneTerminatedListener;
use TheMattos\Leakless\Leakless;

final class LeaklessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $configPath = __DIR__.'/../../../config/leakless.php';
        if (file_exists($configPath)) {
            $this->mergeConfigFrom($configPath, 'leakless');
        }

        $this->app->singleton(Config::class, function (Application $app): Config {
            /** @var ConfigRepository $configRepository */
            $configRepository = $app->make('config');
            /** @var array{
             *     enabled?: bool,
             *     max_rss_mb?: int,
             *     check_transactions?: bool,
             *     check_file_descriptors?: bool,
             *     auto_recycle?: bool,
             *     max_requests?: int|null,
             *     log_violations?: bool
             * } $cfg */
            $cfg = (array) $configRepository->get('leakless', []);

            $logger = function (string $message, Report $report) use ($app): void {
                if ($app->bound('log')) {
                    /** @var LoggerInterface $log */
                    $log = $app->make('log');
                    $log->warning($message, [
                        'duration_ms' => $report->durationMs,
                        'memory_drift_mb' => $report->memoryDriftMb,
                        'dangling_transactions' => $report->danglingTransactionsCount,
                        'should_recycle' => $report->shouldRecycle,
                        'recycle_reason' => $report->recycleReason,
                    ]);
                } else {
                    error_log($message);
                }
            };

            return new Config(
                maxRssMb: (int) ($cfg['max_rss_mb'] ?? 96),
                checkTransactions: (bool) ($cfg['check_transactions'] ?? true),
                checkFileDescriptors: (bool) ($cfg['check_file_descriptors'] ?? false),
                autoRecycleOnViolation: (bool) ($cfg['auto_recycle'] ?? true),
                maxRequests: isset($cfg['max_requests']) ? (int) $cfg['max_requests'] : null,
                logViolations: (bool) ($cfg['log_violations'] ?? true),
                logger: $logger,
            );
        });

        $this->app->singleton(Leakless::class, function (Application $app): Leakless {
            return new Leakless(
                config: $app->make(Config::class),
            );
        });

        $this->app->alias(Leakless::class, 'leakless');
    }

    public function boot(): void
    {
        $configPath = __DIR__.'/../../../config/leakless.php';

        if ($this->app->runningInConsole() && file_exists($configPath)) {
            $this->publishes([
                $configPath => $this->app->configPath('leakless.php'),
            ], 'leakless-config');
        }

        /** @var ConfigRepository $configRepository */
        $configRepository = $this->app->make('config');
        /** @var bool $enabled */
        $enabled = (bool) $configRepository->get('leakless.enabled', true);

        if (! $enabled) {
            return;
        }

        $this->registerOctaneListeners();
    }

    private function registerOctaneListeners(): void
    {
        if (! $this->app->bound('events')) {
            return;
        }

        /** @var Dispatcher $events */
        $events = $this->app->make('events');

        // Listen for Octane RequestReceived event if Octane is present
        $events->listen('Laravel\Octane\Events\RequestReceived', OctaneReceivedListener::class);

        // Listen for Octane RequestTerminated event
        $events->listen('Laravel\Octane\Events\RequestTerminated', OctaneTerminatedListener::class);
    }
}
