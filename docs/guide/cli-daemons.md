# Background Workers & CLI Daemons

Persistent PHP execution is not limited to HTTP web servers. Background queue consumers (e.g. RabbitMQ, Redis Queues, Amazon SQS, Apache Kafka, Gearman) and long-running CLI daemons often run for hours, days, or weeks inside Docker containers.

In queue workers, an unclosed database transaction, a growing static accumulator array, or native memory fragmentation in extensions (`ext-gd`, `ext-imagick`, `ext-curl`) will eventually trigger the Linux kernel OOM Killer (`SIGKILL`), terminating in-flight jobs and corrupting message acknowledgments.

Leakless provides the exact same request-level protection for long-running CLI jobs and queue loops.

---

## Generic CLI Queue Worker Loop

Whether you are using a custom message consumer, a Symfony Messenger worker, or a pure PHP loop:

```php
<?php

declare(strict_types=1);

use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Leakless;

require_once __DIR__ . '/vendor/autoload.php';

// 1. Configure Leakless for long-running job processing
$config = new Config(
    maxDriftMb: 64,                     // Max allowed physical RSS growth above initial job baseline
    consecutiveViolationsThreshold: 3,  // Number of dirty jobs before triggering graceful restart
    maxRequests: 5000,                  // Recycle worker after 5,000 processed jobs
    checkTransactions: true,            // Defensively rollback any uncommitted PDO transactions
    resettables: [
        App\Services\JobContext::class,
        fn () => LegacyProcessor::$batchData = [],
    ],
);

$leakless = new Leakless($config);

// 2. Consume messages in a persistent loop
while ($message = $queueConsumer->fetchNextMessage()) {
    // Treat each message/job as an isolated execution cycle
    $leakless->startRequest();

    try {
        $processor->process($message);
        $message->ack();
    } catch (\Throwable $e) {
        $logger->error('Job processing failed: ' . $e->getMessage());
        $message->nack();
    } finally {
        // 3. Rollback lingering DB transactions, restore timezone/buffers, evaluate RSS
        $report = $leakless->endRequest([
            'job_id' => $message->getId(),
            'queue' => $message->getQueueName(),
        ]);
    }

    // 4. Graceful Daemon Termination (supervisor/Docker will spawn a fresh worker)
    if ($report->shouldRecycle) {
        $logger->info("Recycling queue daemon: {$report->recycleReason}");
        exit(0);
    }
}
```

---

## Why Queue Workers Need Leakless

1. **Orphaned Transactions across Jobs**: If Job #1 throws an uncaught exception while inside a `beginTransaction()`, the PDO connection remains in transaction state. When Job #2 starts on the same worker, its queries are silently trapped inside Job #1's transaction. Leakless guarantees automatic rollback between jobs.
2. **True Kernel Memory Auditing**: Standard PHP memory functions (`memory_get_usage()`) only inspect the Zend VM engine heap. Third-party C extensions (PDF generators, image resizing, XML parsing, gRPC) allocate memory via `malloc()`. Leakless monitors the physical `/proc/self/statm` RSS memory.
3. **Zero Restart Storms**: If a high-volume queue triggers memory growth, Leakless's built-in **jitter** and **hysteresis (consecutive violations)** prevent all 50 queue worker pods from recycling at the exact same second.
