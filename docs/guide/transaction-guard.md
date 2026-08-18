# Automated Transaction Guard (PDO)

In persistent workers, database connections (PDO instances) are long-lived and reused across sequential requests.

If an HTTP request begins a database transaction (`$pdo->beginTransaction()` or `DB::beginTransaction()`) but fails to commit or roll back (due to an unhandled exception, early return, or developer oversight):
- The transaction remains active on the connection.
- The connection is returned to the pool or reused by the worker.
- The **next user's request** unintentionally runs queries inside the uncommitted transaction of the previous user.
- This leads to table lockups, concurrency deadlocks, and cross-tenant data corruption.

---

## How TransactionGuard Works

At the end of every request cycle (`$leakless->endRequest()`), the `TransactionGuard` engine runs an automated audit:

1. **Connection Auto-Discovery**:
   - In **Laravel Octane**, inspects `DB::getConnections()` to retrieve all active database connections (`Illuminate\Database\Connection`).
   - In **Vanilla PHP**, developers or containers can register PDO instances via `$leakless->registerPdo($pdo)`.
2. **Transaction State Inspection**:
   - Queries `$pdo->inTransaction()` on every registered and discovered connection.
3. **Automated Rollback**:
   - If any connection has an active transaction open, `TransactionGuard` immediately calls `$pdo->rollBack()`.
4. **Diagnostic Logging & Reporting**:
   - Logs an alert containing the count of rolled-back transactions:
     `[Leakless] 🚨 Dangling database transaction(s) detected and rolled back (1 transaction(s)).`
   - Sets `hasTransactionLeak = true` in the `Report` DTO.

---

## Registering Custom PDO Connections (Vanilla PHP)

In vanilla PHP or non-Laravel frameworks, register your PDO instances into Leakless:

```php
$pdo = new PDO('mysql:host=localhost;dbname=app', 'user', 'pass');

// Register PDO connection for automated end-of-request transaction audits
$leakless->registerPdo($pdo);
```
