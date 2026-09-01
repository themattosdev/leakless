<?php

declare(strict_types=1);

use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\DTOs\ProcessMetrics;
use TheMattos\Leakless\Leakless;
use TheMattos\Leakless\Support\ProcStatmParser;

test('it captures baseline memory metrics automatically on first request or explicitly', function () {
    $guardian = new Leakless(new Config(maxDriftMb: 64));

    expect($guardian->getBaselineMetrics())->toBeNull();

    $guardian->startRequest();
    $guardian->endRequest();

    expect($guardian->getBaselineMetrics())->not->toBeNull()
        ->and($guardian->getLastReport()?->baselineRssMb)->toBe($guardian->getBaselineMetrics()?->rssMb);
});

test('it allows explicit baseline metrics injection at worker startup', function () {
    $guardian = new Leakless(new Config(maxDriftMb: 64, driftJitterPercentage: 0));

    $customBaseline = new ProcessMetrics(
        rssBytes: 100 * 1024 * 1024,
        rssMb: 100.0,
        virtualBytes: 200 * 1024 * 1024,
        virtualMb: 200.0,
        sharedBytes: 20 * 1024 * 1024,
        sharedMb: 20.0,
        zendMemoryUsageBytes: 10 * 1024 * 1024,
        zendMemoryUsageMb: 10.0,
        zendMemoryPeakBytes: 15 * 1024 * 1024,
        zendMemoryPeakMb: 15.0,
    );

    $guardian->setBaselineMetrics($customBaseline);

    expect($guardian->getBaselineMetrics())->toBe($customBaseline)
        ->and($guardian->getEffectiveDriftLimitMb())->toBe(64.0);
});

test('it applies jitter to drift limit according to configured percentage', function () {
    $config = new Config(
        maxDriftMb: 100,
        driftJitterPercentage: 20,
    );

    $guardian = new Leakless($config);
    $driftLimit = $guardian->getEffectiveDriftLimitMb();

    expect($driftLimit)->toBeGreaterThanOrEqual(80.0)
        ->and($driftLimit)->toBeLessThanOrEqual(120.0);
});

test('it requires consecutive violations threshold (hysteresis) before triggering worker recycling', function () {
    $recycled = false;
    $recycleCount = 0;

    $config = new Config(
        maxDriftMb: 50,
        consecutiveViolationsThreshold: 3,
        recycleCooldownSeconds: 0,
        driftJitterPercentage: 0,
        triggerGcOnBreach: false,
    );

    $fakeStatm = tempnam(sys_get_temp_dir(), 'leakless_drift_statm_');
    assert($fakeStatm !== false);

    // Initial baseline: 100MB RSS (25600 pages * 4096)
    file_put_contents($fakeStatm, '50000 25600 5000 100 0 5000 0');
    $parser = new ProcStatmParser(statmPath: $fakeStatm);

    $guardian = new Leakless(
        config: $config,
        statmParser: $parser,
        recycler: function () use (&$recycled, &$recycleCount): void {
            $recycled = true;
            $recycleCount++;
        },
    );

    // Request 1: captures baseline 100MB
    $guardian->startRequest();
    $r1 = $guardian->endRequest();
    expect($r1->shouldRecycle)->toBeFalse()
        ->and($guardian->getConsecutiveViolations())->toBe(0);

    // Request 2: Spike to 180MB (+80MB drift > 50MB limit) -> Violation 1
    file_put_contents($fakeStatm, '50000 46080 5000 100 0 5000 0');
    $guardian->startRequest();
    $r2 = $guardian->endRequest();
    expect($r2->shouldRecycle)->toBeFalse()
        ->and($guardian->getConsecutiveViolations())->toBe(1)
        ->and($recycled)->toBeFalse();

    // Request 3: Temporary drop back to 110MB (+10MB drift < 50MB) -> Reset violations to 0
    file_put_contents($fakeStatm, '50000 28160 5000 100 0 5000 0');
    $guardian->startRequest();
    $r3 = $guardian->endRequest();
    expect($r3->shouldRecycle)->toBeFalse()
        ->and($guardian->getConsecutiveViolations())->toBe(0);

    // Request 4: Breach 1 (180MB)
    file_put_contents($fakeStatm, '50000 46080 5000 100 0 5000 0');
    $guardian->startRequest();
    $guardian->endRequest();
    expect($guardian->getConsecutiveViolations())->toBe(1);

    // Request 5: Breach 2 (180MB)
    $guardian->startRequest();
    $guardian->endRequest();
    expect($guardian->getConsecutiveViolations())->toBe(2)
        ->and($recycled)->toBeFalse();

    // Request 6: Breach 3 (180MB) -> Hits threshold 3 -> Triggers recycle
    $guardian->startRequest();
    $r6 = $guardian->endRequest();
    expect($r6->shouldRecycle)->toBeTrue()
        ->and($recycled)->toBeTrue()
        ->and($recycleCount)->toBe(1)
        ->and($guardian->getConsecutiveViolations())->toBe(0);

    @unlink($fakeStatm);
});

