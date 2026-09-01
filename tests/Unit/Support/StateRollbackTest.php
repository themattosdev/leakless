<?php

declare(strict_types=1);

use TheMattos\Leakless\Support\StateRollback;

test('it restores timezone when mutated during request', function () {
    $initialTz = date_default_timezone_get();

    $rollback = new StateRollback;

    // Mutate timezone inside the simulated request
    date_default_timezone_set('Asia/Tokyo');
    expect(date_default_timezone_get())->toBe('Asia/Tokyo');

    $rollback->rollback();

    expect(date_default_timezone_get())->toBe($initialTz);
});

test('it drains residual output buffers', function () {
    $initialLevel = ob_get_level();

    $rollback = new StateRollback;

    // Open extra nested output buffers
    ob_start();
    echo 'leaking output buffer 1';
    ob_start();
    echo 'leaking output buffer 2';

    expect(ob_get_level())->toBe($initialLevel + 2);

    $rollback->rollback();

    expect(ob_get_level())->toBe($initialLevel);
});

test('it restores error reporting levels', function () {
    $initialReporting = error_reporting();

    $rollback = new StateRollback;

    // Mutate error reporting
    error_reporting(0);
    expect(error_reporting())->toBe(0);

    $rollback->rollback();

    expect(error_reporting())->toBe($initialReporting);
});

test('it restores working directory when changed during request', function () {
    $initialDir = getcwd();
    if ($initialDir === false) {
        return;
    }

    $rollback = new StateRollback;

    chdir(sys_get_temp_dir());
    expect(getcwd())->toBe(sys_get_temp_dir());

    $rollback->rollback();

    expect(getcwd())->toBe($initialDir);
});
