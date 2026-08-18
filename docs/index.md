---
layout: home

hero:
  name: "Leakless"
  text: "Zero-State & Memory Leak Prevention"
  tagline: "The autonomous runtime guardian and static analysis engine for persistent PHP workers (FrankenPHP & Laravel Octane)."
  actions:
    - theme: brand
      text: Get Started →
      link: /guide/getting-started
    - theme: alt
      text: Documentation
      link: /guide/why-leakless
    - theme: alt
      text: GitHub
      link: https://github.com/themattosdev/leakless

features:
  - title: Real Kernel RSS Tracking
    details: Direct /proc/self/statm inspection on Linux to track actual Resident Set Size and catch native C-extension memory growth outside the Zend VM heap.
  - title: Automated Transaction Guard
    details: Inspects active PDO connections at request termination, detects forgotten transactions, logs diagnostic warnings, and executes safe rollbacks.
  - title: Defensive State Rollback
    details: Automatically restores default timezones, unclosed output buffers, and error reporting levels at the end of every worker cycle.
  - title: Graceful Worker Recycling
    details: Intercepts requests when configured RSS memory ceilings or request limits are reached, ensuring active requests complete cleanly.
  - title: Static Worker Linter CLI
    details: Command-line analyzer (vendor/bin/leakless analyze) with Termwind terminal UI to spot persistent worker anti-patterns in CI/CD pipelines.
  - title: Pest Custom Expectations
    details: Native test assertions including expect($service)->toBeLeakless() and expect($closure)->toRunCleanly() for strict worker guarantees.
---

<div class="vp-doc" style="max-width: 960px; margin: 3rem auto 0 auto;">

### Quick Installation

::: code-group
```bash [Composer (Runtime)]
composer require themattosdev/leakless
```
```bash [Composer (Dev Tooling)]
composer require --dev themattosdev/leakless-dev
```
:::

### Worker Protection Example

::: code-group
```ini [Laravel Octane (.env)]
# Zero-Config: Leakless auto-discovers and attaches to Octane lifecycle events
LEAKLESS_MAX_RSS_MB=256
LEAKLESS_MAX_REQUESTS=1000
LEAKLESS_CHECK_TRANSACTIONS=true
LEAKLESS_ROLLBACK_STATE=true
LEAKLESS_LOG_VIOLATIONS=true
```

```php [FrankenPHP Worker Loop]
use TheMattos\Leakless\Config;
use TheMattos\Leakless\Integrations\FrankenPhp\FrankenPhp;

$config = new Config(
    maxRssMb: 256,
    maxRequests: 1000,
    checkTransactions: true,
);

FrankenPhp::run(function () {
    // Your application request handler
    echo json_encode(['status' => 'ok', 'timestamp' => time()]);
}, $config);
```

```php [Pest Test Assertions]
use App\Services\OrderProcessor;

test('order processor executes without memory drift or transaction leaks', function () {
    expect(OrderProcessor::class)->toBeLeakless();

    expect(function () {
        (new OrderProcessor())->handleBatch();
    })->toRunCleanly(maxDriftMb: 5.0);
});
```

```bash [Static Analysis CLI]
vendor/bin/leakless analyze
```
:::

</div>
