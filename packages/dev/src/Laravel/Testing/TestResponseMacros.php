<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Dev\Laravel\Testing;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;
use TheMattos\Leakless\DTOs\Report;
use TheMattos\Leakless\Leakless;

final class TestResponseMacros
{
    /**
     * Register TestResponse macros for Laravel testing.
     */
    public static function register(): void
    {
        if (! class_exists(TestResponse::class)) {
            return;
        }

        TestResponse::macro('assertNoDanglingTransactions', function (): TestResponse {
            /** @var TestResponse<Response> $this */
            $guardian = app(Leakless::class);
            $report = $guardian->getLastReport();

            if ($report instanceof Report) {
                Assert::assertFalse(
                    $report->danglingTransactionsDetected,
                    sprintf('Dangling database transaction(s) detected: %d transaction(s) were uncommitted.', $report->danglingTransactionsCount),
                );
            }

            return $this;
        });

        TestResponse::macro('assertNoMemoryDrift', function (float $maxAllowedMb = 0.25): TestResponse {
            /** @var TestResponse<Response> $this */
            $guardian = app(Leakless::class);
            $report = $guardian->getLastReport();

            if ($report instanceof Report) {
                Assert::assertLessThanOrEqual(
                    $maxAllowedMb,
                    $report->memoryDriftMb,
                    sprintf('Memory drift [%.2fMB] exceeded the maximum allowed drift of [%.2fMB].', $report->memoryDriftMb, $maxAllowedMb),
                );
            }

            return $this;
        });

        TestResponse::macro('assertCleanWorkerState', function (): TestResponse {
            /** @var TestResponse<Response> $this */
            $guardian = app(Leakless::class);
            $report = $guardian->getLastReport();

            if ($report instanceof Report) {
                Assert::assertTrue(
                    $report->isClean(),
                    sprintf('Worker state is dirty after request. Reason: %s', $report->recycleReason ?? 'Unknown violation'),
                );
            }

            return $this;
        });
    }
}
