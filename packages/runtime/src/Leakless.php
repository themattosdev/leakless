<?php

declare(strict_types=1);

namespace TheMattos\Leakless;

use Closure;
use PDO;
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\DTOs\ProcessMetrics;
use TheMattos\Leakless\DTOs\Report;
use TheMattos\Leakless\Guards\FileDescriptorGuard;
use TheMattos\Leakless\Guards\TransactionGuard;
use TheMattos\Leakless\Support\ProcStatmParser;
use TheMattos\Leakless\Support\StateRollback;

final class Leakless
{
    private readonly Config $config;

    private readonly ProcStatmParser $statmParser;

    private readonly TransactionGuard $transactionGuard;

    private readonly FileDescriptorGuard $fileDescriptorGuard;

    private readonly StateRollback $stateRollback;

    private ?ProcessMetrics $initialMetrics = null;

    /**
     * @var array<int, string>
     */
    private array $initialDescriptors = [];

    private ?float $requestStartTime = null;

    private int $requestCount = 0;

    private ?Report $lastReport = null;

    /**
     * @var (Closure(Report): void)|null
     */
    private ?Closure $recycler;

    /**
     * @param  Config|null  $config  Configuration parameters and operational thresholds.
     * @param  ProcStatmParser|null  $statmParser  Parser for /proc/self/statm metrics.
     * @param  TransactionGuard|null  $transactionGuard  Guard for uncommitted PDO transactions.
     * @param  FileDescriptorGuard|null  $fileDescriptorGuard  Guard for lingering file descriptors.
     * @param  StateRollback|null  $stateRollback  State rollback manager for global PHP environment.
     * @param  (Closure(Report): void)|null  $recycler  Custom recycle handler (useful for testing without exiting PHP).
     */
    public function __construct(
        ?Config $config = null,
        ?ProcStatmParser $statmParser = null,
        ?TransactionGuard $transactionGuard = null,
        ?FileDescriptorGuard $fileDescriptorGuard = null,
        ?StateRollback $stateRollback = null,
        ?Closure $recycler = null,
    ) {
        $this->config = $config ?? new Config;
        $this->statmParser = $statmParser ?? new ProcStatmParser;
        $this->transactionGuard = $transactionGuard ?? new TransactionGuard;
        $this->fileDescriptorGuard = $fileDescriptorGuard ?? new FileDescriptorGuard;
        $this->stateRollback = $stateRollback ?? new StateRollback;
        $this->recycler = $recycler;
    }

    /**
     * Mark the start of an incoming HTTP request cycle.
     */
    public function startRequest(): void
    {
        $this->requestCount++;
        $this->requestStartTime = microtime(true);
        $this->initialMetrics = $this->statmParser->parse();
        $this->stateRollback->captureInitialState();

        if ($this->config->checkFileDescriptors) {
            $this->initialDescriptors = $this->fileDescriptorGuard->captureOpenDescriptors();
        }
    }

