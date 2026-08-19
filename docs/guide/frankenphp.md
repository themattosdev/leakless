# Vanilla PHP & FrankenPHP Worker Mode

When running custom, framework-agnostic, or microservice applications with **FrankenPHP Worker Mode**, Leakless provides a wrapper helper: `TheMattos\Leakless\Integrations\FrankenPhp\FrankenPhp`.

---

## The Worker Script

Create your worker entry point (e.g. `worker.php`):

```php
<?php

declare(strict_types=1);

use TheMattos\Leakless\Config;
use TheMattos\Leakless\Integrations\FrankenPhp\FrankenPhp;

require_once __DIR__ . '/vendor/autoload.php';

// 1. Configure thresholds and safety policies
$config = new Config(
    maxRssMb: 96.0,
    maxRequests: 1000,
    checkTransactions: true,
    logViolations: true,
);

FrankenPhp::run(function () {
    // Application handler
    echo json_encode(['status' => 'ok']);
}, $config);
```

---

## How `FrankenPhp::run()` Works

Under the hood, `FrankenPhp::run()` orchestrates the persistent execution lifecycle:

1. **Worker Bootstrapping**: Initializes the `Leakless` instance with your `Config`.
2. **FrankenPHP Native Polling**: Uses `frankenphp_handle_request()` to wait for incoming HTTP requests.
3. **Automatic Lifecycle Wrapping**:
   - Calls `$leakless->startRequest()` before your handler executes.
   - Executes your application handler inside a protected `try / finally` boundary.
   - Calls `$leakless->endRequest()` in the `finally` block to guarantee transaction auditing, state rollback, and RSS ceiling validation.
4. **Graceful Worker Break**: If the memory ceiling or max request limit is reached, `FrankenPhp::run()` cleanly exits the loop, allowing the FrankenPHP process manager to spawn a new clean worker.

---

## Custom Event Loops & Manual Usage

If you are writing a custom event loop or micro-framework, you can invoke the core `Leakless` lifecycle methods directly:

```php
use TheMattos\Leakless\Leakless;
use TheMattos\Leakless\Config;

$leakless = new Leakless(new Config(maxRssMb: 256.0));

while ($request = $server->accept()) {
    $leakless->startRequest();

    try {
        $response = $app->handle($request);
        $server->send($response);
    } finally {
        $report = $leakless->endRequest();

        if ($report->shouldRecycle) {
            // Gracefully terminate loop
            break;
        }
    }
}
```
