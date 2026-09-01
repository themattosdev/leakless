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
use TheMattos\Leakless\Integrations\Laravel\Listeners\OctaneWorkerStartingListener;
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
             *     max_drift_mb?: int|null,
             *     max_rss_mb?: int|null,
             *     check_transactions?: bool,
             *     check_file_descriptors?: bool,
             *     auto_recycle?: bool,
             *     max_requests?: int|null,
             *     consecutive_violations?: int,
             *     recycle_cooldown?: int,
             *     trigger_gc?: bool,
             *     drift_jitter?: int,
             *     log_violations?: bool,
             *     resettables?: array<int, class-string<object>|object|callable>
             * } $cfg */
            $cfg = (array) $configRepository->get('leakless', []);

            $logger = function (string $message, Report $report) use ($app): void {
                if ($app->bound('log')) {
                    /** @var LoggerInterface $log */
                    $log = $app->make('log');
                    $log->warning($message, [
                        'duration_ms' => $report->durationMs,
                        'memory_drift_mb' => $report->memoryDriftMb,
                        'baseline_rss_mb' => $report->baselineRssMb,
                        'drift_over_baseline_mb' => $report->driftOverBaselineMb,
                        'consecutive_violations' => $report->consecutiveViolationsCount,
                        'cooldown_active' => $report->cooldownActive,
                        'dangling_transactions' => $report->danglingTransactionsCount,
                        'should_recycle' => $report->shouldRecycle,
                        'recycle_reason' => $report->recycleReason,
                    ]);
                } else {
                    error_log($message);
                }
            };

            $maxDrift = array_key_exists('max_drift_mb', $cfg)
                ? ($cfg['max_drift_mb'] !== null ? (int) $cfg['max_drift_mb'] : null)
                : 64;

            $maxRss = isset($cfg['max_rss_mb'])
                ? (int) $cfg['max_rss_mb']
                : null;

            $maxRequests = isset($cfg['max_requests'])
                ? (int) $cfg['max_requests']
                : null;

            return new Config(
                maxDriftMb: $maxDrift,
                maxRssMb: $maxRss,
                checkTransactions: (bool) ($cfg['check_transactions'] ?? true),
                checkFileDescriptors: (bool) ($cfg['check_file_descriptors'] ?? false),
                autoRecycleOnViolation: (bool) ($cfg['auto_recycle'] ?? true),
                maxRequests: $maxRequests,
                consecutiveViolationsThreshold: (int) ($cfg['consecutive_violations'] ?? 5),
                recycleCooldownSeconds: (int) ($cfg['recycle_cooldown'] ?? 10),
                triggerGcOnBreach: (bool) ($cfg['trigger_gc'] ?? true),
                driftJitterPercentage: (int) ($cfg['drift_jitter'] ?? 10),
                logViolations: (bool) ($cfg['log_violations'] ?? true),
                logger: $logger,
                resettables: (array) ($cfg['resettables'] ?? []),
            );
        });

        $this->app->singleton(Leakless::class, function (Application $app): Leakless {
            return new Leakless(
                config: $app->make(Config::class),
                recycler: function (Report $report): void {
                    // Recycling lifecycle in Laravel is managed via OctaneTerminatedListener
                },
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

        // Listen for Octane WorkerStarting event to capture clean baseline memory
        $events->listen('Laravel\Octane\Events\WorkerStarting', OctaneWorkerStartingListener::class);
        $events->listen('Laravel\Octane\Events\OctaneStarted', OctaneWorkerStartingListener::class);

        // Listen for Octane RequestReceived event if Octane is present
        $events->listen('Laravel\Octane\Events\RequestReceived', OctaneReceivedListener::class);

        // Listen for Octane RequestTerminated event
        $events->listen('Laravel\Octane\Events\RequestTerminated', OctaneTerminatedListener::class);
    }
}
