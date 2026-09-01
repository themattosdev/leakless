# RoadRunner Integration

[RoadRunner](https://roadrunner.dev/) is a high-performance PHP application server and process manager written in Go. It runs PHP applications in persistent worker processes, communicating via standard PSR-7 HTTP abstractions over fast binary pipes (Goridge).

Leakless integrates seamlessly with any RoadRunner worker script to ensure that memory growth, uncommitted transactions, open file handles, and polluted state are completely neutralized.

---

## The RoadRunner Worker Script

In your RoadRunner worker script (e.g. `worker.php` or `psr-worker.php`), wrap the request loop with `Leakless`:

```php
<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Leakless;

require_once __DIR__ . '/vendor/autoload.php';

// 1. Initialize Leakless with thresholds and resettables
$config = new Config(
    maxDriftMb: 64,
    consecutiveViolationsThreshold: 5,
    recycleCooldownSeconds: 10,
    checkTransactions: true,
    resettables: [
        App\Services\CartSession::class,
        fn () => LegacyStaticRegistry::$cache = [],
    ],
);

$leakless = new Leakless($config);

// 2. Initialize RoadRunner PSR-7 Worker
$psr17Factory = new Psr17Factory();
$worker = Worker::create();
$psr7 = new PSR7Worker($worker, $psr17Factory, $psr17Factory, $psr17Factory);

// 3. Persistent Request Loop
while ($request = $psr7->waitRequest()) {
    $leakless->startRequest();

    try {
        // Dispatch request to your application or PSR-15 pipeline
        $response = $app->handle($request);
        $psr7->respond($response);
    } catch (\Throwable $e) {
        $psr7->respond(new Response(500, [], 'Internal Server Error'));
    } finally {
        // 4. Audit transactions, drain buffers, execute resettables, and check Linux RSS
        $report = $leakless->endRequest();
    }

    // 5. Graceful recycling when thresholds are breached
    if ($report->shouldRecycle) {
        $psr7->getWorker()->stop();
        break;
    }
}
```

---

## How Leakless Protects RoadRunner Workers

1. **Kernel RSS Monitoring (`/proc/self/statm`)**: RoadRunner's internal supervisor monitors process memory, but Leakless monitors the physical Resident Set Size (RSS) *at the request boundary* and triggers `gc_collect_cycles()` to differentiate real memory leaks from Zend VM allocation fragmentation.
2. **Defensive Transaction Rollback**: If an exception or unhandled database query leaves an open PDO transaction, Leakless rolls it back before the next request is accepted from Go.
3. **Zero-Reflection Resettables**: All services, singletons, and static arrays in `resettables` are automatically reset to clean initial states between requests.
4. **Graceful Stop (`$worker->stop()`)**: When recycling is flagged, calling `$psr7->getWorker()->stop()` informs RoadRunner's Go supervisor to gracefully drain the worker and spawn a fresh replacement process with zero dropped client connections.
