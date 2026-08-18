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
use TheMattos\Leakless\Config;
use TheMattos\Leakless\Integrations\FrankenPhp\FrankenPhp;

$config = new Config(maxRssMb: 256, maxRequests: 1000);

FrankenPhp::run(function () {
    echo json_encode(['status' => 'ok', 'timestamp' => time()]);
}, $config);
```

### 2. Laravel Octane (Zero-Config)

Leakless automatically registers into Laravel Octane via package auto-discovery. Configure parameters in `.env`:

```env
LEAKLESS_MAX_RSS_MB=256
LEAKLESS_MAX_REQUESTS=1000
LEAKLESS_CHECK_TRANSACTIONS=true
LEAKLESS_LOG_VIOLATIONS=true
```

### 3. Pest Testing Assertions

```php
test('services are worker safe and execute cleanly', function () {
    expect(PaymentService::class)->toBeLeakless();

    expect(function () {
        (new OrderProcessor())->handle();
    })->toRunCleanly(maxDriftMb: 5.0);
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
