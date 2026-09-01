<?php

declare(strict_types=1);

namespace Tests\Integration\Documentation;

use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Leakless;

class RoadRunnerSampleCartSession
{
    /** @var array<int, string> */
    public array $items = [];

    public function reset(): void
    {
        $this->items = [];
    }
}

class RoadRunnerSampleLegacyRegistry
{
    /** @var array<string, mixed> */
    public static array $cache = [];
}

test('roadrunner worker loop example from documentation executes and cleans state', function () {
    $cartSession = new RoadRunnerSampleCartSession;

    $config = new Config(
        maxDriftMb: 64,
        consecutiveViolationsThreshold: 5,
        recycleCooldownSeconds: 10,
        checkTransactions: true,
        maxRequests: 2,
        resettables: [
            $cartSession,
            fn () => RoadRunnerSampleLegacyRegistry::$cache = [],
        ],
    );

    $leakless = new Leakless($config);

    // Simulated RoadRunner requests queue
    $simulatedRequests = ['req_1', 'req_2', 'req_3'];
    $processedCount = 0;
    $stopped = false;

    foreach ($simulatedRequests as $request) {
        $leakless->startRequest();
        $shouldRecycle = false;

        try {
            // Simulate request processing modifying cart and static cache
            $cartSession->items[] = 'item_for_'.$request;
            RoadRunnerSampleLegacyRegistry::$cache['key'] = 'val_'.$request;
            $processedCount++;
        } finally {
            $report = $leakless->endRequest(['request' => $request]);
            $shouldRecycle = $report->shouldRecycle;
        }

        if ($shouldRecycle) {
            $stopped = true;
            break;
        }
    }

    expect($processedCount)->toBe(2)
        ->and($stopped)->toBeTrue()
        ->and($cartSession->items)->toBeEmpty()
        ->and(RoadRunnerSampleLegacyRegistry::$cache)->toBeEmpty();
});
