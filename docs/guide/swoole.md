# Swoole & OpenSwoole Integration

[Swoole](https://www.swoole.co.uk/) and [OpenSwoole](https://openswoole.com/) are event-driven asynchronous and coroutine-based runtimes for PHP that keep workers alive indefinitely in memory.

When using Swoole HTTP servers or task workers, Leakless serves as a defensive shield to audit uncommitted database transactions, drain residual output buffers, monitor Linux kernel RSS drift, and trigger graceful worker reloads before memory leaks cause OS `SIGKILL` termination.

---

## Swoole HTTP Server Integration

In a Swoole HTTP server script (e.g. `server.php`), initialize `Leakless` in the worker process and instrument the `request` event callback:

```php
<?php

declare(strict_types=1);

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Leakless;

// 1. Create the Swoole Server
$server = new Server('0.0.0.0', 9501);

$config = new Config(
    maxDriftMb: 64,
    consecutiveViolationsThreshold: 5,
    recycleCooldownSeconds: 10,
    checkTransactions: true,
    resettables: [
        App\Services\WorkerMetricsBuffer::class,
    ],
);

/** @var Leakless|null $leakless */
$leakless = null;

// 2. Initialize Leakless when each worker process starts (WorkerStart)
$server->on('WorkerStart', function (Server $server, int $workerId) use ($config, &$leakless) {
    $leakless = new Leakless($config);
    $leakless->captureBaselineMetrics();
});

// 3. Handle incoming HTTP requests
$server->on('request', function (Request $request, Response $response) use ($server, &$leakless) {
    $leakless?->startRequest();

    try {
        // Dispatch to your application logic
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode(['status' => 'success']));
    } finally {
        // 4. Audit transactions, drain buffers, and assess memory drift
        $report = $leakless?->endRequest();

        // 5. Gracefully restart worker when thresholds are breached
        if ($report !== null && $report->shouldRecycle) {
            $server->reload();
        }
    }
});

$server->start();
```

---

## Important Considerations for Coroutines

When running with Swoole coroutines (`enable_coroutine = true`), keep in mind:

- **Per-Request State**: Do not store request-scoped data (such as authenticated user credentials or request bodies) in global `static` properties, because multiple coroutines executing concurrently within the same worker would access the same global memory space. Use `Swoole\Coroutine::getContext()` or framework-level contextual containers.
- **Worker-Level Resources**: Leakless `resettables`, `StateRollback`, and `TransactionGuard` protect worker-level pools, global environment flags (`timezone`, `error_reporting`), and prevent physical memory exhaustion.
