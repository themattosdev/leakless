# Worker Recycling Lifecycle

Persistent workers accumulate fragmented memory over time. Rather than waiting for the operating system to forcefully kill an overloaded worker with `SIGKILL` (dropping in-flight user requests), Leakless implements **Graceful Worker Recycling**.

---

## Trigger Conditions

A worker is marked for recycling when either of the following thresholds is breached:

1. **Max RSS Memory Threshold (`maxRssMb`)**:
   - The real Linux Resident Set Size measured at the end of the request exceeds the configured ceiling (e.g. `256.0 MB`).
2. **Max Requests Ceiling (`maxRequests`)**:
   - The total number of HTTP requests handled by the worker reaches the limit (e.g. `1000 requests`).

---

## The Recycling Sequence

1. **In-Flight Request Completion**: The active HTTP request runs to completion, generates its HTTP response, and safely flushes it back to the client.
2. **Post-Request Audit**: In `endRequest()`, Leakless completes transaction rollbacks, timezone restores, and RSS evaluation.
3. **Graceful Worker Signal**:
   - In **Laravel Octane**: Signals Octane's worker runner that the worker should be recycled. Octane gracefully spawns a fresh replacement worker.
   - In **Vanilla FrankenPHP**: `FrankenPhp::run()` breaks out of the loop and exits gracefully (`exit(0)`), allowing FrankenPHP's process manager to start a fresh worker.
   - In **Custom Loops**: The `Report::$shouldRecycle` boolean indicates to the loop runner to terminate.

---

## Configuration Example

```php
use TheMattos\Leakless\Config;

$config = new Config(
    maxRssMb: 512.0,    // Recycle if worker RSS exceeds 512MB
    maxRequests: 5000,  // Recycle after 5,000 handled requests
);
```
