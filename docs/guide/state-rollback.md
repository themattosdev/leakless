# Defensive State Rollback

Persistent workers do not automatically reset global PHP process state between requests. 

If an application request mutates global settings (such as the default timezone, error reporting levels, or opens output buffers that fail to close), those mutations persist and pollute subsequent worker requests.

---

## Managed State Entities

During the request lifecycle, Leakless automatically captures and restores three critical state vectors:

### 1. Default Timezone Restoration

If application code or third-party packages call `date_default_timezone_set()`:
- Leakless records the initial timezone at `startRequest()`.
- If mutated during the request, Leakless automatically calls `date_default_timezone_set($originalTimezone)` in `endRequest()`.

### 2. Output Buffer Draining

If an error or unhandled branch leaves output buffers unclosed (`ob_start()` without `ob_end_clean()` or `ob_get_clean()`):
- Leakless measures the initial buffer level count (`ob_get_level()`) at `startRequest()`.
- At `endRequest()`, Leakless loops and safely calls `ob_end_clean()` until the initial buffer depth is restored, preventing stray output from leaking into future responses.

### 3. Error Reporting Levels

If code temporarily alters error reporting (`error_reporting(E_ALL)` or silences errors via `@` / custom levels):
- Leakless records the original error reporting bitmask.
- Restores the original `error_reporting()` level at request completion.
