<?php

declare(strict_types=1);

use Pest\Expectation;
use PHPUnit\Framework\Assert;
use TheMattos\Leakless\Dev\PHPUnit\AssertsLeakless;

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
        AssertsLeakless::assertIsLeakless($target);

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

        AssertsLeakless::assertRunsCleanly($callable, maxDriftMb: $maxDriftMb);

        return $this;
    });
}
