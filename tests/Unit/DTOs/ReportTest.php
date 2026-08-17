<?php

declare(strict_types=1);

use TheMattos\Leakless\DTOs\ProcessMetrics;
use TheMattos\Leakless\DTOs\Report;

test('it calculates memory drift and assesses clean state', function () {
    $initial = ProcessMetrics::fromPages(
        totalProgramPages: 10000,
        residentPages: 5000, // 19.53 MB
        sharedPages: 1000,
        pageSize: 4096,
    );

    $final = ProcessMetrics::fromPages(
        totalProgramPages: 12000,
        residentPages: 6000, // 23.44 MB
        sharedPages: 1000,
        pageSize: 4096,
    );

    $report = new Report(
        initialMetrics: $initial,
        finalMetrics: $final,
        durationMs: 14.5,
        danglingTransactionsDetected: false,
        danglingTransactionsCount: 0,
        shouldRecycle: false,
        recycleReason: null,
    );

    expect($report->memoryDriftMb)->toBe(round($final->rssMb - $initial->rssMb, 2))
        ->and($report->durationMs)->toBe(14.5)
        ->and($report->isClean())->toBeTrue();
});

test('it identifies dirty state when transactions leak or recycling is flagged', function () {
    $initial = ProcessMetrics::fallback();
    $final = ProcessMetrics::fallback();

    $reportWithTransaction = new Report(
        initialMetrics: $initial,
        finalMetrics: $final,
        durationMs: 5.2,
        danglingTransactionsDetected: true,
        danglingTransactionsCount: 1,
        danglingTransactionBacktraces: [['file' => 'OrderService.php', 'line' => 42]],
        shouldRecycle: false,
    );

    expect($reportWithTransaction->isClean())->toBeFalse()
        ->and($reportWithTransaction->danglingTransactionsCount)->toBe(1);

    $reportWithRecycle = new Report(
        initialMetrics: $initial,
        finalMetrics: $final,
        durationMs: 2.1,
        shouldRecycle: true,
        recycleReason: 'Max RSS reached',
    );

    expect($reportWithRecycle->isClean())->toBeFalse()
        ->and($reportWithRecycle->shouldRecycle)->toBeTrue()
        ->and($reportWithRecycle->recycleReason)->toBe('Max RSS reached');
});
