<?php

declare(strict_types=1);

use TheMattos\Leakless\Guards\TransactionGuard;

test('it detects and rolls back uncommitted PDO transactions', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

    $guard = new TransactionGuard;
    $guard->registerConnection($pdo);

    // Open transaction and perform an insert without committing
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO users (name) VALUES ('John')");

    expect($pdo->inTransaction())->toBeTrue();

    $result = $guard->auditAndRollback();

    expect($result['detected'])->toBeTrue()
        ->and($result['rolledBackCount'])->toBe(1)
        ->and($result['errors'])->toBeEmpty()
        ->and($pdo->inTransaction())->toBeFalse();

    // Verify row was rolled back
    $stmt = $pdo->query('SELECT COUNT(*) FROM users');
    expect($stmt !== false ? (int) $stmt->fetchColumn() : -1)->toBe(0);
});

test('it reports clean state when no transactions are open', function () {
    $pdo = new PDO('sqlite::memory:');
    $guard = new TransactionGuard;
    $guard->registerConnection($pdo);

    $result = $guard->auditAndRollback();

    expect($result['detected'])->toBeFalse()
        ->and($result['rolledBackCount'])->toBe(0)
        ->and($result['errors'])->toBeEmpty();
});

test('it audits additional on-the-fly connections', function () {
    $pdo1 = new PDO('sqlite::memory:');
    $pdo2 = new PDO('sqlite::memory:');

    $guard = new TransactionGuard;
    $guard->registerConnection($pdo1);

    $pdo1->beginTransaction();
    $pdo2->beginTransaction();

    $result = $guard->auditAndRollback([$pdo2]);

    expect($result['detected'])->toBeTrue()
        ->and($result['rolledBackCount'])->toBe(2)
        ->and($pdo1->inTransaction())->toBeFalse()
        ->and($pdo2->inTransaction())->toBeFalse();
});
