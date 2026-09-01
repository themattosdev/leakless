<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Leakless Enabled
    |--------------------------------------------------------------------------
    |
    | When enabled, Leakless monitors Laravel Octane request cycles for memory
    | drift, dangling database transactions, and lingering environment state.
    |
    */
    'enabled' => env('LEAKLESS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Maximum Memory Drift Ceiling (MB) [Recommended]
    |--------------------------------------------------------------------------
    |
    | The maximum allowable memory drift in megabytes above the worker's initial
    | boot baseline (/proc/self/statm) before recycling evaluation is triggered.
    |
    */
    'max_drift_mb' => env('LEAKLESS_MAX_DRIFT_MB') !== null ? (int) env('LEAKLESS_MAX_DRIFT_MB') : 64,

    /*
    |--------------------------------------------------------------------------
    | Hard Real RSS Memory Ceiling (MB) [Emergency / Optional]
    |--------------------------------------------------------------------------
    |
    | Optional hard ceiling for physical Resident Set Size (RSS) memory in MB.
    | When exceeded, triggers immediate emergency worker recycling to prevent
    | Linux OOM Killer SIGKILL. Leave null to rely on relative drift.
    |
    */
    'max_rss_mb' => env('LEAKLESS_MAX_RSS_MB') ? (int) env('LEAKLESS_MAX_RSS_MB') : null,

    /*
    |--------------------------------------------------------------------------
    | Consecutive Violations Threshold (Hysteresis)
    |--------------------------------------------------------------------------
    |
    | The number of consecutive requests that must breach the memory drift threshold
    | after garbage collection before triggering worker recycling. This prevents
    | recycling on transient, single-request memory spikes.
    |
    */
    'consecutive_violations' => (int) env('LEAKLESS_CONSECUTIVE_VIOLATIONS', 5),

    /*
    |--------------------------------------------------------------------------
    | Recycling Cooldown Window (Seconds)
    |--------------------------------------------------------------------------
    |
    | Minimum time interval in seconds between worker recycling triggers.
    | Prevents "restart storms" where multiple workers recycle simultaneously
    | during sudden bursts of concurrent traffic.
    |
    */
    'recycle_cooldown' => (int) env('LEAKLESS_RECYCLE_COOLDOWN', 10),

    /*
    |--------------------------------------------------------------------------
    | Trigger Garbage Collection On Potential Breach
    |--------------------------------------------------------------------------
    |
    | When true, gc_collect_cycles() is automatically executed when memory drift
    | exceeds threshold, re-evaluating physical memory before confirming a violation.
    |
    */
    'trigger_gc' => env('LEAKLESS_TRIGGER_GC', true),

    /*
    |--------------------------------------------------------------------------
    | Memory Drift Jitter (%)
    |--------------------------------------------------------------------------
    |
    | Random percentage variation applied to max_drift_mb per worker to desynchronize
    | recycling events across multiple workers under uniform traffic loads.
    |
    */
    'drift_jitter' => (int) env('LEAKLESS_DRIFT_JITTER', 10),

    /*
    |--------------------------------------------------------------------------
    | Check Dangling Database Transactions
    |--------------------------------------------------------------------------
    |
    | When true, Leakless audits all active Laravel database connections at
    | the end of each request and defensibly rolls back uncommitted transactions.
    |
    */
    'check_transactions' => env('LEAKLESS_CHECK_TRANSACTIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Check File Descriptors
    |--------------------------------------------------------------------------
    |
    | When true, Leakless inspects /proc/self/fd/ for unclosed file handles
    | and lingering network sockets.
    |
    */
    'check_file_descriptors' => env('LEAKLESS_CHECK_FILE_DESCRIPTORS', false),

    /*
    |--------------------------------------------------------------------------
    | Auto Recycle On Violation
    |--------------------------------------------------------------------------
    |
    | Automatically triggers graceful worker recycling when thresholds or
    | critical state corruption are detected.
    |
    */
    'auto_recycle' => env('LEAKLESS_AUTO_RECYCLE', true),

    /*
    |--------------------------------------------------------------------------
    | Maximum Requests Ceiling
    |--------------------------------------------------------------------------
    |
    | Optional maximum number of requests handled by a worker before recycling.
    | Set to null to rely solely on memory and state auditing (e.g. 1000).
    |
    */
    'max_requests' => env('LEAKLESS_MAX_REQUESTS') ? (int) env('LEAKLESS_MAX_REQUESTS') : null,

    /*
    |--------------------------------------------------------------------------
    | Log Violations
    |--------------------------------------------------------------------------
    |
    | When true, violations (orphaned transactions, memory overflow) are logged
    | automatically to Laravel's default log channel.
    |
    */
    'log_violations' => env('LEAKLESS_LOG_VIOLATIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Resettables Targets & Callbacks
    |--------------------------------------------------------------------------
    |
    | List of class strings, object instances, or callbacks to automatically
    | reset at the end of each request in persistent workers.
    |
    */
    'resettables' => [
        // App\Services\CartSession::class,
    ],
];
