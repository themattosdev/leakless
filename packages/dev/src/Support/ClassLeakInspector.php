<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Dev\Support;

use ReflectionClass;
use ReflectionNamedType;
use TheMattos\Leakless\Attributes\AllowPersistentState;

final class ClassLeakInspector
{
    private const EPHEMERAL_CLASSES = [
        'Illuminate\Http\Request',
        'Symfony\Component\HttpFoundation\Request',
        'Illuminate\Contracts\Session\Session',
        'Illuminate\Session\Store',
    ];

    /**
     * Inspect a class or object for mutable static properties and ephemeral constructor injection.
     *
     * @param  class-string|object  $target
     * @return array<int, string> List of violation messages, empty if clean
     */
    public function inspect(string|object $target): array
    {
        $reflection = new ReflectionClass($target);
        $violations = [];

        // 1. Inspect static properties
        $violations = array_merge($violations, $this->inspectStaticProperties($reflection));

        // 2. Inspect constructor injection
        $violations = array_merge($violations, $this->inspectConstructor($reflection));

        return $violations;
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @return array<int, string>
     */
    private function inspectStaticProperties(ReflectionClass $reflection): array
    {
        if (count($reflection->getAttributes(AllowPersistentState::class)) > 0) {
            return [];
        }

        $violations = [];

        foreach ($reflection->getProperties() as $property) {
            if (! $this->isStaticPropertyLeaking($property)) {
                continue;
            }

            $violations[] = sprintf(
                'Mutable static property %s::$%s retention detected without #[AllowPersistentState].',
                $reflection->getName(),
                $property->getName(),
            );
        }

        return $violations;
    }

    private function isStaticPropertyLeaking(\ReflectionProperty $property): bool
    {
        if (! $property->isStatic() || $property->isReadOnly()) {
            return false;
        }

        return count($property->getAttributes(AllowPersistentState::class)) === 0;
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @return array<int, string>
     */
    private function inspectConstructor(ReflectionClass $reflection): array
    {
        $className = $reflection->getName();

        if ($this->isEphemeralHostClass($className)) {
            return [];
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return [];
        }

        $violations = [];

        foreach ($constructor->getParameters() as $param) {
            $ephemeralType = $this->resolveEphemeralType($param);
            if ($ephemeralType !== null) {
                $violations[] = sprintf(
                    'Ephemeral request-scoped dependency %s injected into constructor of service %s via $%s.',
                    $ephemeralType,
                    $className,
                    $param->getName(),
                );
            }
        }

        return $violations;
    }

    private function isEphemeralHostClass(string $className): bool
    {
        return str_ends_with($className, 'Controller')
            || str_ends_with($className, 'Middleware')
            || str_ends_with($className, 'Request')
            || str_ends_with($className, 'Job')
            || str_ends_with($className, 'Test');
    }

    private function resolveEphemeralType(\ReflectionParameter $param): ?string
    {
        $type = $param->getType();
        if (! $type instanceof ReflectionNamedType) {
            return null;
        }

        $typeName = $type->getName();

        foreach (self::EPHEMERAL_CLASSES as $ephemeralClass) {
            if ($typeName === $ephemeralClass || str_ends_with($typeName, '\\'.$ephemeralClass)) {
                return $typeName;
            }
        }

        return null;
    }
}
