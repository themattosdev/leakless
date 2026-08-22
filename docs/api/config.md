# Configuration Reference

Leakless is configured via the `TheMattos\Leakless\Config` object in vanilla PHP, or via `.env` / `config/leakless.php` in Laravel Octane.

---

## 1. Vanilla PHP Configuration

Instantiate the `Config` object with your desired options:

```php
use TheMattos\Leakless\DTOs\Config;

$config = new Config(
    maxDriftMb: 64,                     // Allowable memory drift above worker baseline (MB)
    maxRssMb: null,                     // Hard emergency ceiling (MB) for OOM prevention (optional)
    consecutiveViolationsThreshold: 5,  // Consecutive requests required before recycling
    recycleCooldownSeconds: 10,         // Minimum seconds between worker restarts (prevents restart storms)
    triggerGcOnBreach: true,            // Re-evaluate physical memory after gc_collect_cycles()
    driftJitterPercentage: 10,          // Random variation to desynchronize worker recycles
    maxRequests: 1000,                  // Ceiling on requests before worker recycling
    checkTransactions: true,            // Auto-rollback uncommitted PDO transactions
    checkFileDescriptors: false,        // Audit unclosed file handles (/proc/self/fd)
    autoRecycleOnViolation: true,       // Graceful worker recycling when breached
    logViolations: true,                // Emit warnings on anomalies
);
```

---

## 2. Environment Variables (.env)

In Laravel Octane environments, configure Leakless using `.env` variables:

```ini
LEAKLESS_ENABLED=true
LEAKLESS_MAX_DRIFT_MB=64
LEAKLESS_MAX_RSS_MB=null
LEAKLESS_CONSECUTIVE_VIOLATIONS=5
LEAKLESS_RECYCLE_COOLDOWN=10
LEAKLESS_TRIGGER_GC=true
LEAKLESS_DRIFT_JITTER=10
LEAKLESS_MAX_REQUESTS=1000
LEAKLESS_CHECK_TRANSACTIONS=true
LEAKLESS_CHECK_FILE_DESCRIPTORS=false
LEAKLESS_AUTO_RECYCLE=true
LEAKLESS_LOG_VIOLATIONS=true
```

Or publish `config/leakless.php`:

```bash
php artisan vendor:publish --tag="leakless-config"
```

---

## 3. Configuration Reference Table

| Key | Environment Variable | Default | Description |
| :--- | :--- | :---: | :--- |
| `enabled` | `LEAKLESS_ENABLED` | `true` | Master toggle to enable or disable Leakless audits. |
| `max_drift_mb` | `LEAKLESS_MAX_DRIFT_MB` | `64` | Allowable relative RSS growth (in MB) above worker baseline before recycling evaluation. |
| `max_rss_mb` | `LEAKLESS_MAX_RSS_MB` | `null` | Hard emergency physical RSS ceiling (MB) to prevent Linux OOM Killer SIGKILL. |
| `consecutive_violations` | `LEAKLESS_CONSECUTIVE_VIOLATIONS` | `5` | Hysteresis: consecutive post-GC breaches required before recycling worker. |
| `recycle_cooldown` | `LEAKLESS_RECYCLE_COOLDOWN` | `10` | Cooldown window in seconds between worker recycles (prevents restart storms). |
| `trigger_gc` | `LEAKLESS_TRIGGER_GC` | `true` | Invokes `gc_collect_cycles()` and re-reads `/proc/self/statm` on suspected breach. |
| `drift_jitter` | `LEAKLESS_DRIFT_JITTER` | `10` | Percentage variation applied to `max_drift_mb` to desynchronize restarts across workers. |
| `max_requests` | `LEAKLESS_MAX_REQUESTS` | `null` | Maximum request count per worker before triggering graceful recycling (`null` = unlimited). |
| `check_transactions` | `LEAKLESS_CHECK_TRANSACTIONS` | `true` | Automatically audits and rolls back open PDO transactions at request completion. |
| `check_file_descriptors` | `LEAKLESS_CHECK_FILE_DESCRIPTORS` | `false` | Inspects `/proc/self/fd` for unclosed file handles and lingering network sockets. |
| `auto_recycle` | `LEAKLESS_AUTO_RECYCLE` | `true` | Automatically stops the worker when thresholds or persistent corruption are confirmed. |
| `log_violations` | `LEAKLESS_LOG_VIOLATIONS` | `true` | Emits diagnostic logs when uncommitted transactions or state leaks are caught. |

