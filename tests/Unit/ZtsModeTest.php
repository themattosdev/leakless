<?php

declare(strict_types=1);

use Mockery\MockInterface;
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\DTOs\ProcessMetrics;
use TheMattos\Leakless\Leakless;
use TheMattos\Leakless\Support\ProcStatmParser;

test('it auto-detects or explicitly sets ZTS mode in configuration', function () {
    $defaultGuardian = new Leakless(new Config(maxDriftMb: 64));
    expect($defaultGuardian->isZts())->toBe(defined('PHP_ZTS') && PHP_ZTS === 1);

    $forcedZts = new Leakless(new Config(maxDriftMb: 64, ztsAware: true));
    expect($forcedZts->isZts())->toBeTrue();

    $forcedNts = new Leakless(new Config(maxDriftMb: 64, ztsAware: false));
    expect($forcedNts->isZts())->toBeFalse();
});

test('it validates ZTS-specific configuration limits', function () {
    expect(fn () => new Config(threadToleranceMb: -1.0))
        ->toThrow(InvalidArgumentException::class, 'threadToleranceMb cannot be negative');

    expect(fn () => new Config(unattributedViolationsThreshold: 0))
        ->toThrow(InvalidArgumentException::class, 'unattributedViolationsThreshold must be greater than 0');
});

