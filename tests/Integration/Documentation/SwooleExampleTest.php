<?php

declare(strict_types=1);

namespace Tests\Integration\Documentation;

use PDO;
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Leakless;

class SampleWorkerMetricsBuffer
{
    /** @var array<int, string> */
    public static array $events = [];

    public static function resetState(): void
    {
        self::$events = [];
    }
}

test('swoole server worker lifecycle example from documentation intercepts requests and rolls back state', function () {
    $pdo = new PDO('sqlite::memory:');

    $config = new Config(
        maxDriftMb: 64,
        consecutiveViolationsThreshold: 5,
        recycleCooldownSeconds: 10,
        checkTransactions: true,
        maxRequests: 2,
        resettables: [
            SampleWorkerMetricsBuffer::class,
        ],
    );

    // 1. WorkerStart event emulation
    $leakless = new Leakless($config);
    $leakless->registerConnection($pdo);
    $leakless->captureBaselineMetrics();

    expect($leakless->getBaselineMetrics())->not->toBeNull();

    $reloaded = false;

    // 2. Simulated incoming requests
    for ($i = 1; $i <= 2; $i++) {
        $leakless->startRequest();

        try {
            SampleWorkerMetricsBuffer::$events[] = 'metric_'.$i;

            if ($i === 1) {
                $pdo->beginTransaction();
                // Request finished without commit
            }
        } finally {
            $report = $leakless->endRequest();

            if ($report->shouldRecycle) {
                $reloaded = true;
            }
        }
    }

    expect($reloaded)->toBeTrue()
        ->and($pdo->inTransaction())->toBeFalse()
        ->and(SampleWorkerMetricsBuffer::$events)->toBeEmpty();
});
