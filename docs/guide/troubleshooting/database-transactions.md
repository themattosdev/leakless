# Preventing Orphaned PDO Transactions & Deadlocks in PHP Workers

In traditional PHP-FPM, if a request terminates due to an uncaught exception, timeout, or fatal error while inside an open database transaction (`$pdo->beginTransaction()`), the PHP process dies and the database server automatically rolls back the uncommitted transaction upon socket close.

In persistent PHP runtimes (**FrankenPHP**, **Laravel Octane**, **RoadRunner**, **Swoole**), **the database connection is preserved across requests**.

If an open transaction is not committed or rolled back, the PDO connection returns to the connection pool **still in transaction mode** (`$pdo->inTransaction() === true`).

---

## The Danger: How Orphaned Transactions Destroy Production

```
Request 1 (Checkout):
  ├── $pdo->beginTransaction();
  ├── UPDATE inventory SET stock = stock - 1 WHERE item_id = 42; (Row Locked!)
  └── throw new PaymentFailedException(); (Request ends without rollback!)
      └── Connection #1 returns to worker pool with active locks!

Request 2 (Unrelated User visits Home page):
  ├── Reuses Connection #1
  ├── SELECT * FROM products;
  └── $pdo->beginTransaction(); ➔ FATAL: "There is already an active transaction"!
      └── Or queries hang forever waiting on Row Lock #42 ➔ DB Connection Pool Exhaustion!
```

---

## Why Standard Try/Catch Blocks Are Not Enough

Even with disciplined coding, transactions can remain open due to:
1. **Early `return` statements** skipping the `commit()` line.
2. **Third-party packages** opening nested transactions with broken commit logic.
3. **Database disconnection spikes** during commit.
4. **Unhandled errors / memory limits** inside critical blocks.

---

## How Leakless TransactionGuard Fixes It Automatically

Leakless includes a built-in `TransactionGuard` that runs inside the `finally` block of every request cycle.

```
Request Cycle
      │
      ▼
$leakless->startRequest()
      │
      ▼
Application Logic (Handles HTTP / Message)
      │
      ▼
finally {
    $leakless->endRequest()
          │
          ▼
    TransactionGuard audits all active PDO connections:
          ├── Is $pdo->inTransaction() === true?
          ├── YES ➔ Execute $pdo->rollBack() immediately!
          └── Log diagnostic warning with stack trace!
}
```

---

## Configuration & Usage

Transaction auditing is enabled by default in Leakless:

```php
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Leakless;

$config = new Config(
    checkTransactions: true, // Enabled by default
);

$leakless = new Leakless($config);

// In Laravel Octane: Leakless automatically audits all Laravel DatabaseManager PDOs.
// In Vanilla: Register custom PDO instances on boot:
$leakless->registerConnection($pdo);
```

When an orphaned transaction is caught, Leakless rolls it back defensively and emits a structured diagnostic log:

```
[Leakless] 🚨 Dangling database transaction(s) detected and rolled back (1 transaction(s)).
```
