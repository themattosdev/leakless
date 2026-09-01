<?php

declare(strict_types=1);

namespace Tests\Unit\Dev\Concerns;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use TheMattos\Leakless\Attributes\AllowPersistentState;
use TheMattos\Leakless\Dev\Concerns\InteractsWithLeakless;
use TheMattos\Leakless\DTOs\ProcessMetrics;
use TheMattos\Leakless\DTOs\Report;

final class InteractsWithLeaklessTest extends TestCase
{
    use InteractsWithLeakless;

    public function test_it_passes_for_clean_class(): void
    {
        $cleanClass = new class
        {
            public const VERSION = '1.0';

            public function doSomething(): void {}
        };

        $this->assertIsLeakless($cleanClass);
    }

    public function test_it_passes_for_class_with_allow_persistent_state_attribute(): void
    {
        $attributedClass = new #[AllowPersistentState] class
        {
            /** @var array<string, mixed> */
            public static array $cache = [];
        };

        $this->assertIsLeakless($attributedClass);
    }

    public function test_it_fails_for_class_with_mutable_static_property(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Mutable static property');

        $leakyClass = new class
        {
            /** @var array<string, mixed> */
            public static array $dirtyCache = [];
        };

        $this->assertIsLeakless($leakyClass);
    }

    public function test_it_asserts_runs_cleanly_for_clean_callback(): void
    {
        $report = $this->assertRunsCleanly(function (): void {
            $x = 1 + 1;
        });

        $this->assertTrue($report->isClean());
    }

    public function test_it_asserts_no_dangling_transactions_on_clean_report(): void
    {
        $report = new Report(
            initialMetrics: ProcessMetrics::fallback(),
            finalMetrics: ProcessMetrics::fallback(),
            durationMs: 1.5,
            danglingTransactionsDetected: false,
        );

        $this->assertNoDanglingTransactions($report);
        $this->assertCleanWorkerState($report);
        $this->assertNoMemoryDrift($report, maxAllowedMb: 0.25);
    }

    public function test_it_fails_assert_no_dangling_transactions_when_transactions_leak(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Dangling database transaction');

        $report = new Report(
            initialMetrics: ProcessMetrics::fallback(),
            finalMetrics: ProcessMetrics::fallback(),
            durationMs: 1.5,
            danglingTransactionsDetected: true,
            danglingTransactionsCount: 2,
        );

        $this->assertNoDanglingTransactions($report);
    }

    public function test_it_fails_when_target_is_invalid_in_assert_is_leakless(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected value must be a valid class-string or an object.');

        $this->assertIsLeakless('NonExistentClassString');
    }

    public function test_it_fails_when_container_target_has_no_instances(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No valid object instances or container singletons found');

        $this->assertResetsContainerState([], fn () => null);
    }

    public function test_it_asserts_stateless_instances_successfully(): void
    {
        $cleanService = new class
        {
            public string $name = 'initial';
        };

        $this->assertStatelessInstances($cleanService, function () {
            // pure logic without mutating $cleanService
        });
    }
}

