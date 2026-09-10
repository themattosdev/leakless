# Static Analysis CLI (`leakless analyze`)

The Leakless CLI provides a standalone command-line tool to analyze your codebase against all persistent worker rules without requiring manual PHPStan configuration.

> [!TIP]
> The `leakless analyze` command uses a native in-memory Pure AST engine, executing in milliseconds with very low memory footprint (<20MB). If you prefer running these validations directly inside your existing `phpstan analyse` pipeline, see the [PHPStan Extension Guide](./phpstan.md).

---

## Usage

Run analysis against standard application directories (`app/`, `src/`):

```bash
vendor/bin/leakless analyze
```

### Analyzing Custom Paths

Pass specific directories or files to inspect:

```bash
vendor/bin/leakless analyze app/Services src/Infrastructure
```

---

## CLI Options

| Option | Flag | Default | Description |
| :--- | :---: | :---: | :--- |
| `--memory-limit` | `-m` | `256M` | Sets the maximum memory limit for the PHP process running the analyzer. |
| `--configuration` | `-c` | `null` | Kept for backwards compatibility. *(Note: The Pure AST engine is zero-config and does not parse `phpstan.neon`. If you need custom `ignoreErrors` or PHPStan configuration, use the [PHPStan Extension](./phpstan.md)).* |
| `--json` | | `false` | Outputs raw machine-readable JSON for CI/CD integrations. |

---

## Validated Worker Safety Rules

The Pure AST engine traverses all PHP files in the target directories and validates the following rules:

| Rule Identifier | Hazard Checked | Remediation |
| :--- | :--- | :--- |
| `leakless.mutableStaticProperty` | Mutable `static` properties retaining state across worker cycles. | Mark as `readonly`, convert to instance property, or annotate with `#[AllowPersistentState]` / `#[ResetOnRequest]`. |
| `leakless.ephemeralSingletonInjection` | Constructor injection of ephemeral request objects (`Request`, `Session`) into services. | Inject request into action methods, or inject a closure / Container resolver instead. |
| `leakless.superglobal` | Direct access to `$_GET`, `$_POST`, `$_SESSION`, `$_REQUEST`, or `$_FILES`. | Use framework Request or Session abstraction. |
| `leakless.processTerminator` | Direct `exit()` or `die()` calls that terminate the entire persistent worker process. | Return a Response object or throw an exception. |
| `leakless.sessionStart` | Direct native `session_start()` calls corrupting persistent worker concurrency. | Use framework Session manager. |
| `leakless.incompatibleFunction` | Incompatible worker functions (`get_browser`, `header`, `setcookie`, `session_*`, `flush`). | Use framework Response / Cookie / Session abstraction. |
| `leakless.globBraceIncompatible` | `GLOB_BRACE` flag in `glob()`, unsupported on Alpine musl libc (returns false). | Use multiple `glob()` calls or Symfony Finder. |
| `leakless.imapNotThreadSafe` | `imap_*` functions from `ext-imap` which are not thread-safe. | Use modern userland packages (e.g. `webklex/php-imap`). |

---

## Output Formats

### Terminal Mode (Termwind UI)

In interactive terminals, Leakless renders a formatted status banner, grouped file paths, line numbers, and actionable remediation hints:

```text
 LEAKLESS  Static Worker Analysis (app)

 ✕ FAIL: Found 2 worker violation(s) in codebase.

app/Repositories/CachedUserRepository.php
  Line 14: Mutable static property App\Repositories\CachedUserRepository::$cache retention detected. (leakless.mutableStaticProperty)

app/Services/AuditService.php
  Line 19: Direct exit() or die() call detected. In persistent worker environments, calling exit terminates the entire worker process. (leakless.processTerminator)

💡 Hint: Use #[AllowPersistentState] on intentional static properties, or wrap request-scoped dependencies.
```

### JSON Mode (`--json`)

In automated CI/CD pipelines, use `--json` to consume findings programmatically:

```bash
vendor/bin/leakless analyze --json
```

```json
{
  "totals": {
    "errors": 0,
    "file_errors": 2
  },
  "files": {
    "/app/src/Service.php": {
      "errors": 1,
      "messages": [
        {
          "message": "Mutable static property...",
          "line": 14,
          "identifier": "leakless.mutableStaticProperty"
        }
      ]
    }
  }
}
```

---

## Exit Codes

- `0`: Analysis passed with **zero violations**.
- `1`: Analysis detected one or more persistent worker violations.

