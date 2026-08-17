<?php

declare(strict_types=1);

use TheMattos\Leakless\DTOs\ProcessMetrics;

test('it converts page counts accurately into bytes and megabytes', function () {
    // 32768 pages * 4096 bytes = 134,217,728 bytes = 128.00 MB
    // 65536 pages * 4096 bytes = 268,435,456 bytes = 256.00 MB
    // 8192 pages * 4096 bytes = 33,554,432 bytes = 32.00 MB
    $metrics = ProcessMetrics::fromPages(
        totalProgramPages: 65536,
        residentPages: 32768,
        sharedPages: 8192,
        pageSize: 4096,
    );

    expect($metrics->rssBytes)->toBe(134217728)
        ->and($metrics->rssMb)->toBe(128.0)
        ->and($metrics->virtualBytes)->toBe(268435456)
        ->and($metrics->virtualMb)->toBe(256.0)
        ->and($metrics->sharedBytes)->toBe(33554432)
        ->and($metrics->sharedMb)->toBe(32.0)
        ->and($metrics->zendMemoryUsageBytes)->toBeGreaterThan(0)
        ->and($metrics->zendMemoryPeakBytes)->toBeGreaterThan(0);
});

test('it provides fallback metrics when /proc is unavailable', function () {
    $fallback = ProcessMetrics::fallback();

    expect($fallback->rssBytes)->toBeGreaterThan(0)
        ->and($fallback->rssMb)->toBeGreaterThan(0)
        ->and($fallback->virtualBytes)->toBeGreaterThan(0)
        ->and($fallback->zendMemoryUsageBytes)->toBeGreaterThan(0);
});
