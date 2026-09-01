# How to Detect & Fix Memory Leaks in Persistent PHP Workers

When running PHP in long-lived persistent runtimes (**FrankenPHP**, **Laravel Octane**, **RoadRunner**, **Swoole**, or **CLI background queue workers**), memory leaks are the #1 cause of production outages and random container crashes.

This guide explains why persistent PHP workers leak memory, why standard PHP functions fail to detect it, and how to permanently eliminate memory drift.

---

## Symptoms of Memory Leaks in PHP Workers

| Symptom | What It Means | Root Cause |
| :--- | :--- | :--- |
| **Random Docker `exit 137` (SIGKILL)** | The Linux kernel OOM Killer forcibly terminated the worker. | Process Resident Set Size (RSS) exceeded container memory limits. |
| **Worker restarts during traffic bursts** | All pods hit memory ceilings at the same time ("restart storms"). | Lack of recycling cooldown windows or memory jitter. |
| **`memory_get_usage()` is low, but Docker RAM is 95%** | PHP internal memory is clean, but operating system RAM is full. | Native C-extension memory growth (outside the Zend VM heap). |
| **Queue workers slowing down after processing 1,000 jobs** | Garbage collector overhead increases as memory fragments. | Cyclic references and unbounded static arrays. |

---

## Why `memory_get_usage()` Lies in Persistent Workers

Most PHP developers use `memory_get_usage()` to monitor application memory:

```php
// WARNING: This only measures Zend Engine VM heap memory!
$memoryMb = memory_get_usage(true) / 1024 / 1024;
```

### The Problem: Zend Heap vs. Real Kernel RSS
1. **Zend Engine Allocator (`emalloc`)**: `memory_get_usage()` only tracks memory allocated through PHP's internal memory manager (ZMM).
2. **Native C-Extension Allocations (`malloc`)**: Popular PHP extensions like `ext-imagick`, `ext-gd`, `ext-curl`, `ext-pdo`, `ext-xml`, `ext-grpc`, and `ext-protobuf` allocate memory directly via the OS C-library allocator (`malloc()`).
3. **Memory Fragmentation**: In long-running workers, glibc memory fragmentation occurs where memory pages cannot be returned to the OS kernel even after PHP variables are unset.

**Result:** `memory_get_usage()` reports a steady 30 MB, while the Linux kernel measures the process at 512 MB and terminates it with `SIGKILL`.

---

## The Solution: Real Kernel RSS Tracking via `/proc/self/statm`

On Linux systems, the only source of truth for process physical memory is the kernel's `/proc` virtual filesystem.

Leakless reads `/proc/self/statm` directly with microsecond overhead:

```php
use TheMattos\Leakless\Support\ProcStatmParser;

$parser = new ProcStatmParser();
$metrics = $parser->parse();

echo "Real Kernel Physical RAM: {$metrics->rssMb} MB";
```

---

## 3-Step Strategy to Fix Worker Memory Leaks

### Step 1: Filter Transient Spikes with Post-GC Verification
Never recycle a worker on a single request spike. When memory exceeds your threshold, Leakless invokes `gc_collect_cycles()` and re-evaluates physical RAM before confirming a violation:

```php
$config = new Config(
    maxDriftMb: 64,          // Allow up to 64 MB growth above worker boot baseline
    triggerGcOnBreach: true, // Collect cyclic garbage before evaluating
);
```

### Step 2: Use Hysteresis (Consecutive Violations)
A worker should only be recycled if it remains persistently above threshold across $N$ consecutive requests:

```php
$config = new Config(
    consecutiveViolationsThreshold: 5, // Must breach 5 times in a row
);
```

### Step 3: Prevent Restart Storms with Jitter & Cooldown
When all workers receive identical traffic, they tend to reach memory limits simultaneously. Leakless introduces:
- **`driftJitterPercentage: 10`**: Applies pseudo-random variation to each worker's threshold, desynchronizing restarts.
- **`recycleCooldownSeconds: 10`**: Enforces a minimum interval between recycling events.

---

## Automated Memory Protection with Leakless

```php
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Leakless;

$guardian = new Leakless(new Config(
    maxDriftMb: 64,
    consecutiveViolationsThreshold: 5,
    recycleCooldownSeconds: 10,
    triggerGcOnBreach: true,
    driftJitterPercentage: 10,
));

$guardian->startRequest();

try {
    $response = $app->handle($request);
} finally {
    $report = $guardian->endRequest();

    if ($report->shouldRecycle) {
        // Gracefully recycle worker after finishing current HTTP response
        $server->recycle();
    }
}
```
