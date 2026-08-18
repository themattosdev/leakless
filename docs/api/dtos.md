# Attributes & Diagnostic Reports

This section covers how to interact with the diagnostic reports and attributes exposed by Leakless.

---

## 1. Inspecting the `Report` Object

At the end of every request cycle, `$leakless->endRequest()` returns a `Report` object containing diagnostic data and memory metrics.

### Practical Usage Example

```php
$report = $leakless->endRequest();

// 1. Check if the request executed cleanly
if (! $report->isClean) {
    logger()->warning('Worker state anomaly detected in request');
}

// 2. Check if open database transactions were intercepted and rolled back
if ($report->hasTransactionLeak) {
    // Send metric to Prometheus, Datadog, or Sentry
    metrics()->increment('worker.transactions.rolled_back');
}

// 3. Inspect real Linux kernel RSS memory metrics
echo "Physical memory consumed in this request: {$report->memoryDriftMb} MB\n";
echo "Current worker resident memory (RSS): {$report->metricsAfter->rssMb} MB\n";

// 4. Check if worker reached memory ceilings or request limits
if ($report->shouldRecycle) {
    // Gracefully terminate or signal process manager
    $worker->stop();
}
```

### Available Properties

| Property | Type | Description |
| :--- | :---: | :--- |
| `$report->isClean` | `bool` | `true` if no transactions leaked and no worker recycling was triggered. |
| `$report->hasTransactionLeak` | `bool` | `true` if one or more open PDO transactions were rolled back. |
| `$report->fileDescriptorsLeaked` | `bool` | `true` if lingering file handles or open sockets were detected. |
| `$report->fileDescriptorsLeakedCount` | `int` | Count of unclosed file descriptors left behind. |
| `$report->fileDescriptorsLeakedMap` | `array<int, string>` | Map of leaked descriptors `[fd => targetPath]`. |
| `$report->shouldRecycle` | `bool` | `true` if memory or request count limits were breached. |
| `$report->memoryDriftMb` | `float` | Physical RSS delta ($\Delta\text{RSS}$) in megabytes during the request. |
| `$report->metricsBefore` | `ProcessMetrics` | Snapshot of process memory before request handling. |
| `$report->metricsAfter` | `ProcessMetrics` | Snapshot of process memory after request handling. |

---

## 2. Process Memory Metrics (`ProcessMetrics`)

The `$report->metricsBefore` and `$report->metricsAfter` properties contain Linux kernel memory details:

```php
$metrics = $report->metricsAfter;

// Real physical RAM in MB (Resident Set Size)
$rssMb = $metrics->rssMb;

// Total virtual memory size in MB
$virtualMb = $metrics->sizeMb;

// Raw kernel page counts
$residentPages = $metrics->residentPages;
```

---

## 3. The `#[AllowPersistentState]` Attribute

Use this attribute to declare intentional, thread-safe static caches so they are excluded from static analysis and reflection warnings:

```php
use TheMattos\Leakless\Attributes\AllowPersistentState;

class DatabaseSchemaRegistry
{
    // Explicitly permitted: thread-safe immutable boot metadata
    #[AllowPersistentState]
    public static array $tableDefinitions = [];
}
```
