<?php

declare(strict_types=1);

use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\DTOs\Report;

test('it instantiates with default parameters', function () {
    $config = new Config;

    expect($config->maxRssMb)->toBe(96)
        ->and($config->checkTransactions)->toBeTrue()
        ->and($config->checkFileDescriptors)->toBeFalse()
        ->and($config->autoRecycleOnViolation)->toBeTrue()
        ->and($config->maxRequests)->toBeNull()
        ->and($config->onReport)->toBeNull();
});

test('it accepts custom named arguments', function () {
    $callback = function (Report $report): void {};

    $config = new Config(
        maxRssMb: 256,
        checkTransactions: false,
        checkFileDescriptors: true,
        autoRecycleOnViolation: false,
        maxRequests: 500,
        onReport: $callback,
    );

    expect($config->maxRssMb)->toBe(256)
        ->and($config->checkTransactions)->toBeFalse()
        ->and($config->checkFileDescriptors)->toBeTrue()
        ->and($config->autoRecycleOnViolation)->toBeFalse()
        ->and($config->maxRequests)->toBe(500)
        ->and($config->onReport)->toBe($callback);
});

test('it throws exception for invalid maxRssMb', function () {
    expect(fn () => new Config(maxRssMb: 0))
        ->toThrow(InvalidArgumentException::class, 'maxRssMb must be greater than 0');
});

test('it throws exception for invalid maxRequests', function () {
    expect(fn () => new Config(maxRequests: -5))
        ->toThrow(InvalidArgumentException::class, 'maxRequests must be greater than 0');
});
