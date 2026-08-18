<?php

declare(strict_types=1);

use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Guards\FileDescriptorGuard;
use TheMattos\Leakless\Leakless;

test('it captures open file descriptors on Linux systems', function () {
    $guard = new FileDescriptorGuard;
    $descriptors = $guard->captureOpenDescriptors();

    if (is_dir('/proc/self/fd')) {
        expect($descriptors)->not->toBeEmpty();
    } else {
        expect($descriptors)->toBeArray();
    }
});

test('it detects unclosed file descriptors created during request', function () {
    $guard = new FileDescriptorGuard;
    $initial = $guard->captureOpenDescriptors();

    // Open a temporary file without closing
    $tmpFile = tmpfile();
    expect($tmpFile)->not->toBeFalse();

    $audit = $guard->audit($initial);

    if (is_dir('/proc/self/fd')) {
        expect($audit['detected'])->toBeTrue()
            ->and($audit['leakedCount'])->toBeGreaterThanOrEqual(1)
            ->and($audit['leakedDescriptors'])->not->toBeEmpty();
    }

    if (is_resource($tmpFile)) {
        fclose($tmpFile);
    }
});

test('it reports clean state when opened files are properly closed', function () {
    $guard = new FileDescriptorGuard;
    $initial = $guard->captureOpenDescriptors();

    // Open and close file cleanly
    $tmpFile = tmpfile();
    expect($tmpFile)->not->toBeFalse();
    if (is_resource($tmpFile)) {
        fclose($tmpFile);
    }

    $audit = $guard->audit($initial);

    expect($audit['detected'])->toBeFalse()
        ->and($audit['leakedCount'])->toBe(0)
        ->and($audit['leakedDescriptors'])->toBeEmpty();
});

test('it falls back gracefully when proc path is invalid or inaccessible', function () {
    $guard = new FileDescriptorGuard('/non/existent/proc/path');

    expect($guard->captureOpenDescriptors())->toBeEmpty();

    $audit = $guard->audit([]);
    expect($audit['detected'])->toBeFalse()
        ->and($audit['leakedCount'])->toBe(0);
});

test('leakless lifecycle records file descriptor leaks in report', function () {
    $config = new Config(
        checkFileDescriptors: true,
        logViolations: false,
    );

    $leakless = new Leakless($config);
    $leakless->startRequest();

    $tmp = tmpfile();

    $report = $leakless->endRequest();

    if (is_dir('/proc/self/fd')) {
        expect($report->fileDescriptorsLeaked)->toBeTrue()
            ->and($report->fileDescriptorsLeakedCount)->toBeGreaterThanOrEqual(1)
            ->and($report->isClean())->toBeFalse();
    }

    if (is_resource($tmp)) {
        fclose($tmp);
    }
});