    /**
     * Finalize and audit the request cycle in a finally block.
     *
     * @param  array<string, mixed>  $metadata  Extra contextual metadata (e.g. route, method).
     * @param  array<int, PDO>  $additionalConnections  Extra PDO connections to inspect on the fly.
     */
    public function endRequest(array $metadata = [], array $additionalConnections = []): Report
    {
        $durationMs = $this->requestStartTime !== null
            ? round((microtime(true) - $this->requestStartTime) * 1000, 2)
            : 0.0;

        // 1. Audit and defensibly roll back open transactions
        $txAudit = $this->config->checkTransactions
            ? $this->transactionGuard->auditAndRollback($additionalConnections)
            : ['detected' => false, 'rolledBackCount' => 0, 'errors' => []];

        // 2. Audit lingering file descriptors
        $fdAudit = $this->config->checkFileDescriptors
            ? $this->fileDescriptorGuard->audit($this->initialDescriptors)
            : ['detected' => false, 'leakedCount' => 0, 'leakedDescriptors' => []];

        // 3. Perform state rollback for global environment
        $this->stateRollback->rollback();

        // 4. Capture post-request process metrics
        $finalMetrics = $this->statmParser->parse();
        $initialMetrics = $this->initialMetrics ?? $finalMetrics;

        // 5. Evaluate whether worker should be gracefully recycled
        $shouldRecycle = false;
        $recycleReason = null;

        if ($finalMetrics->rssMb > $this->config->maxRssMb) {
            $shouldRecycle = true;
            $recycleReason = "RSS memory limit exceeded: {$finalMetrics->rssMb}MB > {$this->config->maxRssMb}MB";
        } elseif ($this->config->maxRequests !== null && $this->requestCount >= $this->config->maxRequests) {
            $shouldRecycle = true;
            $recycleReason = "Max requests ceiling reached: {$this->requestCount}/{$this->config->maxRequests}";
        }

        // 6. Build Report DTO
        $report = new Report(
            initialMetrics: $initialMetrics,
            finalMetrics: $finalMetrics,
            durationMs: $durationMs,
            danglingTransactionsDetected: $txAudit['detected'],
            danglingTransactionsCount: $txAudit['rolledBackCount'],
            fileDescriptorsLeaked: $fdAudit['detected'],
            fileDescriptorsLeakedCount: $fdAudit['leakedCount'],
            fileDescriptorsLeakedMap: $fdAudit['leakedDescriptors'],
            shouldRecycle: $shouldRecycle,
            recycleReason: $recycleReason,
            metadata: $metadata,
        );

        $this->lastReport = $report;

        // 7. Log anomalies / violations automatically (Zero-Config Logging)
        if ($this->config->logViolations && ! $report->isClean()) {
            $this->logViolation($report);
        }

        // 8. Trigger telemetry callback if configured
        if ($this->config->onReport !== null) {
            ($this->config->onReport)($report);
        }

        // 9. Trigger graceful recycling if required
        if ($shouldRecycle && $this->config->autoRecycleOnViolation) {
            $this->triggerRecycle($report);
        }

        return $report;
    }

    /**
     * Register a PDO connection to be audited across request cycles.
     */
    public function registerConnection(PDO $connection): self
    {
        $this->transactionGuard->registerConnection($connection);

        return $this;
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getLastReport(): ?Report
    {
        return $this->lastReport;
    }

    public function getRequestCount(): int
    {
        return $this->requestCount;
    }

    public function getTransactionGuard(): TransactionGuard
    {
        return $this->transactionGuard;
    }

    public function getFileDescriptorGuard(): FileDescriptorGuard
    {
        return $this->fileDescriptorGuard;
    }

    public function getStateRollback(): StateRollback
    {
        return $this->stateRollback;
    }

    public function resetRequestCount(): void
    {
        $this->requestCount = 0;
    }

    private function logViolation(Report $report): void
    {
        $messages = [];

        if ($report->danglingTransactionsDetected) {
            $messages[] = "[Leakless] 🚨 Dangling database transaction(s) detected and rolled back ({$report->danglingTransactionsCount} transaction(s)).";
        }

        if ($report->fileDescriptorsLeaked) {
            $details = implode(', ', array_map(fn ($fd, $target) => "#{$fd} ({$target})", array_keys($report->fileDescriptorsLeakedMap), $report->fileDescriptorsLeakedMap));
            $messages[] = "[Leakless] 🚨 Lingering file descriptor(s) detected ({$report->fileDescriptorsLeakedCount} descriptor(s)): {$details}";
        }

        if ($report->shouldRecycle && $report->recycleReason !== null) {
            $messages[] = "[Leakless] ⚠️ Worker recycling triggered. Reason: {$report->recycleReason}";
        }

        foreach ($messages as $message) {
            if ($this->config->logger !== null) {
                ($this->config->logger)($message, $report);
            } else {
                error_log($message);
            }
        }
    }

    private function triggerRecycle(Report $report): void
    {
        if ($this->recycler !== null) {
            ($this->recycler)($report);

            return;
        }

        if (function_exists('frankenphp_finish_request')) {
            @frankenphp_finish_request();
        }

        exit(0);
    }
}
