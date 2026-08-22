# Worker Recycling Lifecycle

Persistent workers accumulate fragmented memory over time. Rather than waiting for the operating system to forcefully kill an overloaded worker with `SIGKILL` (dropping in-flight user requests), Leakless implements **Graceful Worker Recycling** with built-in protection against restart storms.

---

## Trigger Conditions

A worker is evaluated for recycling under a resilient two-tier model:

1. **Relative Memory Drift (`maxDriftMb` & `consecutiveViolationsThreshold`)** [Primary]:
   - Measures real Linux Resident Set Size (`/proc/self/statm`) relative to the worker's initial boot baseline ($M_0$).
   - When drift exceeds threshold (`maxDriftMb: 64MB`), Leakless triggers `gc_collect_cycles()` and re-evaluates physical memory.
   - If the breach persists for $N$ consecutive requests (`consecutive_violations: 5`), recycling is triggered.
   - **Cooldown Protection (`recycle_cooldown: 10s`)**: Throttles worker recycling frequency to prevent cascading restart storms under sudden traffic spikes.
   - **Jitter (`drift_jitter: 10%`)**: Randomly varies drift limits per worker to desynchronize restarts.

2. **Emergency Hard RSS Ceiling (`maxRssMb`)** [Optional / Emergency]:
   - Optional hard ceiling (e.g. `512MB`) to prevent Linux OOM Killer `SIGKILL` if a worker experiences runaway memory consumption.

3. **Max Requests Ceiling (`maxRequests`)**:
   - The total number of HTTP requests handled by the worker reaches the limit (e.g. `1000 requests`).

---

## The Recycling Sequence

1. **In-Flight Request Completion**: The active HTTP request runs to completion, generates its HTTP response, and safely flushes it back to the client.
2. **Post-Request Audit**: In `endRequest()`, Leakless completes transaction rollbacks, timezone restores, post-GC verification, and drift evaluation.
3. **Graceful Worker Signal**:
   - In **Laravel Octane**: Signals Octane's worker runner that the worker should be recycled. Octane gracefully spawns a fresh replacement worker.
   - In **Vanilla FrankenPHP**: `FrankenPhp::run()` breaks out of the loop and exits gracefully (`exit(0)`), allowing FrankenPHP's process manager to start a fresh worker.
   - In **Custom Loops**: The `Report::$shouldRecycle` boolean indicates to the loop runner to terminate.

---

## Configuration Example

```php
use TheMattos\Leakless\DTOs\Config;

$config = new Config(
    maxDriftMb: 64,                     // Allow up to 64MB drift above worker baseline
    consecutiveViolationsThreshold: 5,  // Require 5 consecutive breaches post-GC
    recycleCooldownSeconds: 10,         // Cooldown window between restarts (seconds)
    maxRequests: 1000,                  // Recycle after 1,000 handled requests
);
```
