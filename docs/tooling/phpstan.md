# PHPStan AST Rules Extension

The `themattosdev/leakless-dev` package provides a dedicated PHPStan extension (`extension.neon`) containing AST rules to inspect your code during static analysis.

---

## Configuration

Include the extension in your project's `phpstan.neon`:

```yaml
includes:
    - vendor/themattosdev/leakless-dev/extension.neon

parameters:
    level: max
    paths:
        - app
        - src
    ignoreErrors:
        - '#Call to an undefined method Pest\\Expectation.*::(toBeLeakless|toRunCleanly|toResetContainerState|toHaveStatelessInstances)\(\)#'
        - '#Call to an undefined method Illuminate\\Testing\\TestResponse.*::(assertNoDanglingTransactions|assertNoMemoryDrift|assertCleanWorkerState)\(\)#'
```

---

## Included AST Rules

| Rule Class | What It Enforces |
| :--- | :--- |
| `BanMutableStaticPropertiesRule` | Disallows mutable `static` properties on classes unless marked with `#[AllowPersistentState]`. |
| `BanEphemeralInjectionInSingletonsRule` | Prevents constructor injection of `Request` / `Session` in singleton services. |
| `BanSuperglobalsAndTerminatorsRule` | Blocks direct `$_GET`, `$_POST`, `$_SESSION`, `exit()`, `die()`, and `session_start()`. |
| `BanIncompatibleWorkerFunctionsRule` | Detects `get_browser()`, `GLOB_BRACE` on Alpine musl, `ext-imap`, and direct procedural headers. |