test('it respects recycling cooldown window and prevents restart storms', function () {
    $recycleCount = 0;
    $logged = [];

    $config = new Config(
        maxDriftMb: 50,
        consecutiveViolationsThreshold: 1,
        recycleCooldownSeconds: 30,
        driftJitterPercentage: 0,
        triggerGcOnBreach: false,
        logViolations: true,
        logger: function (string $msg) use (&$logged): void {
            $logged[] = $msg;
        },
    );

    $fakeStatm = tempnam(sys_get_temp_dir(), 'leakless_cooldown_statm_');
    assert($fakeStatm !== false);

    // 100MB baseline
    file_put_contents($fakeStatm, '50000 25600 5000 100 0 5000 0');
    $parser = new ProcStatmParser(statmPath: $fakeStatm);

    $guardian = new Leakless(
        config: $config,
        statmParser: $parser,
        recycler: function () use (&$recycleCount): void {
            $recycleCount++;
        },
    );

    $guardian->captureBaselineMetrics();

    // Spike memory to 180MB
    file_put_contents($fakeStatm, '50000 46080 5000 100 0 5000 0');

    // First breach triggers recycle (first time, cooldown is clear)
    $guardian->startRequest();
    $r1 = $guardian->endRequest();
    expect($r1->shouldRecycle)->toBeTrue()
        ->and($r1->cooldownActive)->toBeFalse()
        ->and($recycleCount)->toBe(1);

    // Immediate second breach right after (<30s) -> Throttled by cooldown
    $guardian->startRequest();
    $r2 = $guardian->endRequest();
    expect($r2->shouldRecycle)->toBeFalse()
        ->and($r2->cooldownActive)->toBeTrue()
        ->and($recycleCount)->toBe(1)
        ->and($r2->recycleReason)->toContain('cooldown window (30s)')
        ->and($logged)->toContain('[Leakless] ⏳ '.$r2->recycleReason);

    // Simulate passage of 31 seconds
    $guardian->setLastRecycleTimestamp(microtime(true) - 31);

    // Third breach after cooldown has elapsed -> Recycle allowed again
    $guardian->startRequest();
    $r3 = $guardian->endRequest();
    expect($r3->shouldRecycle)->toBeTrue()
        ->and($r3->cooldownActive)->toBeFalse()
        ->and($recycleCount)->toBe(2);

    @unlink($fakeStatm);
});

test('it triggers emergency recycling immediately when hard maxRssMb ceiling is exceeded', function () {
    $recycled = false;

    $config = new Config(
        maxDriftMb: 500, // soft limit is high
        maxRssMb: 200,   // hard limit is 200MB
        consecutiveViolationsThreshold: 10,
        triggerGcOnBreach: false,
    );

    $fakeStatm = tempnam(sys_get_temp_dir(), 'leakless_hard_statm_');
    assert($fakeStatm !== false);

    // 250MB RSS (64000 pages * 4096)
    file_put_contents($fakeStatm, '70000 64000 5000 100 0 5000 0');
    $parser = new ProcStatmParser(statmPath: $fakeStatm);

    $guardian = new Leakless(
        config: $config,
        statmParser: $parser,
        recycler: function () use (&$recycled): void {
            $recycled = true;
        },
    );

    $guardian->startRequest();
    $report = $guardian->endRequest();

    expect($report->shouldRecycle)->toBeTrue()
        ->and($recycled)->toBeTrue()
        ->and($report->recycleReason)->toContain('Emergency RSS memory ceiling exceeded: 250MB > 200MB');

    @unlink($fakeStatm);
});

test('it re-evaluates physical memory after gc_collect_cycles when triggerGcOnBreach is enabled', function () {
    $config = new Config(
        maxDriftMb: 50,
        triggerGcOnBreach: true,
        driftJitterPercentage: 0,
    );

    $fakeStatm = tempnam(sys_get_temp_dir(), 'leakless_gc_statm_');
    assert($fakeStatm !== false);

    // Initial baseline: 100MB
    file_put_contents($fakeStatm, '50000 25600 5000 100 0 5000 0');
    $parser = new ProcStatmParser(statmPath: $fakeStatm);

    $guardian = new Leakless(
        config: $config,
        statmParser: $parser,
    );

    $guardian->startRequest();

    // In a real environment, cyclic references would be collected by gc_collect_cycles().
    // We simulate memory dropping back to 110MB on subsequent read after GC.
    file_put_contents($fakeStatm, '50000 28160 5000 100 0 5000 0');

    $report = $guardian->endRequest();

    expect($report->shouldRecycle)->toBeFalse()
        ->and($guardian->getConsecutiveViolations())->toBe(0);

    @unlink($fakeStatm);
});
