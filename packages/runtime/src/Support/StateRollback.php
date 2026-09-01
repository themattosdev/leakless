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
        $this->restoreTimezone();
        $this->restoreWorkingDirectory();
        $this->drainOutputBuffers();
        $this->restoreErrorReporting();
    }

    private function restoreTimezone(): void
    {
        if (date_default_timezone_get() !== $this->initialTimezone) {
            date_default_timezone_set($this->initialTimezone);
        }
    }

    private function restoreWorkingDirectory(): void
    {
        if ($this->initialWorkingDir !== null && getcwd() !== $this->initialWorkingDir) {
            @chdir($this->initialWorkingDir);
        }
    }

    private function drainOutputBuffers(): void
    {
        while (ob_get_level() > $this->initialObLevel) {
            @ob_end_clean();
        }
    }

    private function restoreErrorReporting(): void
    {
        if (error_reporting() !== $this->initialErrorReporting) {
            error_reporting($this->initialErrorReporting);
        }
    }
}
