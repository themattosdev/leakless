# Laravel Octane Integration

Leakless provides first-class, zero-configuration integration with **Laravel Octane** (running with the **FrankenPHP** server driver).

> **Note:** Integration with RoadRunner and Swoole drivers is planned on the project roadmap.

---

## How It Works

When `themattosdev/leakless` is installed in a Laravel application:

1. **Auto-Discovery**: Laravel's package discovery automatically loads `TheMattos\Leakless\Integrations\Laravel\LeaklessServiceProvider`.
2. **Lifecycle Hooks**: Leakless automatically registers event listeners on Octane's lifecycle:
   - `Laravel\Octane\Events\RequestReceived` ➔ Takes initial snapshot of active PDO connections and Linux kernel memory state.
   - `Laravel\Octane\Events\RequestTerminated` ➔ Audits uncommitted PDO transactions, triggers automatic rollback if dangling transactions exist, restores output buffers and timezones, and evaluates RSS memory limits against the configured threshold.
3. **Graceful Worker Recycling**: If a worker breaches the `LEAKLESS_MAX_RSS_MB` threshold, Leakless safely stops the worker after completing the active response, signaling Octane to spawn a fresh worker.

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

    'max_rss_mb' => env('LEAKLESS_MAX_RSS_MB', 96),

    'max_requests' => env('LEAKLESS_MAX_REQUESTS', null),

    'check_transactions' => env('LEAKLESS_CHECK_TRANSACTIONS', true),

    'rollback_state' => env('LEAKLESS_ROLLBACK_STATE', true),

    'log_violations' => env('LEAKLESS_LOG_VIOLATIONS', true),
];
```

### Environment Variables

| Variable | Type | Default | Description |
| :--- | :---: | :---: | :--- |
| `LEAKLESS_ENABLED` | `bool` | `true` | Enable or disable Leakless auditing. |
| `LEAKLESS_MAX_RSS_MB` | `int\|float` | `96` | Real Linux kernel RSS threshold in MB before recycling. |
| `LEAKLESS_MAX_REQUESTS` | `int\|null` | `null` | Maximum request count per worker before recycling. |
| `LEAKLESS_CHECK_TRANSACTIONS` | `bool` | `true` | Detect and automatically roll back open PDO transactions. |
| `LEAKLESS_ROLLBACK_STATE` | `bool` | `true` | Revert timezones, output buffers, and error levels. |
| `LEAKLESS_LOG_VIOLATIONS` | `bool` | `true` | Log diagnostic warnings when leaks occur. |

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
