# Developer Tooling Overview

The `themattosdev/leakless-dev` package provides developer tooling to detect and eliminate persistent worker hazards before code reaches production:

- [**Static Linter CLI (`leakless analyze`)**](./cli.md): Fast, standalone AST static analyzer with styled Termwind terminal reporting and JSON output.
- [**Pest Custom Expectations**](./pest.md): `expect($class)->toBeLeakless()` and `expect($closure)->toRunCleanly()` for automated testing.
- [**Laravel HTTP Test Macros**](./laravel-macros.md): `$response->assertNoDanglingTransactions()`, `$response->assertNoMemoryDrift()`, and `$response->assertCleanWorkerState()`.
- [**PHPStan AST Extension**](./phpstan.md): Drop-in PHPStan Level 9 ruleset (`extension.neon`) enforcing persistent worker safety in your static analysis pipeline.

---

## Installation

```bash
composer require --dev themattosdev/leakless-dev
```
