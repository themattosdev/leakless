# Why Leakless?

In traditional PHP-FPM environments, PHP executes under a **Shared-Nothing architecture**:

1. An incoming HTTP request spawns or takes an isolated worker process.
2. The application boots, handles the request, and produces a response.
3. The worker process immediately flushes buffers, closes all database connections, frees all memory, and **terminates** (or completely reinitializes its VM state).

Under PHP-FPM, memory leaks, unclosed database transactions, mutated timezones, or dirty static caches were practically harmless because the operating system wiped everything at request completion.

---

## The Paradigm Shift: Persistent Workers

Modern PHP runtimes such as **FrankenPHP Worker Mode** and **Laravel Octane** keep PHP workers persistently resident in RAM across thousands of sequential requests:

```
[Request #1] ───► Bootstrapped Worker (RAM) ───► Response #1
                         │ (Worker stays alive!)
[Request #2] ───► Same Worker in Memory ──────► Response #2
                         │
[Request #N] ───► Same Worker in Memory ──────► Response #N
```

This persistent execution architecture delivers **10x to 50x higher throughput** and sub-millisecond boot times by avoiding repeated framework bootstrapping.

However, keeping workers alive introduces critical architectural vulnerabilities:

### 1. Silent Native Memory Growth (C-Extensions)

Standard PHP memory profilers (`memory_get_usage()`) only track memory allocated inside the **Zend Engine heap**.

When your application uses native C-extensions (`ext-curl`, `ext-imagick`, `ext-gd`, `ext-openssl`, `ext-pdo`):
- Memory is allocated via standard C library functions (`malloc()`) outside the PHP engine.
- Zend VM memory profilers remain completely blind to these allocations.
- Over hundreds of worker cycles, unmonitored native memory accumulates until the Linux kernel OOM Killer abruptly terminates the worker process.

### 2. Dangling Database Transactions

If an unhandled exception or missing `commit()` occurs inside an application request:
- An active `BEGIN TRANSACTION` block remains open on the persistent PDO connection.
- The next HTTP request from a completely different user reuses that connection.
- Queries executed in the second request run inside the first user's uncommitted transaction, causing cross-tenant data corruption and database lockups.

### 3. Global State Mutation

Persistent workers retain global process modifications:
- Mutating the default timezone via `date_default_timezone_set()` persists for all future requests on that worker.
- Unclosed output buffers created with `ob_start()` leak partial HTML or JSON into subsequent requests.
- Modifying error reporting levels (`error_reporting()`) permanently alters application error handling.

---

## How Leakless Protects You

**Leakless** provides an autonomous, zero-overhead safety guardian designed specifically for persistent PHP:

| Safety Pillar | Mechanism | Protection |
| :--- | :--- | :--- |
| **Real Kernel RSS** | Direct `/proc/self/statm` inspection | Tracks actual Linux memory including native C-extensions |
| **Transaction Guard** | PDO connection reflection & auditing | Detects and automatically rolls back uncommitted transactions |
| **State Rollback** | Defensive `finally` lifecycle handler | Restores default timezone, flushes output buffers, and resets error levels |
| **Worker Recycling** | Graceful request threshold interceptor | Triggers worker recycling without dropping active requests |
| **Static Linter CLI** | AST-based PHPStan inspection | Detects worker anti-patterns in CI/CD before deployment |
| **Pest Assertions** | `toBeLeakless()` & `toRunCleanly()` | Automated unit and integration testing for persistent safety |
