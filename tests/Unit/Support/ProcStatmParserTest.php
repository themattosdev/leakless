<?php

declare(strict_types=1);

use TheMattos\Leakless\Support\ProcStatmParser;

test('it parses valid statm string output correctly', function () {
    $parser = new ProcStatmParser;

    // Raw statm content: size resident shared text lib data dt
    $rawStatm = '65536 32768 8192 1000 0 50000 0';

    $metrics = $parser->parse($rawStatm);

    expect($metrics->rssBytes)->toBe(32768 * 4096)
        ->and($metrics->rssMb)->toBe(128.0)
        ->and($metrics->virtualBytes)->toBe(65536 * 4096)
        ->and($metrics->virtualMb)->toBe(256.0)
        ->and($metrics->sharedBytes)->toBe(8192 * 4096)
        ->and($metrics->sharedMb)->toBe(32.0);
});

test('it falls back gracefully on empty or malformed statm content', function () {
    $parser = new ProcStatmParser;

    $emptyMetrics = $parser->parse('');
    expect($emptyMetrics->rssBytes)->toBeGreaterThan(0);

    $invalidMetrics = $parser->parse('invalid content');
    expect($invalidMetrics->rssBytes)->toBeGreaterThan(0);
});

test('it can read from real system /proc when available on Linux', function () {
    $parser = new ProcStatmParser;

    if ($parser->isLinuxProcAvailable()) {
        $metrics = $parser->parse();
        expect($metrics->rssBytes)->toBeGreaterThan(0)
            ->and($metrics->rssMb)->toBeGreaterThan(0);
    } else {
        expect($parser->isLinuxProcAvailable())->toBeFalse();
    }
});
