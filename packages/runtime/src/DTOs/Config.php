<?php

declare(strict_types=1);

namespace TheMattos\Leakless\DTOs;

use Closure;
use InvalidArgumentException;

final readonly class Config
{
    /**
     * @param  int|null  $maxDriftMb  Allowable relative RSS memory drift in MB above worker baseline before recycling evaluation.
     * @param  int|null  $maxRssMb  Emergency hard ceiling for real OS RSS memory in MB before immediate recycling.
     * @param  bool  $checkTransactions  Whether to inspect and defensibly rollback orphaned PDO transactions.
     * @param  bool  $checkFileDescriptors  Whether to audit lingering file descriptors and TCP sockets.
     * @param  bool  $autoRecycleOnViolation  Whether to flag the worker for graceful exit when thresholds are breached.
     * @param  int|null  $maxRequests  Optional request ceiling before recycling the worker process.
     * @param  int  $consecutiveViolationsThreshold  Number of consecutive breaches required before triggering worker recycling.
     * @param  int  $recycleCooldownSeconds  Minimum interval in seconds between worker recycling triggers.
     * @param  bool  $triggerGcOnBreach  Whether to invoke gc_collect_cycles() before confirming a memory breach.
     * @param  int  $driftJitterPercentage  Random percentage variation applied to maxDriftMb to prevent synchronized restarts.
     * @param  bool  $logViolations  Whether to automatically output warnings to stderr/error_log on detected violations.
     * @param  (Closure(string, Report): void)|null  $logger  Optional custom logger closure for violation messages.
     * @param  (Closure(Report): void)|null  $onReport  Optional telemetry closure executed after each audited request.
     */
    public function __construct(
        public ?int $maxDriftMb = 64,
        public ?int $maxRssMb = null,
        public bool $checkTransactions = true,
        public bool $checkFileDescriptors = false,
        public bool $autoRecycleOnViolation = true,
        public ?int $maxRequests = null,
        public int $consecutiveViolationsThreshold = 5,
        public int $recycleCooldownSeconds = 10,
        public bool $triggerGcOnBreach = true,
        public int $driftJitterPercentage = 10,
        public bool $logViolations = true,
        public ?Closure $logger = null,
        public ?Closure $onReport = null,
    ) {
        $this->validateLimits();
        $this->validateTuning();
    }

    private function validateLimits(): void
    {
        if ($this->maxDriftMb !== null && $this->maxDriftMb <= 0) {
            throw new InvalidArgumentException("maxDriftMb must be greater than 0 if provided, received [{$this->maxDriftMb}].");
        }

        if ($this->maxRssMb !== null && $this->maxRssMb <= 0) {
            throw new InvalidArgumentException("maxRssMb must be greater than 0 if provided, received [{$this->maxRssMb}].");
        }

        if ($this->maxRequests !== null && $this->maxRequests <= 0) {
            throw new InvalidArgumentException("maxRequests must be greater than 0 if provided, received [{$this->maxRequests}].");
        }
    }

    private function validateTuning(): void
    {
        if ($this->consecutiveViolationsThreshold <= 0) {
            throw new InvalidArgumentException("consecutiveViolationsThreshold must be greater than 0, received [{$this->consecutiveViolationsThreshold}].");
        }

        if ($this->recycleCooldownSeconds < 0) {
            throw new InvalidArgumentException("recycleCooldownSeconds cannot be negative, received [{$this->recycleCooldownSeconds}].");
        }

        if ($this->driftJitterPercentage < 0 || $this->driftJitterPercentage > 100) {
            throw new InvalidArgumentException("driftJitterPercentage must be between 0 and 100, received [{$this->driftJitterPercentage}].");
        }
    }
}

