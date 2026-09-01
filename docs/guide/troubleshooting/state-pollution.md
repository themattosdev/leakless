# Fixing State Pollution & Cross-User Data Leaks in PHP

In traditional PHP-FPM, every HTTP request receives a completely isolated PHP process that terminates immediately after sending the response. 

In persistent worker runtimes (**FrankenPHP**, **Laravel Octane**, **RoadRunner**, **Swoole**), the PHP process **survives across thousands of requests**. Any mutable variable stored in global scope or static memory will persist and leak into subsequent requests, leading to **critical cross-user security bugs**.

---

## The Nightmare Scenario: Cross-User Data Leakage

```php
// DANGEROUS: Static property retains data across requests!
class CurrentUser
{
    public static ?User $user = null;
}

// Request 1 (User A logs in):
CurrentUser::$user = $userA;

// Request 2 (Guest user makes an unauthenticated request on the same worker):
// BUG: CurrentUser::$user is STILL User A!
// The guest user sees User A's private dashboard and billing information!
```

---

## 4 Most Dangerous Persistent Worker Anti-Patterns

### 1. Mutable Static Properties
```php
// BAD: State persists indefinitely across all users
class MetricsCollector
{
    public static array $events = [];
}
```
**Fix with Leakless:**
- Reset via `Config::$resettables`: `resettables: [fn () => MetricsCollector::$events = []]`
- Or annotate with `#[ResetOnRequest]`:
  ```php
  use TheMattos\Leakless\Attributes\ResetOnRequest;

  class MetricsCollector
  {
      #[ResetOnRequest(default: [])]
      public static array $events = [];
  }
  ```

---

### 2. Capturing Request or Session in Singleton Constructors
```php
// BAD: The constructor only executes ONCE at worker boot!
// $this->request holds the VERY FIRST request that booted the worker forever.
class InvoiceService
{
    public function __construct(
        private Request $request, // STALE INSTANCE!
    ) {}
}
```
**Fix:** Pass `$request` as a method argument:
```php
class InvoiceService
{
    public function generate(Request $request): Invoice { ... }
}
```

---

### 3. Global Timezone and Error Reporting Mutation
```php
// BAD: Changes timezone globally for every subsequent request on this worker
date_default_timezone_set('America/Sao_Paulo');
```
**Fix with Leakless:**
Leakless automatically captures the worker's initial timezone and error reporting level on `startRequest()` and restores them on `endRequest()`.

---

### 4. Unclosed Output Buffers
```php
// BAD: If an exception is thrown before ob_end_clean(), output leaks into next user's response
ob_start();
renderFragment();
// Exception thrown here! ob_start() is left open!
```
**Fix with Leakless:**
Leakless measures the output buffer depth (`ob_get_level()`) and drains all unclosed buffers in `endRequest()`.

---

## Pre-Production Static Analysis with Leakless

Catch state pollution before your code ever hits staging or production:

### 1. Run the CLI Linter
```bash
vendor/bin/leakless analyze
```

### 2. Add Pest / PHPUnit Structural Assertions
```php
test('all application services are worker safe', function () {
    expect(PaymentService::class)->toBeLeakless();
    expect(InvoiceService::class)->toBeLeakless();
    expect(OrderRepository::class)->toBeLeakless();
});
```
