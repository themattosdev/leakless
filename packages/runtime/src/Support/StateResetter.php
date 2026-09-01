<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Support;

use Closure;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use TheMattos\Leakless\Attributes\ResetOnRequest;

/**
 * High-performance state reset engine for persistent runtime environments.
 *
 * Implements a "Zero Reflection in Hot Path" model:
 * - Reflection runs ONCE during warmup / registration (`registerTarget`).
 * - Generates pre-compiled native closures.
 * - `resetAll()` in `endRequest()` executes purely compiled closures with zero reflection overhead.
 */
final class StateResetter
{
    /**
     * @var array<int, class-string<object>|object|callable>
     */
    private array $targets = [];

    /**
     * Pre-compiled zero-reflection reset closures.
     *
     * @var array<int, Closure(): void>
     */
    private array $compiledResetters = [];

    /**
     * Static cache of compiled closures metadata per class.
     *
     * @var array<string, array<int, Closure(object|null): void>>
     */
    private static array $classPlanCache = [];

    /**
     * Register a class string, object instance, or callable to be reset on request completion.
     * Compiles the execution plan immediately during registration (warmup).
     *
     * @param  class-string<object>|object|callable  $target
     */
    public function registerTarget(string|object|callable $target): void
    {
        if (in_array($target, $this->targets, true)) {
            return;
        }

        $this->targets[] = $target;
        $this->compileTargetClosures($target);
    }

    /**
     * Register multiple targets at once.
     *
     * @param  array<int, class-string<object>|object|callable>  $targets
     */
    public function registerTargets(array $targets): void
    {
        foreach ($targets as $target) {
            $this->registerTarget($target);
        }
    }

    /**
     * Reset all registered targets using pre-compiled closures (Zero Reflection in Hot Path).
     */
    public function resetAll(): void
    {
        foreach ($this->compiledResetters as $resetter) {
            $resetter();
        }
    }

    /**
     * Clear registered targets and compiled closures.
     */
    public function clearTargets(): void
    {
        $this->targets = [];
        $this->compiledResetters = [];
    }

    /**
     * Clear static class plan cache (useful for testing).
     */
    public static function clearCache(): void
    {
        self::$classPlanCache = [];
    }

    /**
     * Inspect and reset a single target immediately.
     *
     * @param  class-string<object>|object|callable  $target
     */
    public function resetTarget(string|object|callable $target): void
    {
        $closures = $this->resolveTargetClosures($target);
        foreach ($closures as $closure) {
            $closure();
        }
    }

    /**
     * @param  class-string<object>|object|callable  $target
     */
    private function compileTargetClosures(string|object|callable $target): void
    {
        $closures = $this->resolveTargetClosures($target);
        foreach ($closures as $closure) {
            $this->compiledResetters[] = $closure;
        }
    }

    /**
     * @param  class-string<object>|object|callable  $target
     * @return array<int, Closure(): void>
     */
    private function resolveTargetClosures(string|object|callable $target): array
    {
        if (is_callable($target) && ($target instanceof Closure || is_array($target) || is_string($target))) {
            if ($target instanceof Closure) {
                return [$target];
            }

            return [Closure::fromCallable($target)];
        }

        if (is_string($target) && ! class_exists($target)) {
            return [];
        }

        /** @var class-string<object>|object $target */
        $className = is_object($target) ? $target::class : $target;
        $plan = $this->resolveClassPlan($className);
        $boundClosures = [];

        $objectInstance = is_object($target) ? $target : null;

        foreach ($plan as $closure) {
            $boundClosures[] = static function () use ($closure, $objectInstance): void {
                $closure($objectInstance);
            };
        }

        return $boundClosures;
    }

