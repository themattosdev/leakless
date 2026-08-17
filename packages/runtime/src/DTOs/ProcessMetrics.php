<?php

declare(strict_types=1);

namespace TheMattos\Leakless\DTOs;

final readonly class ProcessMetrics
{
    public function __construct(
        public int $rssBytes,
        public float $rssMb,
        public int $virtualBytes,
        public float $virtualMb,
        public int $sharedBytes,
        public float $sharedMb,
        public int $zendMemoryUsageBytes,
        public float $zendMemoryUsageMb,
        public int $zendMemoryPeakBytes,
        public float $zendMemoryPeakMb,
    ) {}

    /**
     * Factory to build metrics from raw /proc/self/statm page counts.
     */
    public static function fromPages(
        int $totalProgramPages,
        int $residentPages,
        int $sharedPages,
        int $pageSize = 4096,
    ): self {
        $rssBytes = $residentPages * $pageSize;
        $virtualBytes = $totalProgramPages * $pageSize;
        $sharedBytes = $sharedPages * $pageSize;

        $zendUsage = memory_get_usage(false);
        $zendPeak = memory_get_peak_usage(true);

        return new self(
            rssBytes: $rssBytes,
            rssMb: round($rssBytes / (1024 * 1024), 2),
            virtualBytes: $virtualBytes,
            virtualMb: round($virtualBytes / (1024 * 1024), 2),
            sharedBytes: $sharedBytes,
            sharedMb: round($sharedBytes / (1024 * 1024), 2),
            zendMemoryUsageBytes: $zendUsage,
            zendMemoryUsageMb: round($zendUsage / (1024 * 1024), 2),
            zendMemoryPeakBytes: $zendPeak,
            zendMemoryPeakMb: round($zendPeak / (1024 * 1024), 2),
        );
    }

    /**
     * Fallback factory for non-Linux platforms where /proc/self/statm is unavailable.
     */
    public static function fallback(): self
    {
        $realUsage = memory_get_usage(true);
        $realPeak = memory_get_peak_usage(true);
        $allocUsage = memory_get_usage(false);

        return new self(
            rssBytes: $realUsage,
            rssMb: round($realUsage / (1024 * 1024), 2),
            virtualBytes: $realPeak,
            virtualMb: round($realPeak / (1024 * 1024), 2),
            sharedBytes: 0,
            sharedMb: 0.0,
            zendMemoryUsageBytes: $allocUsage,
            zendMemoryUsageMb: round($allocUsage / (1024 * 1024), 2),
            zendMemoryPeakBytes: $realPeak,
            zendMemoryPeakMb: round($realPeak / (1024 * 1024), 2),
        );
    }
}
