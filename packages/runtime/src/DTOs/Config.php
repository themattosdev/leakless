<?php

declare(strict_types=1);

namespace TheMattos\Leakless\DTOs;

use Closure;
use InvalidArgumentException;

final readonly class Config
{
    /**
     * @param  int  $maxRssMb  Threshold for real OS RSS memory in MB before graceful recycling is triggered.
     * @param  bool  $checkTransactions  Whether to inspect and defensibly rollback orphaned PDO transactions.
     * @param  bool  $checkFileDescriptors  Whether to audit lingering file descriptors and TCP sockets.
     * @param  bool  $autoRecycleOnViolation  Whether to flag the worker for graceful exit when thresholds are breached.
     * @param  int|null  $maxRequests  Optional request ceiling before recycling the worker process.
     * @param  bool  $logViolations  Whether to automatically output warnings to stderr/error_log on detected violations.
     * @param  (Closure(string, Report): void)|null  $logger  Optional custom logger closure for violation messages.
     * @param  (Closure(Report): void)|null  $onReport  Optional telemetry closure executed after each audited request.
     */
    public function __construct(
        public int $maxRssMb = 128,
        public bool $checkTransactions = true,
        public bool $checkFileDescriptors = false,
        public bool $autoRecycleOnViolation = true,
        public ?int $maxRequests = null,
        public bool $logViolations = true,
        public ?Closure $logger = null,
        public ?Closure $onReport = null,
    ) {
        if ($this->maxRssMb <= 0) {
            throw new InvalidArgumentException("maxRssMb must be greater than 0, received [{$this->maxRssMb}].");
        }

        if ($this->maxRequests !== null && $this->maxRequests <= 0) {
            throw new InvalidArgumentException("maxRequests must be greater than 0 if provided, received [{$this->maxRequests}].");
        }
    }
}
