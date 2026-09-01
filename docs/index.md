---
layout: home

hero:
  name: "Leakless"
  text: "Zero-State & Memory Leak Prevention"
  tagline: "The autonomous runtime guardian and static analysis engine for persistent PHP workers (FrankenPHP, RoadRunner, Swoole, Symfony, Laravel & Vanilla)."
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
  - title: Defensive State Rollback & Resettables
    details: Automatically restores timezones, unclosed output buffers, and resets registered services or #[ResetOnRequest] properties with zero reflection in hot path.
  - title: Graceful Worker Recycling
    details: Intercepts requests when configured RSS memory ceilings or request limits are reached, ensuring active requests complete cleanly.
  - title: Static Worker Linter CLI
    details: Command-line analyzer (vendor/bin/leakless analyze) with Termwind terminal UI to spot persistent worker anti-patterns in CI/CD pipelines.
  - title: Pest & PHPUnit Assertions
    details: Native test assertions including expect($service)->toBeLeakless(), expect($closure)->toRunCleanly(), and expect(app())->toResetContainerState().
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
LEAKLESS_ENABLED=true
LEAKLESS_MAX_DRIFT_MB=64
LEAKLESS_CHECK_TRANSACTIONS=true
LEAKLESS_CHECK_FILE_DESCRIPTORS=false
```

```php [Vanilla FrankenPHP]
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Integrations\FrankenPhp\FrankenPhp;

FrankenPhp::run(
    app: fn () => print(json_encode(['status' => 'ok'])),
    config: new Config(
        maxDriftMb: 64,
        checkTransactions: true,
        resettables: [
            App\Services\CartSession::class,
            fn () => LegacyStatic::$cache = [],
        ],
    ),
);
```

```php [Pest PHP Assertions]
test('orders endpoint executes cleanly without state or memory drift', function () {
    // 1. Structural reflection check
    expect(PaymentGateway::class)->toBeLeakless();

    // 2. Request lifecycle & Kernel RSS drift check
    expect(function () {
        (new ProcessInvoicesJob())->handle();
    })->toRunCleanly(maxDriftMb: 0.25);

    // 3. Container singletons state mutation snapshot
    expect(app())->toResetContainerState(function () {
        $this->postJson('/api/checkout', ['plan' => 'pro']);
    });
});
```

```bash [Static Analysis CLI]
vendor/bin/leakless analyze
```
:::

</div>
