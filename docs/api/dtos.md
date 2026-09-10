# Attributes & Diagnostic Reports

This section covers how to interact with the diagnostic reports and attributes exposed by Leakless.

---

## 1. Inspecting the `Report` Object

At the end of every request cycle, `$leakless->endRequest()` returns a `Report` object containing diagnostic data and memory metrics.

### Practical Usage Example

```php
$report = $leakless->endRequest();

// 1. Check if the request executed cleanly
if (! $report->isClean()) {
    logger()->warning('Worker state anomaly detected in request');
}

// 2. Check if open database transactions were intercepted and rolled back
if ($report->danglingTransactionsDetected) {
    // Send metric to Prometheus, Datadog, or Sentry
    metrics()->increment('worker.transactions.rolled_back');
}

// 3. Inspect real Linux kernel RSS memory metrics
echo "Physical memory consumed in this request: {$report->memoryDriftMb} MB\n";
echo "Current worker resident memory (RSS): {$report->finalMetrics->rssMb} MB\n";

// 4. In ZTS mode, inspect thread attribution
if ($report->isZts) {
    if ($report->unattributedProcessDrift) {
        logger()->warning('Process RSS drifted without current thread Zend MM growth (noisy neighbor or native leak)');
    }
}

// 5. Check if worker reached memory ceilings or request limits
if ($report->shouldRecycle) {
    // Gracefully terminate or signal process manager
    $worker->stop();
}
```

### Available Properties & Methods

| Property / Method | Type | Description |
| :--- | :---: | :--- |
| `$report->isClean()` | `bool` | Method: returns `true` if no transactions leaked, no FDs leaked, and no recycling was triggered. |
| `$report->danglingTransactionsDetected` | `bool` | `true` if one or more open PDO transactions were rolled back. |
| `$report->danglingTransactionsCount` | `int` | Number of uncommitted PDO transactions intercepted and rolled back. |
| `$report->fileDescriptorsLeaked` | `bool` | `true` if lingering file handles or open sockets were detected. |
| `$report->fileDescriptorsLeakedCount` | `int` | Count of unclosed file descriptors left behind. |
| `$report->fileDescriptorsLeakedMap` | `array<int, string>` | Map of leaked descriptors `[fd => targetPath]`. |
| `$report->shouldRecycle` | `bool` | `true` if memory drift, emergency ceiling, or request count limits were breached. |
| `$report->recycleReason` | `string\|null` | Human-readable explanation if worker recycling was triggered. |
| `$report->memoryDriftMb` | `float` | Physical RSS delta ($\Delta\text{RSS}$) in megabytes during the request. |
| `$report->zendMemoryDriftMb` | `float` | Delta in current thread Zend Memory Manager during the request. |
| `$report->driftOverBaselineMb` | `float` | Cumulative process memory drift above the worker baseline RSS. |
| `$report->initialMetrics` | `ProcessMetrics` | Snapshot of process memory before request handling. |
| `$report->finalMetrics` | `ProcessMetrics` | Snapshot of process memory after request handling. |
| `$report->isZts` | `bool` | `true` if the runtime is operating in Zend Thread Safety (ZTS) multithread mode. |
| `$report->driftAttributedToThread` | `bool` | In ZTS mode: `true` if process drift was confirmed by the active thread's Zend memory growth. |
| `$report->unattributedProcessDrift` | `bool` | In ZTS mode: `true` if process RSS drifted without current thread Zend memory growth. |
| `$report->consecutiveViolationsCount` | `int` | Current count of consecutive drift breaches. |
| `$report->cooldownActive` | `bool` | `true` if recycling was throttled due to cooldown window. |

---

## 2. Process Memory Metrics (`ProcessMetrics`)

The `$report->initialMetrics` and `$report->finalMetrics` properties contain Linux kernel memory details:

```php
$metrics = $report->finalMetrics;


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

---

## 4. The `#[ResetOnRequest]` Attribute

Use this attribute on classes, properties, or methods to declare state that must be automatically reset to initial default values at the end of every request cycle when registered in Leakless's resettables engine:

```php
use TheMattos\Leakless\Attributes\ResetOnRequest;

class UserSessionContext
{
    // Restores default value null (static property: reset via class string registration)
    #[ResetOnRequest]
    public static ?string $activeToken = null;

    // Calls custom cleanup method on request completion
    #[ResetOnRequest(resetter: 'cleanup')]
    public static array $inMemoryEvents = [];

    // Restores default value [] (instance property: reset via object instance registration)
    #[ResetOnRequest(default: [])]
    public array $permissions = [];

    public static function cleanup(): void
    {
        self::$inMemoryEvents = [];
    }
}
```

### Supported Targets and Parameters

| Parameter | Type | Default | Description |
| :--- | :---: | :---: | :--- |
| `resetter` | `string\|null` | `null` | Name of a custom method on the target to invoke on reset. |
| `default` | `mixed` | `null` | Explicit fallback value to assign to the property on reset. |
| **Attribute Targets** | `Property`, `Class`, `Method` | — | Can be placed directly on static/instance properties, classes, or cleanup methods. |

---

### How ResetOnRequest Works at Runtime

::: tip Registration Requirement
`#[ResetOnRequest]` **does not perform global file or class scanning**. It acts as a set of compiled reset rules for targets explicitly registered in the `resettables` engine:
- In Laravel: add targets to `'resettables'` in `config/leakless.php`.
- In Symfony/Vanilla: register targets via `Config::$resettables` or `$leakless->registerResetTarget($target)`.
:::

#### Static vs. Instance Properties

| Registration Type | Example | What Gets Reset |
| :--- | :--- | :--- |
| **Class String** | `UserSessionContext::class` | **Static properties** annotated with `#[ResetOnRequest]` and **static cleanup methods** (or conventional static `resetState` / `cleanup` methods). Instance properties are ignored because no instance exists. |
| **Object Instance** | `$userSessionContext` | **Instance properties** annotated with `#[ResetOnRequest]`, instance cleanup methods, and conventional `reset()` methods on that specific object. |

::: warning PHPStan vs. Runtime Execution
The static analysis rule `BanMutableStaticPropertiesRule` considers `static` properties annotated with `#[ResetOnRequest]` as safe. **Remember to always add the class to `'resettables'` in your configuration**, otherwise the property will remain mutated and leak across requests at runtime despite passing static analysis!
:::


