# Testing: Pest & PHPUnit Assertions

The `themattosdev/leakless-dev` package provides first-class testing utilities for both **Pest PHP** and **PHPUnit**, allowing you to enforce persistent worker safety in your automated test suite.

---

## 1. Pest Expectations

When using Pest, Leakless automatically registers custom expectations without any manual setup:

### `expect($target)->toBeLeakless()`
Performs a deep reflection audit on a class name (`string`) or object instance (`object`):
- Verifies that the class contains **zero mutable static properties** (unless annotated with `#[AllowPersistentState]`).
- Inspects constructor parameters to ensure no ephemeral request-scoped dependencies (`Illuminate\Http\Request`, `Session`) are captured in long-lived services.

```php
use App\Services\PaymentService;
use App\Repositories\OrderRepository;

test('domain services are worker safe', function () {
    expect(PaymentService::class)->toBeLeakless();
    expect(OrderRepository::class)->toBeLeakless();
});
```

### `expect($closure)->toRunCleanly(?float $maxDriftMb = null)`
Dynamically executes a closure inside a guarded Leakless request cycle:
- Audits uncommitted PDO database transactions and unclosed file descriptors.
- Verifies output buffer restoration and timezone invariance.
- Measures physical Linux kernel RSS memory drift.

```php
test('batch processing runs cleanly', function () {
    expect(function () {
        $service = new ReportGenerator();
        $service->generate();
    })->toRunCleanly(maxDriftMb: 0.25); // Asserts RAM growth <= 0.25MB (256KB)
});
### `expect($target)->toResetContainerState(callable $callback, int $maxDepth = 4)`
*Alias: `expect($target)->toHaveStatelessInstances(callable $callback, int $maxDepth = 4)`*

Deeply snapshots object instances (vanilla objects, arrays of objects, PSR-11 containers, or the Laravel `$app` container) across callback execution to assert that long-lived singletons maintain clean, immutable, or properly reset instance properties:

```php
test('services do not mutate internal state across cycles', function () {
    $globalsBag = new ViewGlobalsBag();
    $cacheService = new MetadataCache();

    expect([$globalsBag, $cacheService])->toResetContainerState(function () use ($globalsBag) {
        $globalsBag->set('csrf_token', 'temporary-token');
        // Will fail if $globalsBag is not reset at the end of the cycle!
    });
});
```

You can also pass your application container directly, with an optional recursion depth limit:

```php
test('laravel singletons maintain stateless properties', function () {
    expect(app())->toResetContainerState(function () {
        $this->postJson('/api/checkout', ['item' => 'pro']);
    }, maxDepth: 2); // Custom max depth (default: 4)
});
```

---

## 2. PHPUnit Native Assertions (`AssertsLeakless`)

If your project uses standard PHPUnit `TestCase` classes instead of Pest, use the `AssertsLeakless` trait:

```php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use TheMattos\Leakless\Dev\PHPUnit\AssertsLeakless;
use App\Services\PaymentService;

final class PaymentServiceTest extends TestCase
{
    use AssertsLeakless;

    public function test_service_is_worker_safe(): void
    {
        $this->assertIsLeakless(PaymentService::class);
    }

    public function test_request_cycle_runs_cleanly(): void
    {
        $this->assertRunsCleanly(function () {
            $service = new PaymentService();
            $service->process();
        }, maxDriftMb: 0.25);
    }

    public function test_container_maintains_clean_state(): void
    {
        $this->assertResetsContainerState($this->app, function () {
            $this->postJson('/api/users');
        }, maxDepth: 4);
    }
}
```

### Available PHPUnit Methods

| Method | Description |
| :--- | :--- |
| `$this->assertIsLeakless($target)` | Asserts a class/object contains no mutable static state or illegal injections. |
| `$this->assertRunsCleanly($callable, $config, $maxDriftMb)` | Executes a callback under Leakless and asserts clean state. |
| `$this->assertResetsContainerState($target, $callback, $msg, $maxDepth)` | Snapshots object instances or container singletons to assert clean state. |
| `$this->assertStatelessInstances($target, $callback, $msg, $maxDepth)` | Alias for `assertResetsContainerState`. |
| `$this->assertNoDanglingTransactions($reportOrResponse)` | Asserts no uncommitted PDO transactions remained. |
| `$this->assertCleanWorkerState($reportOrResponse)` | Asserts the worker state is completely clean after handling. |
| `$this->assertNoMemoryDrift($reportOrResponse, $maxMb)` | Asserts physical memory drift did not exceed threshold. |