test('it protects innocent threads against noisy neighbor RSS spikes in ZTS mode', function () {
    /** @var ProcStatmParser&MockInterface $parser */
    $parser = Mockery::mock(ProcStatmParser::class);

    $baseline = new ProcessMetrics(
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

    // Process RSS drifted by +80MB (to 180MB), but current thread Zend memory only grew by 0.1MB (10.1MB)
    $noisyNeighborMetrics = new ProcessMetrics(
        rssBytes: 180 * 1024 * 1024,
        rssMb: 180.0,
        virtualBytes: 300 * 1024 * 1024,
        virtualMb: 300.0,
        sharedBytes: 20 * 1024 * 1024,
        sharedMb: 20.0,
        zendMemoryUsageBytes: (int) (10.1 * 1024 * 1024),
        zendMemoryUsageMb: 10.1,
        zendMemoryPeakBytes: 15 * 1024 * 1024,
        zendMemoryPeakMb: 15.0,
    );

    $parser->shouldReceive('parse')->andReturn($noisyNeighborMetrics);

    $config = new Config(
        maxDriftMb: 64,
        driftJitterPercentage: 0,
        ztsAware: true,
        threadToleranceMb: 2.0,
        consecutiveViolationsThreshold: 3,
        unattributedViolationsThreshold: 5,
        triggerGcOnBreach: false,
    );

    $guardian = new Leakless($config, statmParser: $parser);
    $guardian->setBaselineMetrics($baseline);

    $guardian->startRequest();
    $report = $guardian->endRequest();

    expect($report->isZts)->toBeTrue()
        ->and($report->driftAttributedToThread)->toBeFalse()
        ->and($report->unattributedProcessDrift)->toBeTrue()
        ->and($report->consecutiveViolationsCount)->toBe(0)
        ->and($guardian->getConsecutiveViolations())->toBe(0)
        ->and($guardian->getUnattributedViolations())->toBe(1)
        ->and($report->shouldRecycle)->toBeFalse();
});

test('it attributes drift to the leaking thread when Zend memory grows in ZTS mode', function () {
    /** @var ProcStatmParser&MockInterface $parser */
    $parser = Mockery::mock(ProcStatmParser::class);

    $baseline = new ProcessMetrics(
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

    // Process RSS drifted by +80MB (to 180MB), and current thread Zend memory grew by +30MB (to 40MB)
    $leakingThreadMetrics = new ProcessMetrics(
        rssBytes: 180 * 1024 * 1024,
        rssMb: 180.0,
        virtualBytes: 300 * 1024 * 1024,
        virtualMb: 300.0,
        sharedBytes: 20 * 1024 * 1024,
        sharedMb: 20.0,
        zendMemoryUsageBytes: 40 * 1024 * 1024,
        zendMemoryUsageMb: 40.0,
        zendMemoryPeakBytes: 45 * 1024 * 1024,
        zendMemoryPeakMb: 45.0,
    );

    $parser->shouldReceive('parse')->andReturn($leakingThreadMetrics);

    $config = new Config(
        maxDriftMb: 64,
        driftJitterPercentage: 0,
        ztsAware: true,
        threadToleranceMb: 2.0,
        consecutiveViolationsThreshold: 2,
        triggerGcOnBreach: false,
    );

    $guardian = new Leakless($config, statmParser: $parser);
    $guardian->setBaselineMetrics($baseline);

    // Request 1: First violation
    $guardian->startRequest();
    $report1 = $guardian->endRequest();

    expect($report1->driftAttributedToThread)->toBeTrue()
        ->and($report1->unattributedProcessDrift)->toBeFalse()
        ->and($report1->consecutiveViolationsCount)->toBe(1)
        ->and($report1->shouldRecycle)->toBeFalse();

    // Request 2: Second violation hits threshold of 2
    $guardian->startRequest();
    $report2 = $guardian->endRequest();

    expect($report2->consecutiveViolationsCount)->toBe(2)
        ->and($report2->shouldRecycle)->toBeTrue()
        ->and($report2->recycleReason)->toContain('Thread memory drift limit exceeded');
});

test('it recycles worker on persistent unattributed process drift when maxRssMb is null (native C leak)', function () {
    /** @var ProcStatmParser&MockInterface $parser */
    $parser = Mockery::mock(ProcStatmParser::class);

    $baseline = new ProcessMetrics(
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

    // Process RSS drifted by +80MB (to 180MB), but Zend MM is flat (e.g. malloc leak from C extension)
    $cLeakMetrics = new ProcessMetrics(
        rssBytes: 180 * 1024 * 1024,
        rssMb: 180.0,
        virtualBytes: 300 * 1024 * 1024,
        virtualMb: 300.0,
        sharedBytes: 20 * 1024 * 1024,
        sharedMb: 20.0,
        zendMemoryUsageBytes: 10 * 1024 * 1024,
        zendMemoryUsageMb: 10.0,
        zendMemoryPeakBytes: 15 * 1024 * 1024,
        zendMemoryPeakMb: 15.0,
    );

    $parser->shouldReceive('parse')->andReturn($cLeakMetrics);

    $config = new Config(
        maxDriftMb: 64,
        maxRssMb: null, // maxRssMb is null!
        driftJitterPercentage: 0,
        ztsAware: true,
        threadToleranceMb: 2.0,
        consecutiveViolationsThreshold: 5,
        unattributedViolationsThreshold: 3, // recycle after 3 unattributed violations
        triggerGcOnBreach: false,
    );

    $guardian = new Leakless($config, statmParser: $parser);
    $guardian->setBaselineMetrics($baseline);

    // Request 1: First unattributed breach
    $guardian->startRequest();
    $r1 = $guardian->endRequest();
    expect($r1->shouldRecycle)->toBeFalse()
        ->and($guardian->getUnattributedViolations())->toBe(1);

    // Request 2: Second unattributed breach
    $guardian->startRequest();
    $r2 = $guardian->endRequest();
    expect($r2->shouldRecycle)->toBeFalse()
        ->and($guardian->getUnattributedViolations())->toBe(2);

    // Request 3: Third unattributed breach reaches threshold of 3 -> triggers recycle
    $guardian->startRequest();
    $r3 = $guardian->endRequest();
    expect($r3->shouldRecycle)->toBeTrue()
        ->and($r3->recycleReason)->toContain('possible native C extension leak or allocator fragmentation');
});

test('it still enforces emergency maxRssMb hard ceiling immediately in ZTS mode', function () {
    /** @var ProcStatmParser&MockInterface $parser */
    $parser = Mockery::mock(ProcStatmParser::class);

    $emergencyMetrics = new ProcessMetrics(
        rssBytes: 500 * 1024 * 1024,
        rssMb: 500.0,
        virtualBytes: 600 * 1024 * 1024,
        virtualMb: 600.0,
        sharedBytes: 20 * 1024 * 1024,
        sharedMb: 20.0,
        zendMemoryUsageBytes: 10 * 1024 * 1024,
        zendMemoryUsageMb: 10.0,
        zendMemoryPeakBytes: 15 * 1024 * 1024,
        zendMemoryPeakMb: 15.0,
    );

    $parser->shouldReceive('parse')->andReturn($emergencyMetrics);

    $config = new Config(
        maxDriftMb: 64,
        maxRssMb: 400, // Hard ceiling at 400MB
        ztsAware: true,
    );

    $guardian = new Leakless($config, statmParser: $parser);

    $guardian->startRequest();
    $report = $guardian->endRequest();

    expect($report->shouldRecycle)->toBeTrue()
        ->and($report->recycleReason)->toContain('Emergency RSS memory ceiling exceeded: 500MB > 400MB');
});
