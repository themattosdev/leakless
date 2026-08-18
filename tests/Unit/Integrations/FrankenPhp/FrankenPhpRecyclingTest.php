<?php

declare(strict_types=1);

use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\DTOs\Report;
use TheMattos\Leakless\Integrations\FrankenPhp\FrankenPhp;
use TheMattos\Leakless\Leakless;

test('frankenphp loop terminates gracefully when maxRequests ceiling is reached', function () {
    $executed = 0;
    $recycledReport = null;

    $config = new Config(
        autoRecycleOnViolation: false,
        maxRequests: 3,
        logViolations: false,
    );

    $guardian = FrankenPhp::run(
        app: function () use (&$executed) {
            $executed++;
        },
        config: $config,
        maxLoops: 10, // Without maxRequests, would run 10 times
    );

    expect($executed)->toBe(3)
        ->and($guardian->getRequestCount())->toBe(3)
        ->and($guardian->getLastReport())->not->toBeNull()
        ->and($guardian->getLastReport()?->shouldRecycle)->toBeTrue()
        ->and($guardian->getLastReport()?->recycleReason)->toContain('Max requests ceiling reached');
});

test('frankenphp loop invokes custom recycler callback when recycling is triggered', function () {
    $recycledCalled = false;
    $capturedReport = null;

    $recycler = function (Report $report) use (&$recycledCalled, &$capturedReport) {
        $recycledCalled = true;
        $capturedReport = $report;
    };

    $config = new Config(
        autoRecycleOnViolation: true,
        maxRequests: 1,
        logViolations: false,
    );

    $leakless = new Leakless(
        config: $config,
        recycler: $recycler,
    );

    $executed = 0;

    FrankenPhp::run(
        app: function () use (&$executed) {
            $executed++;
        },
        guardian: $leakless,
        maxLoops: 5,
    );

    expect($executed)->toBe(1)
        ->and($recycledCalled)->toBeTrue()
        ->and($capturedReport)->not->toBeNull()
        ->and($capturedReport?->shouldRecycle)->toBeTrue();
});