    /**
     * @param  class-string<object>  $className
     * @return array<int, Closure(object|null): void>
     */
    private function resolveClassPlan(string $className): array
    {
        if (isset(self::$classPlanCache[$className])) {
            return self::$classPlanCache[$className];
        }

        $reflection = new ReflectionClass($className);
        $plan = [];

        $classResetAttr = $this->getClassResetAttribute($reflection);

        // 1. Process Class-level #[ResetOnRequest(resetter: '...')] or conventional reset methods
        $this->compileClassLevelPlan($reflection, $classResetAttr, $plan);

        // 2. Process Method-level #[ResetOnRequest]
        $this->compileMethodLevelPlan($reflection, $plan);

        // 3. Process Property-level #[ResetOnRequest] or class-level blanket reset
        $this->compilePropertyLevelPlan($reflection, $classResetAttr, $plan);

        self::$classPlanCache[$className] = $plan;

        return $plan;
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @param  array<int, Closure(object|null): void>  $plan
     */
    private function compileClassLevelPlan(ReflectionClass $reflection, ?ResetOnRequest $attribute, array &$plan): void
    {
        if ($attribute !== null && $attribute->resetter !== null && $reflection->hasMethod($attribute->resetter)) {
            $method = $reflection->getMethod($attribute->resetter);
            $plan[] = $this->compileMethodClosure($method);

            return;
        }

        // Conventional method names if no explicit resetter was annotated
        foreach (['reset', 'resetState', 'cleanup'] as $conventionalMethod) {
            if ($reflection->hasMethod($conventionalMethod)) {
                $method = $reflection->getMethod($conventionalMethod);
                if ($method->isPublic() && $method->getNumberOfRequiredParameters() === 0) {
                    $plan[] = $this->compileMethodClosure($method);
                    break;
                }
            }
        }
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @param  array<int, Closure(object|null): void>  $plan
     */
    private function compileMethodLevelPlan(ReflectionClass $reflection, array &$plan): void
    {
        foreach ($reflection->getMethods() as $method) {
            if ($this->hasResetAttribute($method)) {
                $plan[] = $this->compileMethodClosure($method);
            }
        }
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @param  array<int, Closure(object|null): void>  $plan
     */
    private function compilePropertyLevelPlan(ReflectionClass $reflection, ?ResetOnRequest $classResetAttr, array &$plan): void
    {
        foreach ($reflection->getProperties() as $property) {
            $propAttribute = $this->getPropertyResetAttribute($property);

            // Property has its own #[ResetOnRequest] or class has #[ResetOnRequest] without a custom resetter method
            if ($propAttribute === null && ($classResetAttr === null || $classResetAttr->resetter !== null)) {
                continue;
            }

            if ($propAttribute !== null && $propAttribute->resetter !== null && $reflection->hasMethod($propAttribute->resetter)) {
                $plan[] = $this->compileMethodClosure($reflection->getMethod($propAttribute->resetter));

                continue;
            }

            $defaultValue = $this->resolvePropertyDefaultValue($property, $propAttribute);
            $plan[] = $this->compilePropertyClosure($property, $defaultValue);
        }
    }

    private function resolvePropertyDefaultValue(ReflectionProperty $property, ?ResetOnRequest $attribute): mixed
    {
        if ($attribute !== null && $attribute->default !== null) {
            return $attribute->default;
        }

        if ($property->hasDefaultValue()) {
            return $property->getDefaultValue();
        }

        return null;
    }

    /**
     * @return Closure(object|null): void
     */
    private function compileMethodClosure(ReflectionMethod $method): Closure
    {
        $methodName = $method->getName();

        if ($method->isStatic()) {
            $declaringClass = $method->getDeclaringClass()->getName();

            return static function () use ($declaringClass, $methodName): void {
                $declaringClass::$methodName();
            };
        }

        return static function (?object $instance) use ($methodName): void {
            if ($instance !== null) {
                $instance->$methodName();
            }
        };
    }

    /**
     * @return Closure(object|null): void
     */
    private function compilePropertyClosure(ReflectionProperty $property, mixed $defaultValue): Closure
    {
        if ($property->isStatic()) {
            return static function () use ($property, $defaultValue): void {
                $property->setValue(null, $defaultValue);
            };
        }

        return static function (?object $instance) use ($property, $defaultValue): void {
            if ($instance !== null) {
                $property->setValue($instance, $defaultValue);
            }
        };
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     */
    private function getClassResetAttribute(ReflectionClass $reflection): ?ResetOnRequest
    {
        $attrs = $reflection->getAttributes(ResetOnRequest::class);
        if (! empty($attrs)) {
            /** @var ResetOnRequest */
            return $attrs[0]->newInstance();
        }

        return null;
    }

    private function getPropertyResetAttribute(ReflectionProperty $property): ?ResetOnRequest
    {
        $attrs = $property->getAttributes(ResetOnRequest::class);
        if (! empty($attrs)) {
            /** @var ResetOnRequest */
            return $attrs[0]->newInstance();
        }

        return null;
    }

    private function hasResetAttribute(ReflectionMethod $method): bool
    {
        return ! empty($method->getAttributes(ResetOnRequest::class));
    }
}
