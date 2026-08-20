<?php

declare(strict_types=1);

use Pest\Expectation;
use PHPUnit\Framework\Assert;
use TheMattos\Leakless\Dev\PHPUnit\AssertsLeakless;

if (function_exists('expect')) {
    /**
     * Asserts via reflection that a class or object has no mutable static properties or ephemeral constructor injections.
     *
     * Validates structural class design (bans mutable `static $prop` and request-scoped injections in singletons).
     * To test runtime lifecycle and memory drift, combine with `toRunCleanly()`.
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
     * Asserts that a closure executes within the Leakless request guard without state pollution or excessive memory drift.
     *
     * Validates runtime execution (checks uncommitted PDO transactions, unclosed file descriptors, and Linux RSS memory drift).
     * To verify structural class hygiene, combine with `toBeLeakless()`.
     */
    expect()->extend('toRunCleanly', function (?float $maxDriftMb = null): Expectation {
        $callable = $this->value;

        if (! is_callable($callable)) {
            Assert::fail('Expected value must be a callable closure.');
        }

        AssertsLeakless::assertRunsCleanly($callable, maxDriftMb: $maxDriftMb);

        return $this;
    });

    /**
     * Asserts that target objects, arrays of objects, or container singletons maintain clean state across callback execution.
     *
     * Takes deep pre/post property snapshots of object instances to detect dynamic runtime mutations (e.g. `$this->bag[$key] = $val`).
     * To check structural static properties or constructor injections, use `toBeLeakless()`.
     */
    expect()->extend('toResetContainerState', function (callable $callback, int $maxDepth = 4): Expectation {
        $target = $this->value;

        AssertsLeakless::assertResetsContainerState($target, $callback, maxDepth: $maxDepth);

        return $this;
    });

    /**
     * Alias for toResetContainerState().
     */
    expect()->extend('toHaveStatelessInstances', function (callable $callback, int $maxDepth = 4): Expectation {
        $target = $this->value;

        AssertsLeakless::assertStatelessInstances($target, $callback, maxDepth: $maxDepth);

        return $this;
    });
}
