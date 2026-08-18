<?php

declare(strict_types=1);

namespace Tests\Unit\Dev\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TheMattos\Leakless\Dev\Console\Application;
use TheMattos\Leakless\Dev\Console\Commands\AnalyzeCommand;

test('it executes analyze command on clean source and passes with zero exit code', function () {
    $command = new AnalyzeCommand;
    $tester = new CommandTester($command);

    $exitCode = $tester->execute([
        'paths' => ['packages/runtime/src'],
    ]);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('LEAKLESS')
        ->and($tester->getDisplay())->toContain('PASS');
});

test('it executes analyze command with json output flag', function () {
    $command = new AnalyzeCommand;
    $tester = new CommandTester($command);

    $exitCode = $tester->execute([
        'paths' => ['packages/runtime/src'],
        '--json' => true,
    ]);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('"totals"')
        ->and($tester->getDisplay())->toContain('"errors":0');
});

test('it executes analyze command on leaky fixtures and fails with error exit code', function () {
    $command = new AnalyzeCommand;
    $tester = new CommandTester($command);

    $exitCode = $tester->execute([
        'paths' => ['tests/Fixtures/PHPStan'],
    ]);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($tester->getDisplay())->toContain('FAIL')
        ->and($tester->getDisplay())->toContain('leakless.');
});

test('application boots with default version and analyze command', function () {
    $app = new Application;

    expect($app->getName())->toBe('Leakless')
        ->and($app->getVersion())->toBe(Application::VERSION)
        ->and($app->has('analyze'))->toBeTrue();
});
