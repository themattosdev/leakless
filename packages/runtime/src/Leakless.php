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

    private ?ProcessMetrics $baselineMetrics = null;

    private ?float $effectiveDriftLimitMb = null;

    private ?ProcessMetrics $initialMetrics = null;

    /**
     * @var array<int, string>
     */
    private array $initialDescriptors = [];

    private ?float $requestStartTime = null;

    private int $requestCount = 0;

    private int $consecutiveViolations = 0;

    private ?float $lastRecycleTimestamp = null;

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

        if (! empty($this->config->resettables)) {
            $this->stateRollback->registerResetTargets($this->config->resettables);
        }

        $this->calculateEffectiveDriftLimit();
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

        if ($this->baselineMetrics === null) {
            $this->baselineMetrics = $this->initialMetrics;
        }

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

        [$txAudit, $fdAudit] = $this->auditGuardsAndRollback($additionalConnections);
        [$initialMetrics, $finalMetrics, $baselineRssMb, $currentDriftMb, $driftLimit] = $this->evaluateMemoryMetrics();
        [$shouldRecycle, $recycleReason, $cooldownActive] = $this->evaluateRecycleDecision($finalMetrics, $currentDriftMb, $driftLimit);

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
            baselineRssMb: $baselineRssMb,
            consecutiveViolationsCount: $this->consecutiveViolations,
            cooldownActive: $cooldownActive,
        );

        $this->lastReport = $report;
        $this->dispatchReportNotifications($report);

        return $report;
    }

    private function dispatchReportNotifications(Report $report): void
    {
        if ($this->config->logViolations && ! $report->isClean()) {
            $this->logViolation($report);
        }

        if ($this->config->onReport !== null) {
            ($this->config->onReport)($report);
        }

        if ($report->shouldRecycle && $this->config->autoRecycleOnViolation) {
            $this->triggerRecycle($report);
        }
    }

    /**
     * @param  array<int, PDO>  $additionalConnections
     * @return array{0: array{detected: bool, rolledBackCount: int, errors: array<int, string>}, 1: array{detected: bool, leakedCount: int, leakedDescriptors: array<int, string>}}
     */
    private function auditGuardsAndRollback(array $additionalConnections): array
    {
        $txAudit = $this->config->checkTransactions
            ? $this->transactionGuard->auditAndRollback($additionalConnections)
            : ['detected' => false, 'rolledBackCount' => 0, 'errors' => []];

        $fdAudit = $this->config->checkFileDescriptors
            ? $this->fileDescriptorGuard->audit($this->initialDescriptors)
            : ['detected' => false, 'leakedCount' => 0, 'leakedDescriptors' => []];

        $this->stateRollback->rollback();

        return [$txAudit, $fdAudit];
    }

    /**
     * @return array{0: ProcessMetrics, 1: ProcessMetrics, 2: float, 3: float, 4: float}
     */
    private function evaluateMemoryMetrics(): array
    {
        $finalMetrics = $this->statmParser->parse();
        $initialMetrics = $this->initialMetrics ?? $finalMetrics;
        $this->baselineMetrics ??= $initialMetrics;
        $baselineRssMb = $this->baselineMetrics->rssMb;

        $driftLimit = $this->effectiveDriftLimitMb ?? (float) ($this->config->maxDriftMb ?? 64);
        $currentDriftMb = round($finalMetrics->rssMb - $baselineRssMb, 2);

        $softDriftBreached = $this->isSoftDriftBreached($currentDriftMb, $driftLimit);
        $hardCeilingBreached = $this->isHardCeilingBreached($finalMetrics);

        if (($softDriftBreached || $hardCeilingBreached) && $this->config->triggerGcOnBreach) {
            $finalMetrics = $this->reEvaluateMetricsAfterGc();
            $currentDriftMb = round($finalMetrics->rssMb - $baselineRssMb, 2);
            $softDriftBreached = $this->isSoftDriftBreached($currentDriftMb, $driftLimit);
        }

        $this->consecutiveViolations = $softDriftBreached ? $this->consecutiveViolations + 1 : 0;

        return [$initialMetrics, $finalMetrics, $baselineRssMb, $currentDriftMb, $driftLimit];
    }

    private function isSoftDriftBreached(float $currentDriftMb, float $driftLimit): bool
    {
        return $this->config->maxDriftMb !== null && $currentDriftMb > $driftLimit;
    }

    private function isHardCeilingBreached(ProcessMetrics $finalMetrics): bool
    {
        return $this->config->maxRssMb !== null && $finalMetrics->rssMb > $this->config->maxRssMb;
    }

    private function reEvaluateMetricsAfterGc(): ProcessMetrics
    {
        gc_collect_cycles();

        return $this->statmParser->parse();
    }

    /**
     * @return array{0: bool, 1: string|null, 2: bool}
     */
    private function evaluateRecycleDecision(ProcessMetrics $finalMetrics, float $currentDriftMb, float $driftLimit): array
    {
        $now = microtime(true);

        if ($this->isHardCeilingBreached($finalMetrics)) {
            $this->recordRecycleTrigger($now);

            return [true, "Emergency RSS memory ceiling exceeded: {$finalMetrics->rssMb}MB > {$this->config->maxRssMb}MB", false];
        }

        if ($this->isDriftThresholdMet()) {
            if ($this->isCooldownThrottled($now)) {
                return [false, "Memory drift exceeded limit ({$currentDriftMb}MB > {$driftLimit}MB across {$this->consecutiveViolations} consecutive requests), but recycling is throttled by cooldown window ({$this->config->recycleCooldownSeconds}s).", true];
            }

            $this->recordRecycleTrigger($now);

            return [true, "Memory drift limit exceeded: {$currentDriftMb}MB > {$driftLimit}MB persistently across {$this->consecutiveViolations} consecutive requests", false];
        }

        if ($this->isMaxRequestsBreached()) {
            $this->recordRecycleTrigger($now);

            return [true, "Max requests ceiling reached: {$this->requestCount}/{$this->config->maxRequests}", false];
        }

        return [false, null, false];
    }

    private function isDriftThresholdMet(): bool
    {
        return $this->config->maxDriftMb !== null && $this->consecutiveViolations >= $this->config->consecutiveViolationsThreshold;
    }

    private function isCooldownThrottled(float $now): bool
    {
        return $this->lastRecycleTimestamp !== null && ($now - $this->lastRecycleTimestamp) < $this->config->recycleCooldownSeconds;
    }

    private function isMaxRequestsBreached(): bool
    {
        return $this->config->maxRequests !== null && $this->requestCount >= $this->config->maxRequests;
    }

    private function recordRecycleTrigger(float $now): void
    {
        $this->lastRecycleTimestamp = $now;
        $this->consecutiveViolations = 0;
    }

    /**
     * Capture the current process memory as the initial worker baseline.
     */
    public function captureBaselineMetrics(): ProcessMetrics
    {
        $metrics = $this->statmParser->parse();
        $this->baselineMetrics = $metrics;
        $this->calculateEffectiveDriftLimit();

        return $metrics;
    }

    /**
     * Explicitly set or update the baseline metrics for the worker.
     */
    public function setBaselineMetrics(?ProcessMetrics $metrics): self
    {
        $this->baselineMetrics = $metrics;
        $this->calculateEffectiveDriftLimit();

        return $this;
    }

    public function getBaselineMetrics(): ?ProcessMetrics
    {
        return $this->baselineMetrics;
    }

    public function getEffectiveDriftLimitMb(): ?float
    {
        return $this->effectiveDriftLimitMb;
    }

    public function getConsecutiveViolations(): int
    {
        return $this->consecutiveViolations;
    }

    public function resetConsecutiveViolations(): void
    {
        $this->consecutiveViolations = 0;
    }

    public function getLastRecycleTimestamp(): ?float
    {
        return $this->lastRecycleTimestamp;
    }

    public function setLastRecycleTimestamp(?float $timestamp): self
    {
        $this->lastRecycleTimestamp = $timestamp;

        return $this;
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

    public function getStateResetter(): Support\StateResetter
    {
        return $this->stateRollback->getStateResetter();
    }

    /**
     * Register a class string, object instance, or callable to be automatically reset at the end of each request.
     *
     * @param  class-string<object>|object|callable  $target
     */
    public function registerResetTarget(string|object|callable $target): self
    {
        $this->stateRollback->registerResetTarget($target);

        return $this;
    }

    /**
     * Register multiple targets to be automatically reset at the end of each request.
     *
     * @param  array<int, class-string<object>|object|callable>  $targets
     */
    public function registerResetTargets(array $targets): self
    {
        $this->stateRollback->registerResetTargets($targets);

        return $this;
    }

    public function resetRequestCount(): void
    {
        $this->requestCount = 0;
    }

    private function calculateEffectiveDriftLimit(): void
    {
        if ($this->config->maxDriftMb === null) {
            $this->effectiveDriftLimitMb = null;

            return;
        }

        $baseLimit = (float) $this->config->maxDriftMb;

        if ($this->config->driftJitterPercentage > 0) {
            $maxJitter = (int) round($baseLimit * ($this->config->driftJitterPercentage / 100));
            if ($maxJitter > 0) {
                $jitter = mt_rand(-$maxJitter, $maxJitter);
                $baseLimit = max(1.0, $baseLimit + $jitter);
            }
        }

        $this->effectiveDriftLimitMb = round($baseLimit, 2);
    }

    private function logViolation(Report $report): void
    {
        $messages = $this->buildViolationMessages($report);

        foreach ($messages as $message) {
            if ($this->config->logger !== null) {
                ($this->config->logger)($message, $report);
            } else {
                error_log($message);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function buildViolationMessages(Report $report): array
    {
        $messages = [];

        if ($report->danglingTransactionsDetected) {
            $messages[] = "[Leakless] 🚨 Dangling database transaction(s) detected and rolled back ({$report->danglingTransactionsCount} transaction(s)).";
        }

        if ($report->fileDescriptorsLeaked) {
            $details = implode(', ', array_map(fn ($fd, $target) => "#{$fd} ({$target})", array_keys($report->fileDescriptorsLeakedMap), $report->fileDescriptorsLeakedMap));
            $messages[] = "[Leakless] 🚨 Lingering file descriptor(s) detected ({$report->fileDescriptorsLeakedCount} descriptor(s)): {$details}";
        }

        $recycleMessage = $this->buildRecycleViolationMessage($report);
        if ($recycleMessage !== null) {
            $messages[] = $recycleMessage;
        }

        return $messages;
    }

    private function buildRecycleViolationMessage(Report $report): ?string
    {
        if ($report->cooldownActive && $report->recycleReason !== null) {
            return "[Leakless] ⏳ {$report->recycleReason}";
        }

        if ($report->shouldRecycle && $report->recycleReason !== null) {
            return "[Leakless] ⚠️ Worker recycling triggered. Reason: {$report->recycleReason}";
        }

        return null;
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
