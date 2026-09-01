<?php

declare(strict_types=1);

namespace Tests\Unit\Dev\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TheMattos\Leakless\Dev\Console\Application;
use TheMattos\Leakless\Dev\Console\Commands\AnalyzeCommand;

test('it executes analyze command on clean source and passes with zero exit code', function () {
    $runner = function (array $cmd): array {
        return [
            0,
            json_encode([
                'totals' => ['errors' => 0, 'file_errors' => 0],
                'files' => [],
            ]) ?: '{}',
            '',
        ];
    };

    $command = new AnalyzeCommand($runner);
    $tester = new CommandTester($command);

    $exitCode = $tester->execute([
        'paths' => ['packages/runtime/src'],
    ]);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('LEAKLESS')
        ->and($tester->getDisplay())->toContain('PASS');
});

test('it executes analyze command with json output flag', function () {
    $runner = function (array $cmd): array {
        return [
            0,
            json_encode([
                'totals' => ['errors' => 0, 'file_errors' => 0],
                'files' => [],
            ]) ?: '{}',
            '',
        ];
    };

    $command = new AnalyzeCommand($runner);
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
    $runner = function (array $cmd): array {
        return [
            1,
            json_encode([
                'totals' => ['errors' => 0, 'file_errors' => 1],
                'files' => [
                    '/app/LeakyService.php' => [
                        'errors' => 1,
                        'messages' => [
                            [
                                'message' => 'Mutable static property $cache detected',
                                'line' => 15,
                                'identifier' => 'leakless.mutableStaticProperty',
                            ],
                        ],
                    ],
                ],
            ]) ?: '{}',
            '',
        ];
    };

    $command = new AnalyzeCommand($runner);
    $tester = new CommandTester($command);

    $exitCode = $tester->execute([
        'paths' => ['app'],
    ]);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($tester->getDisplay())->toContain('FAIL')
        ->and($tester->getDisplay())->toContain('LeakyService.php')
        ->and($tester->getDisplay())->toContain('Line 15:')
        ->and($tester->getDisplay())->toContain('leakless.mutableStaticProperty');
});

test('it executes analyze command when analysis engine fails', function () {
    $runner = function (array $cmd): array {
        return [
            1,
            'invalid non-json output',
            'Fatal PHP error during analysis',
        ];
    };

    $command = new AnalyzeCommand($runner);
    $tester = new CommandTester($command);

    $exitCode = $tester->execute([
        'paths' => ['app'],
    ]);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($tester->getDisplay())->toContain('Analysis engine execution failed')
        ->and($tester->getDisplay())->toContain('Fatal PHP error during analysis');
});

test('application boots with default version and analyze command', function () {
    $app = new Application;

    expect($app->getName())->toBe('Leakless')
        ->and($app->getVersion())->toBe(Application::VERSION)
        ->and($app->has('analyze'))->toBeTrue();
});
