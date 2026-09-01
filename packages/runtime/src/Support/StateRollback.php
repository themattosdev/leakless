<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Support;

final class StateRollback
{
    private string $initialTimezone;

    private ?string $initialWorkingDir;

    private int $initialObLevel;

    private int $initialErrorReporting;

    private readonly StateResetter $stateResetter;

    public function __construct(?StateResetter $stateResetter = null)
    {
        $this->stateResetter = $stateResetter ?? new StateResetter;
        $this->captureInitialState();
    }

    public function getStateResetter(): StateResetter
    {
        return $this->stateResetter;
    }

    /**
     * @param  class-string<object>|object|callable  $target
     */
    public function registerResetTarget(string|object|callable $target): void
    {
        $this->stateResetter->registerTarget($target);
    }

    /**
     * @param  array<int, class-string<object>|object|callable>  $targets
     */
    public function registerResetTargets(array $targets): void
    {
        $this->stateResetter->registerTargets($targets);
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
        $this->stateResetter->resetAll();
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
