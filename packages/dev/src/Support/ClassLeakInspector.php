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
        // If class itself has #[AllowPersistentState], static properties are permitted
        if (count($reflection->getAttributes(AllowPersistentState::class)) > 0) {
            return [];
        }

        $violations = [];

        foreach ($reflection->getProperties() as $property) {
            if (! $property->isStatic()) {
                continue;
            }

            // Readonly static properties are immutable
            if ($property->isReadOnly()) {
                continue;
            }

            // Explicitly allowed properties with attribute
            if (count($property->getAttributes(AllowPersistentState::class)) > 0) {
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

    /**
     * @param  ReflectionClass<object>  $reflection
     * @return array<int, string>
     */
    private function inspectConstructor(ReflectionClass $reflection): array
    {
        $className = $reflection->getName();

        // Ephemeral classes by design (Controllers, Middlewares, Jobs) can receive request
        if (
            str_ends_with($className, 'Controller') ||
            str_ends_with($className, 'Middleware') ||
            str_ends_with($className, 'Request') ||
            str_ends_with($className, 'Job') ||
            str_ends_with($className, 'Test')
        ) {
            return [];
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return [];
        }

        $violations = [];

        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            if (! $type instanceof ReflectionNamedType) {
                continue;
            }

            $typeName = $type->getName();

            foreach (self::EPHEMERAL_CLASSES as $ephemeralClass) {
                if ($typeName === $ephemeralClass || str_ends_with($typeName, '\\'.$ephemeralClass)) {
                    $violations[] = sprintf(
                        'Ephemeral request-scoped dependency %s injected into constructor of service %s via $%s.',
                        $typeName,
                        $className,
                        $param->getName(),
                    );
                }
            }
        }

        return $violations;
    }
}
