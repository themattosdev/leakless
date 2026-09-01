<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Dev\State;

use Closure;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use SplObjectStorage;
use TheMattos\Leakless\Attributes\AllowPersistentState;

final class ObjectStateSnapshotter
{
    public const DEFAULT_MAX_DEPTH = 4;

    /**
     * Extracts target object instances from vanilla objects, lists, or containers.
     *
     * @return array<int, object>
     */
    public function extractInstances(mixed $target): array
    {
        if (is_object($target) && ! ($target instanceof ContainerInterface)) {
            return [$target];
        }

        if (is_array($target)) {
            $instances = [];
            foreach ($target as $item) {
                if (is_object($item)) {
                    $instances[] = $item;
                }
            }

            return $instances;
        }

        if ($target instanceof ContainerInterface) {
            return $this->extractFromContainer($target);
        }

        return [];
    }

    /**
     * Takes a state snapshot of the given objects.
     *
     * @param  array<int, object>  $instances
     * @param  int  $maxDepth  Maximum recursion depth for nested object inspection (default: 4)
     * @return array<string, array<string, mixed>> Keyed by spl_object_hash
     */
    public function snapshot(array $instances, int $maxDepth = self::DEFAULT_MAX_DEPTH): array
    {
        $snapshot = [];

        foreach ($instances as $instance) {
            /** @var SplObjectStorage<object, bool> $visited */
            $visited = new SplObjectStorage;
            $id = $this->getInstanceIdentifier($instance);
            $snapshot[$id] = $this->captureObjectState($instance, $visited, 0, $maxDepth);
        }

        return $snapshot;
    }

    /**
     * Compares pre and post execution snapshots and detects property mutations.
     *
     * @param  array<int, object>  $instances
     * @param  array<string, array<string, mixed>>  $before
     * @param  array<string, array<string, mixed>>  $after
     * @return array<int, StateMutation>
     */
    public function compare(array $instances, array $before, array $after): array
    {
        $mutations = [];

        foreach ($instances as $instance) {
            $id = $this->getInstanceIdentifier($instance);
            $beforeProps = $before[$id] ?? [];
            $afterProps = $after[$id] ?? [];

            $allKeys = array_unique(array_merge(array_keys($beforeProps), array_keys($afterProps)));

            foreach ($allKeys as $propKey) {
                $valBefore = $beforeProps[$propKey] ?? null;
                $valAfter = $afterProps[$propKey] ?? null;

                if (! $this->areValuesEqual($valBefore, $valAfter)) {
                    $mutations[] = new StateMutation(
                        className: $instance::class,
                        propertyName: $propKey,
                        beforeValue: $valBefore,
                        afterValue: $valAfter,
                    );
                }
            }
        }

        return $mutations;
    }

    /**
     * @param  SplObjectStorage<object, bool>  $visited
     * @return array<string, mixed>
     */
    private function captureObjectState(object $object, SplObjectStorage $visited, int $depth, int $maxDepth): array
    {
        if ($depth > $maxDepth || $visited->contains($object)) {
            return ['__recursion__' => sprintf('object(%s#%d)', $object::class, spl_object_id($object))];
        }

        $visited->attach($object, true);
        $state = [];

        $reflection = new ReflectionClass($object);
        if (count($reflection->getAttributes(AllowPersistentState::class)) > 0) {
            return [];
        }

        $currentClass = $reflection;
        while ($currentClass !== false) {
            $this->captureClassProperties($object, $reflection, $currentClass, $visited, $depth, $maxDepth, $state);
            $currentClass = $currentClass->getParentClass();
        }

        return $state;
    }

    /**
     * @param  ReflectionClass<object>  $rootReflection
     * @param  ReflectionClass<object>  $currentClass
     * @param  SplObjectStorage<object, bool>  $visited
     * @param  array<string, mixed>  $state
     */
    private function captureClassProperties(
        object $object,
        ReflectionClass $rootReflection,
        ReflectionClass $currentClass,
        SplObjectStorage $visited,
        int $depth,
        int $maxDepth,
        array &$state,
    ): void {
        foreach ($currentClass->getProperties() as $property) {
            if ($property->isStatic() || count($property->getAttributes(AllowPersistentState::class)) > 0) {
                continue;
            }

            $propName = $property->getName();
            $propKey = $currentClass->getName() === $rootReflection->getName()
                ? $propName
                : $currentClass->getName().'::'.$propName;

            if (! $property->isInitialized($object)) {
                $state[$propKey] = '__UNINITIALIZED__';

                continue;
            }

            $value = $property->getValue($object);
            $state[$propKey] = $this->serializeValue($value, $visited, $depth + 1, $maxDepth);
        }
    }

    /**
     * @param  SplObjectStorage<object, bool>  $visited
     */
    private function serializeValue(mixed $value, SplObjectStorage $visited, int $depth, int $maxDepth): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            $serialized = [];
            foreach ($value as $k => $v) {
                $serialized[$k] = $this->serializeValue($v, $visited, $depth, $maxDepth);
            }

            return $serialized;
        }

        if (is_object($value)) {
            return $this->serializeObject($value, $visited, $depth, $maxDepth);
        }

        if (is_resource($value)) {
            return 'resource('.get_resource_type($value).')';
        }

        return gettype($value);
    }

    /**
     * @param  SplObjectStorage<object, bool>  $visited
     */
    private function serializeObject(object $value, SplObjectStorage $visited, int $depth, int $maxDepth): mixed
    {
        if ($value instanceof Closure) {
            return 'Closure#'.spl_object_id($value);
        }

        if ($value instanceof ContainerInterface) {
            return sprintf('container(%s#%d)', $value::class, spl_object_id($value));
        }

        return $this->captureObjectState($value, $visited, $depth, $maxDepth);
    }

    private function areValuesEqual(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }

        if (is_array($a) && is_array($b)) {
            return serialize($a) === serialize($b);
        }

        return false;
    }

    private function getInstanceIdentifier(object $instance): string
    {
        return $instance::class.'#'.spl_object_id($instance);
    }

    /**
     * @return array<int, object>
     */
    private function extractFromContainer(ContainerInterface $container): array
    {
        $instances = $this->extractFromLaravelContainer($container);
        if (count($instances) > 0) {
            return $instances;
        }

        return $this->extractFromPsrProperties($container);
    }

    /**
     * @return array<int, object>
     */
    private function extractFromLaravelContainer(ContainerInterface $container): array
    {
        if (! property_exists($container, 'instances')) {
            return [];
        }

        $ref = new ReflectionClass($container);
        if (! $ref->hasProperty('instances')) {
            return [];
        }

        $rawInstances = $ref->getProperty('instances')->getValue($container);
        if (! is_array($rawInstances)) {
            return [];
        }

        $instances = [];
        foreach ($rawInstances as $instance) {
            if (is_object($instance) && ! ($instance instanceof ContainerInterface)) {
                $instances[] = $instance;
            }
        }

        return $instances;
    }

    /**
     * @return array<int, object>
     */
    private function extractFromPsrProperties(ContainerInterface $container): array
    {
        $ref = new ReflectionClass($container);
        $candidateProperties = ['services', 'entries', 'resolved', 'singletons', 'values'];
        $instances = [];

        foreach ($candidateProperties as $propName) {
            if (! $ref->hasProperty($propName)) {
                continue;
            }

            $val = $ref->getProperty($propName)->getValue($container);
            if (is_array($val)) {
                foreach ($val as $item) {
                    if (is_object($item) && ! ($item instanceof ContainerInterface)) {
                        $instances[] = $item;
                    }
                }
            }
        }

        return $instances;
    }
}

