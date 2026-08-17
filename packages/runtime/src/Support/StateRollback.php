<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Support;

final class StateRollback
{
    private string $initialTimezone;

    private ?string $initialWorkingDir;

    private int $initialObLevel;

    private int $initialErrorReporting;

    public function __construct()
    {
        $this->captureInitialState();
    }

    /**
     * Snapshot global environment state at worker / request boot.
     */
    public function captureInitialState(): void
    {
        $this->initialTimezone = date_default_timezone_get();
        $cwd = getcwd();
        $this->initialWorkingDir = $cwd !== false ? $cwd : null;
        $this->initialObLevel = ob_get_level();
        $this->initialErrorReporting = error_reporting();
    }

    /**
     * Revert global environment state back to the captured baseline.
     */
    public function rollback(): void
    {
        // 1. Restore default timezone if changed
        if (date_default_timezone_get() !== $this->initialTimezone) {
            date_default_timezone_set($this->initialTimezone);
        }

        // 2. Restore current working directory if changed
        if ($this->initialWorkingDir !== null && getcwd() !== $this->initialWorkingDir) {
            @chdir($this->initialWorkingDir);
        }

        // 3. Drain residual output buffers opened during the request
        while (ob_get_level() > $this->initialObLevel) {
            @ob_end_clean();
        }

        // 4. Restore error reporting level if modified
        if (error_reporting() !== $this->initialErrorReporting) {
            error_reporting($this->initialErrorReporting);
        }
    }
}
