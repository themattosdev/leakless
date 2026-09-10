<?php

declare(strict_types=1);

namespace Tests\Unit\Dev\Analysis;

use TheMattos\Leakless\Dev\Analysis\WorkerSafetyAnalyzer;

test('it analyzes clean code with zero violations', function () {
    $analyzer = new WorkerSafetyAnalyzer;
    $report = $analyzer->analyze(['packages/runtime/src/Attributes']);

    expect($report['totals']['file_errors'])->toBe(0)
        ->and($report['totals']['errors'])->toBe(0)
        ->and($report['files'])->toBeEmpty();
});

test('it detects incompatible worker functions in fixtures', function () {
    $analyzer = new WorkerSafetyAnalyzer;
    $report = $analyzer->analyze(['tests/Fixtures/PHPStan/IncompatibleFunctionsFixture.php']);

    expect($report['totals']['file_errors'])->toBe(1);

    $files = array_values($report['files']);
    $fileData = $files[0];
    expect($fileData['errors'])->toBe(9);

    $identifiers = array_column($fileData['messages'], 'identifier');
    expect($identifiers)->toContain('leakless.incompatibleFunction')
        ->and($identifiers)->toContain('leakless.globBraceIncompatible')
        ->and($identifiers)->toContain('leakless.imapNotThreadSafe');
});

test('it detects superglobals, session_start, and process terminators in fixtures', function () {
    $analyzer = new WorkerSafetyAnalyzer;
    $report = $analyzer->analyze(['tests/Fixtures/PHPStan/SuperglobalsFixture.php']);

    expect($report['totals']['file_errors'])->toBe(1);

    $files = array_values($report['files']);
    $fileData = $files[0];
    expect($fileData['errors'])->toBe(7);

    $identifiers = array_column($fileData['messages'], 'identifier');
    expect($identifiers)->toContain('leakless.superglobal')
        ->and($identifiers)->toContain('leakless.sessionStart')
        ->and($identifiers)->toContain('leakless.processTerminator');
});

test('it detects mutable static properties without attributes in fixtures', function () {
    $analyzer = new WorkerSafetyAnalyzer;
    $report = $analyzer->analyze(['tests/Fixtures/PHPStan/MutableStaticFixture.php']);

    expect($report['totals']['file_errors'])->toBe(1);

    $files = array_values($report['files']);
    $fileData = $files[0];
    expect($fileData['errors'])->toBe(1);

    $message = $fileData['messages'][0];
    expect($message['identifier'])->toBe('leakless.mutableStaticProperty')
        ->and($message['line'])->toBe(12)
        ->and($message['message'])->toContain('Mutable static property');
});

test('it detects ephemeral request injection in service constructors in fixtures', function () {
    $analyzer = new WorkerSafetyAnalyzer;
    $report = $analyzer->analyze(['tests/Fixtures/PHPStan/EphemeralInjectionFixture.php']);

    expect($report['totals']['file_errors'])->toBe(1);

    $files = array_values($report['files']);
    $fileData = $files[0];
    expect($fileData['errors'])->toBe(1);

    $message = $fileData['messages'][0];
    expect($message['identifier'])->toBe('leakless.ephemeralSingletonInjection')
        ->and($message['line'])->toBe(12)
        ->and($message['message'])->toContain('Ephemeral request-scoped dependency');
});

test('it handles non existent or unreadable files gracefully', function () {
    $analyzer = new WorkerSafetyAnalyzer;
    $report = $analyzer->analyze(['non/existent/path/File.php']);

    expect($report['totals']['file_errors'])->toBe(0)
        ->and($report['files'])->toBeEmpty();
});
