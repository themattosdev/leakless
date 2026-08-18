# Static Analysis CLI (`leakless analyze`)

The Leakless CLI provides a standalone command-line tool to analyze your codebase against all persistent worker rules without requiring manual PHPStan configuration.

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
| `--memory-limit` | `-m` | `512M` | Sets the maximum memory limit for the PHP process running the analyzer. |
| `--configuration` | `-c` | `null` | Path to a custom `phpstan.neon` configuration file. |
| `--json` | | `false` | Outputs raw machine-readable JSON for CI/CD integrations. |

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
