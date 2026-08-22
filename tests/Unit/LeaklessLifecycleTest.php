<?php

declare(strict_types=1);

use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\DTOs\Report;
use TheMattos\Leakless\Leakless;
use TheMattos\Leakless\Support\ProcStatmParser;

test('it executes complete request cycle and produces clean report with standard 96MB RSS config', function () {
    $recycled = false;
    $reported = null;

    $config = new Config(
        maxRssMb: 96,
        onReport: function (Report $r) use (&$reported): void {
            $reported = $r;
        },
    );

    $guardian = new Leakless(
        config: $config,
        recycler: function () use (&$recycled): void {
            $recycled = true;
        },
    );

    $guardian->startRequest();
    $report = $guardian->endRequest(['route' => '/api/users']);

    expect($guardian->getRequestCount())->toBe(1)
        ->and($report->isClean())->toBeTrue()
        ->and($report->shouldRecycle)->toBeFalse()
        ->and($report->metadata['route'])->toBe('/api/users')
        ->and($reported)->toBe($report)
        ->and($recycled)->toBeFalse();
});

test('it intercepts and rolls back dangling PDO transactions in request cycle', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE test (id INTEGER PRIMARY KEY)');

    $guardian = new Leakless(new Config(maxRssMb: 96));
    $guardian->registerConnection($pdo);

    $guardian->startRequest();

    // Leak a transaction during request processing
    $pdo->beginTransaction();
    $pdo->exec('INSERT INTO test DEFAULT VALUES');
    expect($pdo->inTransaction())->toBeTrue();

    $report = $guardian->endRequest();

    expect($pdo->inTransaction())->toBeFalse()
        ->and($report->danglingTransactionsDetected)->toBeTrue()
        ->and($report->danglingTransactionsCount)->toBe(1)
        ->and($report->isClean())->toBeFalse();
});

test('it rolls back mutated timezone and output buffers on endRequest', function () {
    $initialTz = date_default_timezone_get();
    $initialOb = ob_get_level();

    $guardian = new Leakless(new Config(maxRssMb: 96));

    $guardian->startRequest();

    // Mutate environment
    date_default_timezone_set('America/New_York');
    ob_start();
    echo 'dirty output buffer';

    $guardian->endRequest();

    expect(date_default_timezone_get())->toBe($initialTz)
        ->and(ob_get_level())->toBe($initialOb);
});

test('it triggers recycling when RSS threshold is breached', function () {
    $recycled = false;
    $recycleReason = null;

    $config = new Config(
        maxRssMb: 96,
        autoRecycleOnViolation: true,
    );

    // Fake statm file simulating 150MB RSS (38400 pages * 4096 bytes)
    $fakeStatm = tempnam(sys_get_temp_dir(), 'leakless_statm_');
    assert($fakeStatm !== false);
    file_put_contents($fakeStatm, '50000 38400 5000 100 0 5000 0');

    $parser = new ProcStatmParser(statmPath: $fakeStatm);

    $guardian = new Leakless(
        config: $config,
        statmParser: $parser,
        recycler: function (Report $report) use (&$recycled, &$recycleReason): void {
            $recycled = true;
            $recycleReason = $report->recycleReason;
        },
    );

    $guardian->startRequest();
    $report = $guardian->endRequest();

    expect($report->shouldRecycle)->toBeTrue()
        ->and($recycled)->toBeTrue()
        ->and($recycleReason)->toContain('150MB > 96MB');

    @unlink($fakeStatm);
});

test('it triggers recycling when maxRequests limit is reached', function () {
    $recycleTriggered = false;
    $recycleReason = null;

    $config = new Config(
        maxRssMb: 96,
        maxRequests: 2,
        autoRecycleOnViolation: true,
    );

    $guardian = new Leakless(
        config: $config,
        recycler: function (Report $report) use (&$recycleTriggered, &$recycleReason): void {
            $recycleTriggered = true;
            $recycleReason = $report->recycleReason;
        },
    );

    // Request 1: should not recycle
    $guardian->startRequest();
    $r1 = $guardian->endRequest();
    expect($r1->shouldRecycle)->toBeFalse()
        ->and($recycleTriggered)->toBeFalse();

    // Request 2: reaches maxRequests -> triggers recycle
    $guardian->startRequest();
    $r2 = $guardian->endRequest();
    expect($r2->shouldRecycle)->toBeTrue()
        ->and($recycleTriggered)->toBeTrue()
        ->and($recycleReason)->toContain('Max requests ceiling reached: 2/2');
});

test('it automatically logs violations when state is dirty', function () {
    $loggedMessages = [];

    $config = new Config(
        maxRssMb: 96,
        maxRequests: 1,
        logViolations: true,
        logger: function (string $msg, Report $r) use (&$loggedMessages): void {
            $loggedMessages[] = $msg;
        },
    );

    $pdo = new PDO('sqlite::memory:');
    $guardian = new Leakless(
        config: $config,
        recycler: function (): void {},
    );
    $guardian->registerConnection($pdo);

    $guardian->startRequest();
    $pdo->beginTransaction(); // trigger dangling transaction
    $guardian->endRequest();

    expect($loggedMessages)->toHaveCount(2)
        ->and($loggedMessages[0])->toContain('[Leakless] 🚨 Dangling database transaction(s) detected')
        ->and($loggedMessages[1])->toContain('[Leakless] ⚠️ Worker recycling triggered');
});
