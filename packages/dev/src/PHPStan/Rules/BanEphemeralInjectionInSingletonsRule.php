<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Dev\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<ClassMethod>
 */
final class BanEphemeralInjectionInSingletonsRule implements Rule
{
    private const EPHEMERAL_CLASSES = [
        'Illuminate\Http\Request',
        'Symfony\Component\HttpFoundation\Request',
        'Illuminate\Contracts\Session\Session',
        'Illuminate\Session\Store',
    ];

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    /**
     * @param  ClassMethod  $node
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->name->toString() !== '__construct') {
            return [];
        }

        $classReflection = $scope->getClassReflection();
        if ($classReflection === null || $this->isEphemeralHostClass($classReflection->getName())) {
            return [];
        }

        $className = $classReflection->getName();
        $errors = [];

        foreach ($node->params as $param) {
            $error = $this->inspectParam($param, $className);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    private function isEphemeralHostClass(string $className): bool
    {
        return str_ends_with($className, 'Controller')
            || str_ends_with($className, 'Middleware')
            || str_ends_with($className, 'Request')
            || str_ends_with($className, 'Job')
            || str_ends_with($className, 'Test');
    }

    private function inspectParam(Node\Param $param, string $className): ?IdentifierRuleError
    {
        if ($param->type === null) {
            return null;
        }

        $typeString = $param->type instanceof Node\Name ? $param->type->toString() : '';

        foreach (self::EPHEMERAL_CLASSES as $ephemeralClass) {
            if ($this->isMatchingEphemeralType($typeString, $ephemeralClass)) {
                $paramName = $param->var instanceof Node\Expr\Variable && is_string($param->var->name)
                    ? '$'.$param->var->name
                    : 'parameter';

                return RuleErrorBuilder::message(
                    sprintf(
                        'Ephemeral request-scoped dependency %s injected into constructor of service %s via %s. In persistent workers (FrankenPHP/Octane), singleton services hold references across requests, causing multi-tenant data leaks. Pass the request directly to the method, or inject a closure / Container resolver instead.',
                        $typeString,
                        $className,
                        $paramName,
                    ),
                )
                    ->identifier('leakless.ephemeralSingletonInjection')
                    ->line($param->getStartLine())
                    ->build();
            }
        }

        return null;
    }

    private function isMatchingEphemeralType(string $typeString, string $ephemeralClass): bool
    {
        return str_ends_with($typeString, $ephemeralClass)
            || $typeString === $ephemeralClass
            || str_ends_with($ephemeralClass, '\\'.$typeString);
    }
}
