<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Dev\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use TheMattos\Leakless\Attributes\AllowPersistentState;
use TheMattos\Leakless\Attributes\ResetOnRequest;

/**
 * @implements Rule<Property>
 */
final class BanMutableStaticPropertiesRule implements Rule
{
    private const ALLOWED_ATTRIBUTES = [
        AllowPersistentState::class,
        'AllowPersistentState',
        'TheMattos\Leakless\Attributes\AllowPersistentState',
        ResetOnRequest::class,
        'ResetOnRequest',
        'TheMattos\Leakless\Attributes\ResetOnRequest',
    ];

    public function getNodeType(): string
    {
        return Property::class;
    }

    /**
     * @param  Property  $node
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->isStatic()) {
            return [];
        }

        // If marked as readonly, it is immutable after initialization
        if ($node->isReadonly()) {
            return [];
        }

        // Check if property itself has allowed attribute
        if ($this->hasAllowedAttribute($node->attrGroups)) {
            return [];
        }

        // Check if enclosing class has allowed attribute
        $classReflection = $scope->getClassReflection();
        if ($classReflection !== null) {
            $nativeReflection = $classReflection->getNativeReflection();
            if ($this->hasClassAllowedAttribute($nativeReflection)) {
                return [];
            }
        }

        $className = $classReflection !== null ? $classReflection->getDisplayName() : 'anonymous class';
        $propName = $node->props[0]->name->toString();

        return [
            RuleErrorBuilder::message(
                sprintf(
                    'Mutable static property %s::$%s retention detected. In persistent worker environments (FrankenPHP/Octane), mutable static properties retain state across requests and leak data between users. Mark as readonly, convert to instance property, or annotate with #[AllowPersistentState] if intentionally cached.',
                    $className,
                    $propName,
                ),
            )
                ->identifier('leakless.mutableStaticProperty')
                ->line($node->getStartLine())
                ->build(),
        ];
    }

    /**
     * @param  array<Node\AttributeGroup>  $attrGroups
     */
    private function hasAllowedAttribute(array $attrGroups): bool
    {
        foreach ($attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                $name = $attr->name->toString();
                if (in_array($name, self::ALLOWED_ATTRIBUTES, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  \ReflectionClass<object>  $reflection
     */
    private function hasClassAllowedAttribute(\ReflectionClass $reflection): bool
    {
        return count($reflection->getAttributes(AllowPersistentState::class)) > 0
            || count($reflection->getAttributes(ResetOnRequest::class)) > 0;
    }
}
