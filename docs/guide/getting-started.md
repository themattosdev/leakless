# Getting Started

Leakless is distributed as two complementary packages:

1. **`themattosdev/leakless` (Runtime Package)**: The lightweight, zero-dependency production engine for persistent worker monitoring, transaction guards, and state rollback.
2. **`themattosdev/leakless-dev` (Development Package)**: Developer tooling containing the static linter CLI (`vendor/bin/leakless analyze`), Pest custom expectations, Laravel HTTP testing macros, and PHPStan AST rules.

---

## Installation

Install the runtime package in your production dependencies:

```bash
composer require themattosdev/leakless
```

Install developer tooling in your development dependencies:

```bash
composer require --dev themattosdev/leakless-dev
```

---

## System Requirements

- **PHP Version**: `^8.2` or higher
- **Extensions**: `ext-posix` and `ext-pcntl` (standard in Linux/Docker FrankenPHP images)
- **Supported Runtimes**:
  - FrankenPHP Worker Mode (`frankenphp_handle_request`)
  - Laravel Octane (via FrankenPHP driver; RoadRunner & Swoole drivers planned in roadmap)
  - Custom persistent PHP event loops

---

## Quick Setup by Environment

### Laravel Octane

If you are using Laravel Octane, Leakless requires **zero code changes**:

1. Install the package via Composer.
2. Leakless automatically registers its `LeaklessServiceProvider` and hooks into Octane's `RequestReceived` and `RequestTerminated` events.
3. Configure thresholds in your `.env`:

```ini
LEAKLESS_ENABLED=true
LEAKLESS_MAX_RSS_MB=256
LEAKLESS_MAX_REQUESTS=1000
LEAKLESS_CHECK_TRANSACTIONS=true
LEAKLESS_LOG_VIOLATIONS=true
```

Read the full [Laravel Octane Guide](./laravel-octane.md) for advanced customization.

---

### Vanilla PHP & FrankenPHP Worker Mode

For custom persistent worker scripts, wrap your request handler in `FrankenPhp::run()`:

```php
<?php

declare(strict_types=1);

use TheMattos\Leakless\Config;
use TheMattos\Leakless\Integrations\FrankenPhp\FrankenPhp;

require_once __DIR__ . '/vendor/autoload.php';

$config = new Config(
    maxRssMb: 256,
    maxRequests: 1000,
    checkTransactions: true,
    logViolations: true,
);

FrankenPhp::run(function () {
    // Handle incoming HTTP request
    echo json_encode(['status' => 'success', 'time' => microtime(true)]);
}, $config);
```

Read the full [Vanilla FrankenPHP Guide](./frankenphp.md) for custom loop integration.

---

## Next Steps

- Learn about [Migrating from PHP-FPM](./migrating-from-fpm.md) without downtime.
- Understand how [Real Kernel RSS Tracking](./kernel-memory.md) works under Linux.
- Explore the [PHPStan AST Rules](../tooling/phpstan.md) and static checks.
- Run static analysis with the [Leakless CLI](../tooling/cli.md).
