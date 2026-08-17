<?php

declare(strict_types=1);

use Pest\Expectation;
use PHPUnit\Framework\Assert;
use TheMattos\Leakless\Dev\Support\ClassLeakInspector;
use TheMattos\Leakless\Leakless;

if (function_exists('expect')) {
    /**
     * Asserts that a class or object has no mutable static properties or ephemeral constructor injections.
     */
    expect()->extend('toBeLeakless', function (): Expectation {
        $target = $this->value;

        if (! is_object($target) && (! is_string($target) || ! class_exists($target))) {
            Assert::fail('Expected value must be a valid class-string or an object.');
        }

        /** @var class-string|object $target */
        $inspector = new ClassLeakInspector;
        $violations = $inspector->inspect($target);

        Assert::assertEmpty(
            $violations,
            sprintf(
                "Failed asserting that [%s] is leakless:\n- %s",
                is_object($target) ? $target::class : $target,
                implode("\n- ", $violations),
            ),
        );

        return $this;
    });

    /**
     * Asserts that a closure executes with the Leakless lifecycle manager without state pollution or excessive memory drift.
     */
    expect()->extend('toRunCleanly', function (?float $maxDriftMb = null): Expectation {
        $callable = $this->value;

        if (! is_callable($callable)) {
            Assert::fail('Expected value must be a callable closure.');
        }

        $guardian = new Leakless;
        $guardian->startRequest();

        try {
            $callable();
        } finally {
            $report = $guardian->endRequest();
        }

        Assert::assertTrue(
            $report->isClean(),
            sprintf(
                'Failed asserting that closure ran cleanly. Dangling transactions: %d, Should recycle: %s',
                $report->danglingTransactionsCount,
                $report->shouldRecycle ? 'true' : 'false',
            ),
        );

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

        return $this;
    });
}
