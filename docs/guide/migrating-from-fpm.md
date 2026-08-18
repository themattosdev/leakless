# Migrating from PHP-FPM to Persistent Workers

Migrating a production codebase from traditional PHP-FPM to persistent workers (such as **FrankenPHP Worker Mode** or **Laravel Octane**) can be intimidating. In PHP-FPM, applications relied on the operating system to clean up memory, database locks, and global variables after every request.

**Leakless serves as your migration safety net**, allowing teams to transition smoothly in three structured phases.

---

## Phase 1: Static Pre-Migration Audit

Before enabling worker mode in staging or production, run the Leakless static analyzer across your entire codebase:

```bash
vendor/bin/leakless analyze
```

The analyzer inspects your AST to identify code patterns that are dangerous in persistent workers:

1. **Process Terminators**: `exit()` or `die()` calls that would kill the entire worker process and drop concurrent connections.
2. **Native Sessions**: `session_start()` and `session_id()` calls that corrupt persistent concurrency.
3. **Direct Header Output**: `header()` and `setcookie()` calls that bypass framework response objects and bleed headers into subsequent user requests.
4. **Mutable Static Properties**: Static variables retaining cache or user data across requests without the `#[AllowPersistentState]` attribute.
5. **Singleton Injections**: Request-scoped services (like `Request` or `Session`) injected into long-lived singleton constructors.

Fix all flagged issues or wrap intentional static caches with `#[AllowPersistentState]`.

---

## Phase 2: Runtime Safety Net

When switching to persistent worker mode in staging or production, install `themattosdev/leakless`:

```bash
composer require themattosdev/leakless
```

Leakless provides an autonomous runtime guard:

- **Uncommitted Transactions**: If a legacy controller or third-party package fails to commit a database transaction, Leakless automatically detects the open PDO transaction, logs the violation, and executes a rollback before the next user request is handled.
- **Unclosed Output Buffers**: If legacy code calls `ob_start()` without `ob_end_clean()`, Leakless drains the buffer to prevent HTML leakage.
- **Native Memory Limits**: If legacy image processing or external API calls leak memory via native C libraries, Leakless monitors real Linux kernel RSS and gracefully recycles the worker after the active request finishes.

Configure your `.env` to log all violations for developer triage:

```ini
LEAKLESS_ENABLED=true
LEAKLESS_LOG_VIOLATIONS=true
LEAKLESS_MAX_RSS_MB=256
LEAKLESS_MAX_REQUESTS=1000
```

---

## Phase 3: Test Suite Fortification

Add persistent worker assertions to your existing test suite using Pest:

```php
use App\Services\LegacyInvoiceService;

test('legacy invoice service contains no mutable static state', function () {
    expect(LegacyInvoiceService::class)->toBeLeakless();
});

test('critical checkout workflow runs with clean worker state', function () {
    expect(function () {
        $service = app(CheckoutWorkflow::class);
        $service->processPendingOrders();
    })->toRunCleanly(maxDriftMb: 2.0);
});
```

And in Laravel feature tests:

```php
test('api checkout endpoint maintains state integrity', function () {
    $response = $this->postJson('/api/v1/orders', [
        'items' => [1, 2, 3],
    ]);

    $response->assertOk()
        ->assertNoDanglingTransactions()
        ->assertNoMemoryDrift(maxAllowedMb: 2.0)
        ->assertCleanWorkerState();
});
```
