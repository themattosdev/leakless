<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Dev\PHPUnit;

use Closure;
use PHPUnit\Framework\Assert;
use TheMattos\Leakless\Dev\State\ObjectStateSnapshotter;
use TheMattos\Leakless\Dev\State\StateMutation;
use TheMattos\Leakless\Dev\Support\ClassLeakInspector;
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\DTOs\Report;
use TheMattos\Leakless\Leakless;

/**
 * Trait providing PHPUnit assertions for validating persistent worker safety and state hygiene.
 */
trait AssertsLeakless
{
    /**
     * Asserts that object instances or container singletons maintain clean/stateless properties across callback executions.
     *
     * Takes deep pre/post property snapshots of object instances to detect dynamic runtime mutations (e.g. `$this->bag[$key] = $val`).
     * To check structural static properties or constructor injections, use `assertIsLeakless()`.
     *
     * @param  mixed  $target  An object, array of objects, generic PSR-11 container, or Laravel Application ($app)
     * @param  callable|Closure  $callback  The execution payload or request simulation
     * @param  int  $maxDepth  Maximum recursion depth for nested object inspection (default: 4)
     */
    public static function assertResetsContainerState(
        mixed $target,
        callable|Closure $callback,
        string $message = '',
        int $maxDepth = 4,
    ): void {
        $snapshotter = new ObjectStateSnapshotter;
        $instances = $snapshotter->extractInstances($target);

        if (count($instances) === 0) {
            Assert::fail('No valid object instances or container singletons found to snapshot.');
        }

        $before = $snapshotter->snapshot($instances, maxDepth: $maxDepth);

        $callback();

        $after = $snapshotter->snapshot($instances, maxDepth: $maxDepth);
        $mutations = $snapshotter->compare($instances, $before, $after);

        $diffs = array_map(fn (StateMutation $m) => $m->toFormattedString(), $mutations);
        $failureMsg = $message !== '' ? $message : sprintf(
            "Failed asserting that container instances maintained clean state:\n%s",
            implode("\n", $diffs),
        );

        Assert::assertEmpty($mutations, $failureMsg);
    }

    /**
     * Alias for assertResetsContainerState().
     *
     * @param  mixed  $target  An object, array of objects, generic PSR-11 container, or Laravel Application ($app)
     * @param  callable|Closure  $callback  The execution payload or request simulation
     * @param  int  $maxDepth  Maximum recursion depth for nested object inspection (default: 4)
     */
    public static function assertStatelessInstances(
        mixed $target,
        callable|Closure $callback,
        string $message = '',
        int $maxDepth = 4,
    ): void {
        self::assertResetsContainerState($target, $callback, $message, maxDepth: $maxDepth);
    }

    /**
     * Asserts via reflection that a class or object has no mutable static properties or ephemeral constructor injections.
     *
     * Validates structural class design (bans mutable `static $prop` and request-scoped injections in singletons).
     * To test runtime lifecycle and memory drift, combine with `assertRunsCleanly()`.
     *
     * @param  class-string|object  $target
     */
    public static function assertIsLeakless(object|string $target, string $message = ''): void
    {
        if (! is_object($target) && ! class_exists($target)) {
            Assert::fail('Expected value must be a valid class-string or an object.');
        }

        $inspector = new ClassLeakInspector;
        $violations = $inspector->inspect($target);

        $failureMsg = $message !== '' ? $message : sprintf(
            "Failed asserting that [%s] is leakless:\n- %s",
            is_object($target) ? $target::class : $target,
            implode("\n- ", $violations),
        );

        Assert::assertEmpty($violations, $failureMsg);
    }

    /**
     * Asserts that a closure executes within the Leakless lifecycle manager without state pollution or excessive memory drift.
     *
     * Validates runtime execution (checks uncommitted PDO transactions, unclosed file descriptors, and Linux RSS memory drift).
     * To verify structural class hygiene, combine with `assertIsLeakless()`.
     */
    public static function assertRunsCleanly(
        callable|Closure $callback,
        ?Config $config = null,
        ?float $maxDriftMb = null,
        string $message = '',
    ): Report {
        $guardian = new Leakless($config);
        $guardian->startRequest();

        try {
            $callback();
        } finally {
            $report = $guardian->endRequest();
        }

        $cleanMsg = $message !== '' ? $message : sprintf(
            'Failed asserting that callback ran cleanly. Dangling transactions: %d, Should recycle: %s, File descriptors leaked: %d',
            $report->danglingTransactionsCount,
            $report->shouldRecycle ? 'true' : 'false',
            $report->fileDescriptorsLeakedCount,
        );

        Assert::assertTrue($report->isClean(), $cleanMsg);

        if ($maxDriftMb !== null) {
            Assert::assertLessThanOrEqual(
                $maxDriftMb,
                $report->memoryDriftMb,
                sprintf(
                    'Memory drift [%.2fMB] exceeded the maximum allowed drift of [%.2fMB].',
                    $report->memoryDriftMb,
                    $maxDriftMb,
                ),
            );
        }

        return $report;
    }

    /**
     * Asserts that a Report or Laravel TestResponse did not leave uncommitted PDO transactions.
     */
    public static function assertNoDanglingTransactions(mixed $target, string $message = ''): void
    {
        $report = self::resolveReportFromTarget($target);

        if ($report !== null) {
            Assert::assertFalse(
                $report->danglingTransactionsDetected,
                $message !== '' ? $message : sprintf(
                    'Dangling database transaction(s) detected: %d transaction(s) were uncommitted.',
                    $report->danglingTransactionsCount,
                ),
            );
        }
    }

    /**
     * Asserts that a Report or Laravel TestResponse finished in a completely clean worker state.
     */
    public static function assertCleanWorkerState(mixed $target, string $message = ''): void
    {
        $report = self::resolveReportFromTarget($target);

        if ($report !== null) {
            Assert::assertTrue(
                $report->isClean(),
                $message !== '' ? $message : sprintf(
                    'Worker state is dirty after request. Reason: %s',
                    $report->recycleReason ?? 'State corruption or memory limit exceeded',
                ),
            );
        }
    }

    /**
     * Asserts that a Report or Laravel TestResponse did not exceed the allowed memory drift.
     */
    public static function assertNoMemoryDrift(mixed $target, float $maxAllowedMb = 0.25, string $message = ''): void
    {
        $report = self::resolveReportFromTarget($target);

        if ($report !== null) {
            Assert::assertLessThanOrEqual(
                $maxAllowedMb,
                $report->memoryDriftMb,
                $message !== '' ? $message : sprintf(
                    'Memory drift [%.2fMB] exceeded the maximum allowed drift of [%.2fMB].',
                    $report->memoryDriftMb,
                    $maxAllowedMb,
                ),
            );
        }
    }

    private static function resolveReportFromTarget(mixed $target): ?Report
    {
        if ($target instanceof Report) {
            return $target;
        }

        if (function_exists('app')) {
            try {
                if (app()->bound(Leakless::class)) {
                    /** @var Leakless $guardian */
                    $guardian = app(Leakless::class);

                    return $guardian->getLastReport();
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
