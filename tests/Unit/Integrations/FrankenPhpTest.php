<?php

declare(strict_types=1);

use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Integrations\FrankenPhp\FrankenPhp;
use TheMattos\Leakless\Leakless;

test('it executes app handler across worker loops with FrankenPhp helper', function () {
    $executions = 0;
    $pdo = new PDO('sqlite::memory:');

    $guardian = new Leakless(new Config(maxRssMb: 96));
    $guardian->registerConnection($pdo);

    $app = function () use (&$executions, $pdo): void {
        $executions++;
        // Start transaction but don't commit
        $pdo->beginTransaction();
    };

    FrankenPhp::run(
        app: $app,
        guardian: $guardian,
        requestHandlerRunner: function (Closure $handler): bool {
            $handler();

            return true;
        },
        maxLoops: 3,
    );

    expect($executions)->toBe(3)
        ->and($guardian->getRequestCount())->toBe(3)
        ->and($pdo->inTransaction())->toBeFalse()
        ->and($guardian->getLastReport())->not->toBeNull();
});

test('it breaks the worker loop when runner returns false', function () {
    $runs = 0;

    $runner = function (Closure $handler) use (&$runs): bool {
        $runs++;
        $handler();

        return $runs < 2; // stops after 2nd run
    };

    $guardian = FrankenPhp::run(
        app: function (): void {},
        requestHandlerRunner: $runner,
        maxLoops: 10,
    );

    expect($runs)->toBe(2)
        ->and($guardian->getRequestCount())->toBe(2);
});
