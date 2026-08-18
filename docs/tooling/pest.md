# Pest Custom Expectations

The `themattosdev/leakless-dev` package automatically registers custom Pest expectations to validate persistent worker safety in your automated test suite.

---

## 1. `expect($target)->toBeLeakless()`

Performs a deep structural reflection audit on a class name (`string`) or instantiated object (`object`):

- Verifies that the class and all its parent classes contain **zero mutable static properties** (unless annotated with `#[AllowPersistentState]`).
- Inspects constructor parameters to ensure no ephemeral request-scoped dependencies (`Illuminate\Http\Request`, `Illuminate\Session\SessionManager`) are captured in long-lived services.

```php
use App\Services\PaymentService;
use App\Repositories\OrderRepository;

test('core domain services are worker safe', function () {
    expect(PaymentService::class)->toBeLeakless();
    expect(OrderRepository::class)->toBeLeakless();
});
```

---

## 2. `expect($closure)->toRunCleanly(?float $maxDriftMb = null)`

Dynamically executes a closure inside a guarded Leakless request cycle:

- Checks for uncommitted PDO database transactions before and after closure execution.
- Verifies output buffer restoration and timezone invariance.
- Measures physical Linux kernel RSS memory before and after execution.
- If `$maxDriftMb` is provided, asserts that physical memory drift does not exceed the allowed threshold.

```php
use App\Jobs\ProcessPendingInvoices;

test('batch invoice processing executes cleanly without memory drift', function () {
    expect(function () {
        $job = new ProcessPendingInvoices();
        $job->handle();
    })->toRunCleanly(maxDriftMb: 5.0); // Asserts RAM growth <= 5MB
});
```

---

## PHPStan Strict Mode Integration

If you use strict PHPStan Level 9 in your test suite, include this ignore rule in your `phpstan.neon` to satisfy custom macro reflection:

```yaml
parameters:
    ignoreErrors:
        - '#Call to an undefined method Pest\\Expectation.*::(toBeLeakless|toRunCleanly)\(\)#'
```
