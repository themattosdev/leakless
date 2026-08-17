<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Dev\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
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
        if ($classReflection === null) {
            return [];
        }

        $className = $classReflection->getName();

        // Controllers, Middlewares, FormRequests and Jobs are ephemeral/per-request by design
        if (
            str_ends_with($className, 'Controller') ||
            str_ends_with($className, 'Middleware') ||
            str_ends_with($className, 'Request') ||
            str_ends_with($className, 'Job') ||
            str_ends_with($className, 'Test')
        ) {
            return [];
        }

        $errors = [];

        foreach ($node->params as $param) {
            if ($param->type === null) {
                continue;
            }

            $typeString = $param->type instanceof Node\Name ? $param->type->toString() : '';

            foreach (self::EPHEMERAL_CLASSES as $ephemeralClass) {
                if (str_ends_with($typeString, $ephemeralClass) || $typeString === $ephemeralClass || str_ends_with($ephemeralClass, '\\'.$typeString)) {
                    $paramName = $param->var instanceof Node\Expr\Variable && is_string($param->var->name)
                        ? '$'.$param->var->name
                        : 'parameter';

                    $errors[] = RuleErrorBuilder::message(
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
        }

        return $errors;
    }
}
