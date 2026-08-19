# Laravel HTTP Test Macros

The `themattosdev/leakless-dev` package automatically injects testing assertion macros into Laravel's `Illuminate\Testing\TestResponse`.

---

## Available Test Macros

### 1. `assertNoDanglingTransactions()`

Asserts that the HTTP request did not leave any uncommitted or open database transactions across active database connections:

```php
test('order checkout leaves no uncommitted database transactions', function () {
    $response = $this->postJson('/api/orders', [
        'product_id' => 42,
        'quantity' => 1,
    ]);

    $response->assertCreated()
        ->assertNoDanglingTransactions();
});
```

---

### 2. `assertNoMemoryDrift(?float $maxAllowedMb = 0.25)`

Asserts that the physical Linux kernel RSS memory variation during the HTTP request remained within the acceptable megabyte limit:

```php
test('api endpoint does not cause excessive memory drift', function () {
    $response = $this->get('/api/users');

    $response->assertOk()
        ->assertNoMemoryDrift(maxAllowedMb: 0.25);
});
```

---

### 3. `assertCleanWorkerState()`

Combines both assertions: verifies that no database transactions leaked and that no dirty state was recorded:

```php
test('api endpoint maintains 100% clean worker state', function () {
    $response = $this->postJson('/api/process-batch');

    $response->assertOk()
        ->assertCleanWorkerState();
});
```
