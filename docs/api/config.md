# Configuration Reference

Leakless is configured via the `TheMattos\Leakless\Config` object in vanilla PHP, or via `.env` / `config/leakless.php` in Laravel Octane.

---

## 1. Vanilla PHP Configuration

Instantiate the `Config` object with your desired options:

```php
use TheMattos\Leakless\DTOs\Config;

$config = new Config(
    maxRssMb: 96.0,            // Maximum Linux kernel RSS memory threshold in MB
    maxRequests: 1000,         // Ceiling on requests before worker recycling
    checkTransactions: true,   // Auto-rollback uncommitted PDO transactions
    checkFileDescriptors: false, // Audit unclosed file handles (/proc/self/fd)
    autoRecycleOnViolation: true, // Graceful worker recycling when breached
    logViolations: true,       // Emit warnings on anomalies
);
```

---

## 2. Environment Variables (.env)

In Laravel Octane environments, configure Leakless using `.env` variables:

```ini
LEAKLESS_ENABLED=true
LEAKLESS_MAX_RSS_MB=96
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
| `max_rss_mb` | `LEAKLESS_MAX_RSS_MB` | `96.0` | Real Linux kernel RSS threshold in MB. If breached, worker recycling is triggered. |
| `max_requests` | `LEAKLESS_MAX_REQUESTS` | `null` | Maximum request count per worker before triggering graceful recycling (`null` = unlimited). |
| `check_transactions` | `LEAKLESS_CHECK_TRANSACTIONS` | `true` | Automatically audits and rolls back open PDO transactions at request completion. |
| `check_file_descriptors` | `LEAKLESS_CHECK_FILE_DESCRIPTORS` | `false` | Inspects `/proc/self/fd` for unclosed file handles and lingering network sockets. |
| `rollback_state` | `LEAKLESS_ROLLBACK_STATE` | `true` | Restores default timezone, unclosed output buffers, and error reporting levels. |
| `log_violations` | `LEAKLESS_LOG_VIOLATIONS` | `true` | Emits diagnostic logs when uncommitted transactions or state leaks are caught. |
