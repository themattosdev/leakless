<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Support;

use TheMattos\Leakless\DTOs\ProcessMetrics;

final class ProcStatmParser
{
    private const DEFAULT_PAGE_SIZE = 4096;

    public function __construct(
        private readonly string $statmPath = '/proc/self/statm',
    ) {}

    /**
     * Parse /proc/self/statm contents into a ProcessMetrics DTO.
     */
    public function parse(?string $rawContent = null): ProcessMetrics
    {
        $content = $rawContent ?? $this->readStatm();

        if ($content === null || trim($content) === '') {
            return ProcessMetrics::fallback();
        }

        $parts = preg_split('/\s+/', trim($content));

        if ($parts === false || count($parts) < 3) {
            return ProcessMetrics::fallback();
        }

        $totalProgramPages = (int) $parts[0];
        $residentPages = (int) $parts[1];
        $sharedPages = (int) $parts[2];

        $pageSize = $this->detectPageSize();

        return ProcessMetrics::fromPages(
            totalProgramPages: $totalProgramPages,
            residentPages: $residentPages,
            sharedPages: $sharedPages,
            pageSize: $pageSize,
        );
    }

    /**
     * Check if /proc/self/statm is accessible in the current OS environment.
     */
    public function isLinuxProcAvailable(): bool
    {
        return @file_exists($this->statmPath) && @is_readable($this->statmPath);
    }

    private function readStatm(): ?string
    {
        if (! $this->isLinuxProcAvailable()) {
            return null;
        }

        $content = @file_get_contents($this->statmPath);

        return $content !== false ? $content : null;
    }

    private function detectPageSize(): int
    {
        if (function_exists('posix_sysconf') && defined('POSIX_SC_PAGESIZE')) {
            $pageSize = @posix_sysconf(POSIX_SC_PAGESIZE);
            if (is_int($pageSize) && $pageSize > 0) {
                return $pageSize;
            }
        }

        return self::DEFAULT_PAGE_SIZE;
    }
}
