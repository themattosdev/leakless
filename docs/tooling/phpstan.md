# PHPStan Rules Extension (Alternative to CLI)

In addition to the standalone native CLI (`vendor/bin/leakless analyze`), the `themattosdev/leakless-dev` package provides an **official PHPStan extension** (`extension.neon`).

This alternative is designed for projects that **already run PHPStan in their CI/CD pipeline** and prefer consolidating all static analysis into a single invocation (`vendor/bin/phpstan analyse`), without needing an extra pipeline step.

---

## When to Choose Each Approach?

| Approach | Execution Model | Performance | Dependencies | Best For |
| :--- | :--- | :--- | :--- | :--- |
| **Native CLI (`leakless analyze`)** *(Recommended)* | Pure AST in-memory engine | **Ultra-fast** (~milliseconds) | Zero heavy dependencies (<20MB RAM) | Recommended for most projects; does not require PHPStan. |
| **PHPStan Extension (`extension.neon`)** | Integrated into PHPStan pipeline | Governed by PHPStan cache | Requires `phpstan/phpstan: ^2.0` in your project | Ideal if your project already uses PHPStan and you want a unified run. |

---

## Installing Requirements

Because Leakless does not force PHPStan as a hard requirement, add PHPStan to your dev dependencies if you haven't already:

```bash
composer require --dev phpstan/phpstan:^2.0
```

---

## Configuration

Include the extension in your `phpstan.neon` configuration:

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

If your project utilizes [`phpstan/extension-installer`](https://github.com/phpstan/extension-installer), `extension.neon` is automatically discovered and registered without manual inclusion.

---

## Running

Run PHPStan normally in your terminal or CI environment:

```bash
vendor/bin/phpstan analyse
```

If any persistent worker rule is breached, PHPStan will report it natively with rule identifiers (e.g. `leakless.mutableStaticProperty`).

---

## Included Rules

| Rule Class | Identifier | What It Enforces |
| :--- | :--- | :--- |
| `BanMutableStaticPropertiesRule` | `leakless.mutableStaticProperty` | Disallows mutable `static` properties on classes unless marked with `#[AllowPersistentState]` or `#[ResetOnRequest]`. |
| `BanEphemeralInjectionInSingletonsRule` | `leakless.ephemeralSingletonInjection` | Prevents constructor injection of `Request` / `Session` in singleton services. |
| `BanSuperglobalsAndTerminatorsRule` | `leakless.superglobal`<br>`leakless.processTerminator`<br>`leakless.sessionStart` | Blocks direct `$_GET`, `$_POST`, `$_SESSION`, `exit()`, `die()`, and `session_start()`. |
| `BanIncompatibleWorkerFunctionsRule` | `leakless.incompatibleFunction`<br>`leakless.globBraceIncompatible`<br>`leakless.imapNotThreadSafe` | Detects `get_browser()`, `GLOB_BRACE` on Alpine musl, `ext-imap`, and direct procedural headers (`setcookie`, `header`). |

::: tip Static Analysis vs Runtime Registration
Marking a mutable static property with `#[ResetOnRequest]` informs PHPStan that state cleanup is intended. **However, you must also register the class in `'resettables'`** (in `config/leakless.php` or via `$leakless->registerResetTarget()`) so that Leakless actually resets it between requests during runtime execution.
:::

