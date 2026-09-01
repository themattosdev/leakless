<p align="center">
  <img src="https://raw.githubusercontent.com/themattosdev/leakless/master/art/banner.png" alt="Leakless Banner" width="100%" style="max-width: 800px; border-radius: 8px;">
</p>

<p align="center">
  <strong>Zero-State & Memory Leak Prevention for PHP Persistent Workers (FrankenPHP & Laravel Octane)</strong>
</p>

<p align="center">
  <a href="https://github.com/themattosdev/leakless/releases"><img src="https://img.shields.io/github/v/release/themattosdev/leakless?color=blue&label=version" alt="Latest Version"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/php-%5E8.2-777bb4.svg" alt="PHP Version"></a>
  <a href="https://github.com/themattosdev/leakless/actions"><img src="https://img.shields.io/badge/tests-passing-brightgreen.svg" alt="Tests Passing"></a>
  <a href="https://github.com/themattosdev/leakless/blob/master/LICENSE"><img src="https://img.shields.io/badge/license-MIT-green.svg" alt="License"></a>
</p>

---

## Overview

In traditional PHP-FPM, worker processes terminate after each request, allowing the OS to wipe memory and state. Persistent runtimes like **FrankenPHP Worker Mode** and **Laravel Octane** keep PHP in memory across thousands of requests. While significantly faster, persistent workers can suffer from unmonitored C-extension memory growth, dangling database transactions, and polluted global state.

**Leakless** is an autonomous guardian for persistent PHP: it reads real Linux kernel RSS from `/proc/self/statm`, rolls back uncommitted PDO transactions, cleans runtime state in `finally` blocks, and provides static analysis via PHPStan and Pest assertions.

---

## Installation

```bash
# Runtime engine (production)
composer require themattosdev/leakless

# Developer tooling, CLI analyzer, and Pest assertions
composer require --dev themattosdev/leakless-dev
```

---

## Usage

### 1. Vanilla PHP / FrankenPHP Loop

```php
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Integrations\FrankenPhp\FrankenPhp;

FrankenPHP::run(
    app: function () {
        echo json_encode(['status' => 'ok']);
    },
    config: new Config(
        maxDriftMb: 64,
        maxRequests: 1000,
        resettables: [
            App\Services\CartSession::class,
            fn () => LegacyRegistry::$cache = [],
        ],
    ),
);
```

### 2. Laravel Octane (Zero-Config)

```env
LEAKLESS_ENABLED=true
LEAKLESS_MAX_DRIFT_MB=64
LEAKLESS_CHECK_TRANSACTIONS=true
LEAKLESS_CHECK_FILE_DESCRIPTORS=false
```

In `config/leakless.php`, you can also register classes or callbacks to auto-reset:

```php
'resettables' => [
    App\Services\CartSession::class,
    fn () => LegacyRegistry::$cache = [],
],
```

> **Note regarding Laravel Octane:** Octane provides native `scoped()` bindings and a `'flush'` list in `config/octane.php`. Leakless provides its compiled zero-reflection `resettables` engine and `#[ResetOnRequest]` attribute. **It is at your own discretion which mechanism to use** — you can rely on Octane's native mechanisms, use Leakless's resettables, or combine both seamlessly.

### 3. Declarative State Reset (`#[ResetOnRequest]`)

Annotate properties or classes to automatically restore initial or default values between requests:

```php
use TheMattos\Leakless\Attributes\ResetOnRequest;

class UserContext
{
    #[ResetOnRequest(default: [])]
    public array $permissions = [];

    #[ResetOnRequest]
    public static ?string $token = null;
}
```

### 4. Automated Testing (Pest & PHPUnit)

```php
test('service executes cleanly without leaking memory or state', function () {
    // 1. Structural design check (no mutable static props or illegal constructor injections)
    expect(PaymentService::class)->toBeLeakless();

    // 2. Full request lifecycle check (PDO transactions, FDs, and Linux RSS drift)
    expect(function () {
        (new PaymentService())->processPendingTransactions();
    })->toRunCleanly(maxDriftMb: 0.25);

    // 3. Deep container/instance property snapshotting (detects runtime state mutations)
    expect(app())->toResetContainerState(function () {
        $this->postJson('/api/checkout', ['item' => 'pro']);
    });
});
```

### 4. Static Worker Linter CLI

```bash
vendor/bin/leakless analyze
```

---

## Documentation

Full documentation, architecture guides, kernel memory details, and anti-pattern catalogues are available at:

👉 **[https://leakless.themattos.dev](https://leakless.themattos.dev)**

---

## Testing & Quality

```bash
# Run test suite
docker compose run --rm app vendor/bin/pest

# Code style
docker compose run --rm app vendor/bin/pint --test

# Static analysis (Level 9)
docker compose run --rm app composer analyse
```

---

## License

Open-source software licensed under the [MIT License](LICENSE).  
Developed by [Jonathan Gonçalves](https://github.com/jmgoncalves97).
