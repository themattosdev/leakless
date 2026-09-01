<?php

declare(strict_types=1);

use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\DTOs\Report;

test('it instantiates with default parameters', function () {
    $config = new Config;

    expect($config->maxDriftMb)->toBe(64)
        ->and($config->maxRssMb)->toBeNull()
        ->and($config->checkTransactions)->toBeTrue()
        ->and($config->checkFileDescriptors)->toBeFalse()
        ->and($config->autoRecycleOnViolation)->toBeTrue()
        ->and($config->maxRequests)->toBeNull()
        ->and($config->consecutiveViolationsThreshold)->toBe(5)
        ->and($config->recycleCooldownSeconds)->toBe(10)
        ->and($config->triggerGcOnBreach)->toBeTrue()
        ->and($config->driftJitterPercentage)->toBe(10)
        ->and($config->logViolations)->toBeTrue()
        ->and($config->onReport)->toBeNull()
        ->and($config->resettables)->toBe([]);
});

test('it accepts custom named arguments', function () {
    $callback = function (Report $report): void {};
    $dummyResettable = fn () => null;

    $config = new Config(
        maxDriftMb: 128,
        maxRssMb: 256,
        checkTransactions: false,
        checkFileDescriptors: true,
        autoRecycleOnViolation: false,
        maxRequests: 500,
        consecutiveViolationsThreshold: 10,
        recycleCooldownSeconds: 60,
        triggerGcOnBreach: false,
        driftJitterPercentage: 0,
        logViolations: false,
        onReport: $callback,
        resettables: [$dummyResettable],
    );

    expect($config->maxDriftMb)->toBe(128)
        ->and($config->maxRssMb)->toBe(256)
        ->and($config->checkTransactions)->toBeFalse()
        ->and($config->checkFileDescriptors)->toBeTrue()
        ->and($config->autoRecycleOnViolation)->toBeFalse()
        ->and($config->maxRequests)->toBe(500)
        ->and($config->consecutiveViolationsThreshold)->toBe(10)
        ->and($config->recycleCooldownSeconds)->toBe(60)
        ->and($config->triggerGcOnBreach)->toBeFalse()
        ->and($config->driftJitterPercentage)->toBe(0)
        ->and($config->logViolations)->toBeFalse()
        ->and($config->onReport)->toBe($callback)
        ->and($config->resettables)->toBe([$dummyResettable]);
});

test('it throws exception for invalid maxDriftMb', function () {
    expect(fn () => new Config(maxDriftMb: 0))
        ->toThrow(InvalidArgumentException::class, 'maxDriftMb must be greater than 0');
});

test('it throws exception for invalid maxRssMb', function () {
    expect(fn () => new Config(maxRssMb: 0))
        ->toThrow(InvalidArgumentException::class, 'maxRssMb must be greater than 0');
});

test('it throws exception for invalid maxRequests', function () {
    expect(fn () => new Config(maxRequests: -5))
        ->toThrow(InvalidArgumentException::class, 'maxRequests must be greater than 0');
});

test('it throws exception for invalid consecutiveViolationsThreshold', function () {
    expect(fn () => new Config(consecutiveViolationsThreshold: 0))
        ->toThrow(InvalidArgumentException::class, 'consecutiveViolationsThreshold must be greater than 0');
});

test('it throws exception for invalid recycleCooldownSeconds', function () {
    expect(fn () => new Config(recycleCooldownSeconds: -1))
        ->toThrow(InvalidArgumentException::class, 'recycleCooldownSeconds cannot be negative');
});

test('it throws exception for invalid driftJitterPercentage', function () {
    expect(fn () => new Config(driftJitterPercentage: 150))
        ->toThrow(InvalidArgumentException::class, 'driftJitterPercentage must be between 0 and 100');
});
