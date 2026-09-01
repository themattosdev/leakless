<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Dev\Concerns;

use Closure;
use TheMattos\Leakless\Dev\PHPUnit\LeaklessAssert;
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\DTOs\Report;

/**
 * Trait providing instance assertion methods for PHPUnit TestCase classes.
 */
trait InteractsWithLeakless
{
    public function assertResetsContainerState(
        mixed $target,
        callable|Closure $callback,
        string $message = '',
        int $maxDepth = 4,
    ): void {
        LeaklessAssert::assertResetsContainerState($target, $callback, $message, maxDepth: $maxDepth);
    }

    public function assertStatelessInstances(
        mixed $target,
        callable|Closure $callback,
        string $message = '',
        int $maxDepth = 4,
    ): void {
        LeaklessAssert::assertStatelessInstances($target, $callback, $message, maxDepth: $maxDepth);
    }

    /**
     * @param  class-string|object  $target
     */
    public function assertIsLeakless(object|string $target, string $message = ''): void
    {
        /** @var class-string|object $target */
        LeaklessAssert::assertIsLeakless($target, $message);
    }

    public function assertRunsCleanly(
        callable|Closure $callback,
        ?Config $config = null,
        ?float $maxDriftMb = null,
        string $message = '',
    ): Report {
        return LeaklessAssert::assertRunsCleanly($callback, $config, $maxDriftMb, $message);
    }

    public function assertNoDanglingTransactions(mixed $target, string $message = ''): void
    {
        LeaklessAssert::assertNoDanglingTransactions($target, $message);
    }

    public function assertCleanWorkerState(mixed $target, string $message = ''): void
    {
        LeaklessAssert::assertCleanWorkerState($target, $message);
    }

    public function assertNoMemoryDrift(mixed $target, float $maxAllowedMb = 0.25, string $message = ''): void
    {
        LeaklessAssert::assertNoMemoryDrift($target, $maxAllowedMb, $message);
    }
}
