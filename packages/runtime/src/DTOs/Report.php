<?php

declare(strict_types=1);

namespace TheMattos\Leakless\DTOs;

final readonly class Report
{
    public float $memoryDriftMb;

    public float $zendMemoryDriftMb;

    public float $driftOverBaselineMb;

    /**
     * @param  ProcessMetrics  $initialMetrics  Metrics captured at the start of the request.
     * @param  ProcessMetrics  $finalMetrics  Metrics captured after the response is sent.
     * @param  float  $durationMs  Execution duration of the request in milliseconds.
     * @param  bool  $danglingTransactionsDetected  True if orphaned database transactions were detected.
     * @param  int  $danglingTransactionsCount  Number of dangling transactions rolled back.
     * @param  array<int, array<string, mixed>>  $danglingTransactionBacktraces  Backtrace details for orphaned transactions.
     * @param  bool  $fileDescriptorsLeaked  True if lingering file descriptors or open sockets were detected.
     * @param  int  $fileDescriptorsLeakedCount  Number of leaked file descriptors.
     * @param  array<int, string>  $fileDescriptorsLeakedMap  Map of leaked descriptors [fd => targetPath].
     * @param  bool  $shouldRecycle  True if the worker must be gracefully restarted.
     * @param  string|null  $recycleReason  Human-readable explanation if recycling was requested.
     * @param  array<string, mixed>  $metadata  Arbitrary context (e.g. route, method, status code).
     * @param  float|null  $baselineRssMb  Baseline memory measured during worker initialization.
     * @param  int  $consecutiveViolationsCount  Number of consecutive memory drift breaches detected.
     * @param  bool  $cooldownActive  True if worker recycling was throttled due to cooldown window.
     */
    public function __construct(
        public ProcessMetrics $initialMetrics,
        public ProcessMetrics $finalMetrics,
        public float $durationMs,
        public bool $danglingTransactionsDetected = false,
        public int $danglingTransactionsCount = 0,
        public array $danglingTransactionBacktraces = [],
        public bool $fileDescriptorsLeaked = false,
        public int $fileDescriptorsLeakedCount = 0,
        public array $fileDescriptorsLeakedMap = [],
        public bool $shouldRecycle = false,
        public ?string $recycleReason = null,
        public array $metadata = [],
        public ?float $baselineRssMb = null,
        public int $consecutiveViolationsCount = 0,
        public bool $cooldownActive = false,
    ) {
        $this->memoryDriftMb = round($this->finalMetrics->rssMb - $this->initialMetrics->rssMb, 2);
        $this->zendMemoryDriftMb = round($this->finalMetrics->zendMemoryUsageMb - $this->initialMetrics->zendMemoryUsageMb, 2);
        $this->driftOverBaselineMb = $this->baselineRssMb !== null
            ? round($this->finalMetrics->rssMb - $this->baselineRssMb, 2)
            : $this->memoryDriftMb;
    }

    /**
     * Determine if the request cycle finished in a completely clean, non-leaking state.
     */
    public function isClean(): bool
    {
        return ! $this->shouldRecycle
            && ! $this->cooldownActive
            && ! $this->danglingTransactionsDetected
            && ! $this->fileDescriptorsLeaked;
    }
}
