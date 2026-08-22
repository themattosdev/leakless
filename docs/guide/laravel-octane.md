# Laravel Octane Integration

Leakless provides first-class, zero-configuration integration with **Laravel Octane** (running with the **FrankenPHP** server driver).

> **Note:** Integration with RoadRunner and Swoole drivers is planned on the project roadmap.

---

## How It Works

When `themattosdev/leakless` is installed in a Laravel application:

1. **Auto-Discovery**: Laravel's package discovery automatically loads `TheMattos\Leakless\Integrations\Laravel\LeaklessServiceProvider`.
2. **Lifecycle Hooks**: Leakless automatically registers event listeners on Octane's lifecycle:
   - `Laravel\Octane\Events\WorkerStarting` / `OctaneStarted` ➔ Captures the clean initial memory baseline ($M_0$) post-boot.
   - `Laravel\Octane\Events\RequestReceived` ➔ Takes request snapshot of active PDO connections and Linux kernel memory state.
   - `Laravel\Octane\Events\RequestTerminated` ➔ Audits uncommitted PDO transactions, triggers automatic rollback if dangling transactions exist, restores output buffers and timezones, and evaluates relative memory drift against the baseline.
3. **Graceful Worker Recycling with Cooldown**: If memory drift persists across $N$ consecutive requests post-GC, Leakless safely stops the worker after completing the active response, respecting the recycling cooldown window to prevent restart storms.

---

## Configuration

Publish the default configuration file:

```bash
php artisan vendor:publish --tag="leakless-config"
```

This creates `config/leakless.php`:

```php
return [
    'enabled' => env('LEAKLESS_ENABLED', true),

    'max_drift_mb' => env('LEAKLESS_MAX_DRIFT_MB') !== null ? (int) env('LEAKLESS_MAX_DRIFT_MB') : 64,

    'max_rss_mb' => env('LEAKLESS_MAX_RSS_MB') ? (int) env('LEAKLESS_MAX_RSS_MB') : null,

    'consecutive_violations' => (int) env('LEAKLESS_CONSECUTIVE_VIOLATIONS', 5),

    'recycle_cooldown' => (int) env('LEAKLESS_RECYCLE_COOLDOWN', 10),

    'trigger_gc' => env('LEAKLESS_TRIGGER_GC', true),

    'drift_jitter' => (int) env('LEAKLESS_DRIFT_JITTER', 10),

    'max_requests' => env('LEAKLESS_MAX_REQUESTS') ? (int) env('LEAKLESS_MAX_REQUESTS') : null,

    'check_transactions' => env('LEAKLESS_CHECK_TRANSACTIONS', true),

    'check_file_descriptors' => env('LEAKLESS_CHECK_FILE_DESCRIPTORS', false),

    'auto_recycle' => env('LEAKLESS_AUTO_RECYCLE', true),

    'log_violations' => env('LEAKLESS_LOG_VIOLATIONS', true),
];
```

### Environment Variables

| Variable | Type | Default | Description |
| :--- | :---: | :---: | :--- |
| `LEAKLESS_ENABLED` | `bool` | `true` | Enable or disable Leakless auditing. |
| `LEAKLESS_MAX_DRIFT_MB` | `int\|null` | `64` | Allowable relative RSS drift (MB) above worker baseline. |
| `LEAKLESS_MAX_RSS_MB` | `int\|null` | `null` | Hard emergency physical RSS ceiling in MB (optional). |
| `LEAKLESS_CONSECUTIVE_VIOLATIONS` | `int` | `5` | Consecutive post-GC breaches required before recycling. |
| `LEAKLESS_RECYCLE_COOLDOWN` | `int` | `10` | Minimum interval in seconds between worker recycles. |
| `LEAKLESS_TRIGGER_GC` | `bool` | `true` | Trigger `gc_collect_cycles()` on suspected drift breach. |
| `LEAKLESS_DRIFT_JITTER` | `int` | `10` | Jitter percentage to desynchronize worker restarts. |
| `LEAKLESS_MAX_REQUESTS` | `int\|null` | `null` | Maximum request count per worker before recycling. |
| `LEAKLESS_CHECK_TRANSACTIONS` | `bool` | `true` | Detect and automatically roll back open PDO transactions. |
| `LEAKLESS_CHECK_FILE_DESCRIPTORS` | `bool` | `false` | Inspect `/proc/self/fd` for lingering file handles and sockets. |
| `LEAKLESS_AUTO_RECYCLE` | `bool` | `true` | Automatically signal Octane worker stop on confirmed breach. |
| `LEAKLESS_LOG_VIOLATIONS` | `bool` | `true` | Log diagnostic warnings when leaks or anomalies occur. |

---

## Laravel HTTP Testing Macros

When `themattosdev/leakless-dev` is installed, Leakless injects custom assertion macros into Laravel's `TestResponse`:

```php
test('checkout endpoint leaves clean worker state', function () {
    $response = $this->postJson('/api/checkout', [
        'cart_id' => 1001,
    ]);

    $response->assertOk()
        ->assertNoDanglingTransactions()
        ->assertNoMemoryDrift(maxAllowedMb: 0.25)
        ->assertCleanWorkerState();
});
```
