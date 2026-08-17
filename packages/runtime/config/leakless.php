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
    | Real RSS Memory Ceiling (MB)
    |--------------------------------------------------------------------------
    |
    | The maximum real Resident Set Size (RSS) memory in megabytes measured
    | from the Linux kernel (/proc/self/statm) before graceful recycling.
    |
    */
    'max_rss_mb' => (int) env('LEAKLESS_MAX_RSS_MB', 256),

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
    | Set to null to rely solely on memory and state auditing.
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
];
