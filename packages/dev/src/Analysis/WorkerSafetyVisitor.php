<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Dev\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\BitwiseOr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeVisitorAbstract;
use TheMattos\Leakless\Attributes\AllowPersistentState;
use TheMattos\Leakless\Attributes\ResetOnRequest;

final class WorkerSafetyVisitor extends NodeVisitorAbstract
{
    private const ALLOWED_ATTRIBUTES = [
        AllowPersistentState::class,
        'AllowPersistentState',
        'TheMattos\Leakless\Attributes\AllowPersistentState',
        ResetOnRequest::class,
        'ResetOnRequest',
        'TheMattos\Leakless\Attributes\ResetOnRequest',
    ];

    private const EPHEMERAL_CLASSES = [
        'Illuminate\Http\Request',
        'Symfony\Component\HttpFoundation\Request',
        'Illuminate\Contracts\Session\Session',
        'Illuminate\Session\Store',
    ];

    private const FORBIDDEN_SUPERGLOBALS = [
        '_GET',
        '_POST',
        '_SESSION',
        '_REQUEST',
        '_FILES',
    ];

    private const INCOMPATIBLE_FUNCTIONS = [
        'get_browser' => 'get_browser() causes severe memory degradation over worker cycles. Use an external user-agent parser or cache results in Redis/APCu.',
        'setcookie' => 'Direct setcookie() call bypasses framework response cookie jars and can corrupt persistent worker response headers.',
        'setrawcookie' => 'Direct setrawcookie() call bypasses framework response cookie jars and can corrupt persistent worker response headers.',
        'header' => 'Direct header() call bypasses framework Response abstraction and can leak headers to subsequent worker requests.',
        'header_remove' => 'Direct header_remove() call bypasses framework Response abstraction.',
        'http_response_code' => 'Direct http_response_code() call bypasses framework Response abstraction.',
        'session_id' => 'Native session functions corrupt worker concurrency. Use framework Session abstraction instead.',
        'session_regenerate_id' => 'Native session functions corrupt worker concurrency. Use framework Session abstraction instead.',
        'session_destroy' => 'Native session functions corrupt worker concurrency. Use framework Session abstraction instead.',
        'flush' => 'Direct flush() call interferes with worker response buffering lifecycle.',
        'ob_implicit_flush' => 'Direct ob_implicit_flush() call interferes with worker response buffering lifecycle.',
    ];

    /**
     * @var array<int, array{message: string, line: int, identifier: string}>
     */
    private array $violations = [];

    /**
     * @var array<int, Class_>
     */
    private array $classStack = [];

    private string $currentFilePath = '';

    public function setFilePath(string $filePath): void
    {
        $this->currentFilePath = $filePath;
    }

    /**
     * @return array<int, array{message: string, line: int, identifier: string}>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }

    public function reset(): void
    {
        $this->violations = [];
        $this->classStack = [];
        $this->currentFilePath = '';
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Class_) {
            $this->classStack[] = $node;
        } elseif ($node instanceof Property) {
            $this->inspectProperty($node);
        } elseif ($node instanceof ClassMethod) {
            $this->inspectConstructor($node);
        } elseif ($node instanceof FuncCall) {
            $this->inspectFuncCall($node);
        } elseif ($node instanceof Variable) {
            $this->inspectVariable($node);
        } elseif ($node instanceof Exit_) {
            $this->inspectExit($node);
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Class_) {
            array_pop($this->classStack);
        }

        return null;
    }

    private function inspectProperty(Property $node): void
    {
        if (! $node->isStatic() || $node->isReadonly()) {
            return;
        }

        if ($this->hasAllowedAttribute($node->attrGroups)) {
            return;
        }

        $enclosingClass = end($this->classStack);
        if ($enclosingClass instanceof Class_ && $this->hasAllowedAttribute($enclosingClass->attrGroups)) {
            return;
        }

        $className = $this->resolveClassName($enclosingClass !== false ? $enclosingClass : null);
        $propName = $node->props[0]->name->toString();

        $this->addViolation(
            sprintf(
                'Mutable static property %s::$%s retention detected. In persistent worker environments (FrankenPHP/Octane), mutable static properties retain state across requests and leak data between users. Mark as readonly, convert to instance property, or annotate with #[AllowPersistentState] if intentionally cached.',
                $className,
                $propName
            ),
            $node->getStartLine(),
            'leakless.mutableStaticProperty'
        );
    }

    private function inspectConstructor(ClassMethod $node): void
    {
        if ($node->name->toString() !== '__construct') {
            return;
        }

        $enclosingClass = end($this->classStack);
        if (! $enclosingClass instanceof Class_) {
            return;
        }

        $className = $this->resolveClassName($enclosingClass);
        if ($this->isEphemeralHostClass($className)) {
            return;
        }

        foreach ($node->params as $param) {
            $this->inspectConstructorParam($param, $className);
        }
    }

    private function inspectConstructorParam(Node\Param $param, string $className): void
    {
        if (! $param->type instanceof Name) {
            return;
        }

        $typeString = $param->type->toString();

        foreach (self::EPHEMERAL_CLASSES as $ephemeralClass) {
            if ($this->isMatchingEphemeralType($typeString, $ephemeralClass)) {
                $paramName = $param->var instanceof Variable && is_string($param->var->name)
                    ? '$'.$param->var->name
                    : 'parameter';

                $this->addViolation(
                    sprintf(
                        'Ephemeral request-scoped dependency %s injected into constructor of service %s via %s. In persistent workers (FrankenPHP/Octane), singleton services hold references across requests, causing multi-tenant data leaks. Pass the request directly to the method, or inject a closure / Container resolver instead.',
                        $typeString,
                        $className,
                        $paramName
                    ),
                    $param->getStartLine(),
                    'leakless.ephemeralSingletonInjection'
                );

                return;
            }
        }
    }

    private function inspectFuncCall(FuncCall $node): void
    {
        if (! $node->name instanceof Name) {
            return;
        }

        $funcName = strtolower($node->name->toString());

        if ($funcName === 'session_start') {
            $this->addViolation(
                'Direct session_start() call detected. In persistent worker environments, native PHP sessions corrupt worker concurrency and session headers. Use the framework Session manager instead.',
                $node->getStartLine(),
                'leakless.sessionStart'
            );

            return;
        }

        $this->inspectIncompatibleFunction($node, $funcName);
    }

    private function inspectIncompatibleFunction(FuncCall $node, string $funcName): void
    {
        if ($funcName === 'glob' && isset($node->args[1])) {
            $flagArg = $node->args[1];
            if ($flagArg instanceof Node\Arg && $this->containsGlobBrace($flagArg->value)) {
                $this->addViolation(
                    'The GLOB_BRACE flag in glob() is unsupported in FrankenPHP Alpine-based Docker images (musl libc). glob() will fail and return false. Use multiple glob() calls or Symfony Finder instead.',
                    $node->getStartLine(),
                    'leakless.globBraceIncompatible'
                );
            }

            return;
        }

        if (str_starts_with($funcName, 'imap_')) {
            $this->addViolation(
                sprintf(
                    'Function %s() belongs to the ext-imap extension which is not thread-safe and unsupported in FrankenPHP. Use a modern userland package like DirectoryTree/ImapEngine or webklex/php-imap instead.',
                    $funcName
                ),
                $node->getStartLine(),
                'leakless.imapNotThreadSafe'
            );

            return;
        }

        if (array_key_exists($funcName, self::INCOMPATIBLE_FUNCTIONS)) {
            $this->addViolation(
                sprintf(
                    'Incompatible persistent worker function %s() detected. %s',
                    $funcName,
                    self::INCOMPATIBLE_FUNCTIONS[$funcName]
                ),
                $node->getStartLine(),
                'leakless.incompatibleFunction'
            );
        }
    }

    private function inspectVariable(Variable $node): void
    {
        if (is_string($node->name) && in_array($node->name, self::FORBIDDEN_SUPERGLOBALS, true)) {
            $this->addViolation(
                sprintf(
                    'Direct access to superglobal $%s detected. In persistent worker environments (FrankenPHP/Octane), superglobals are not reset between requests or can leak data across multi-tenant requests. Use framework Request or Session abstraction instead.',
                    $node->name
                ),
                $node->getStartLine(),
                'leakless.superglobal'
            );
        }
    }

    private function inspectExit(Exit_ $node): void
    {
        if (str_ends_with($this->currentFilePath, 'Leakless.php')) {
            return;
        }

        $enclosingClass = end($this->classStack);
        if ($enclosingClass instanceof Class_) {
            $className = $this->resolveClassName($enclosingClass);
            if ($className === 'TheMattos\Leakless\Leakless') {
                return;
            }
        }

        $this->addViolation(
            'Direct exit() or die() call detected. In persistent worker environments (FrankenPHP/Octane), calling exit terminates the entire worker process and drops pending requests. Return a response or throw an exception instead.',
            $node->getStartLine(),
            'leakless.processTerminator'
        );
    }

    /**
     * @param  array<Node\AttributeGroup>  $attrGroups
     */
    private function hasAllowedAttribute(array $attrGroups): bool
    {
        foreach ($attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if (in_array($attr->name->toString(), self::ALLOWED_ATTRIBUTES, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function resolveClassName(?Class_ $classNode): string
    {
        if ($classNode === null) {
            return 'anonymous class';
        }

        if (isset($classNode->namespacedName)) {
            return $classNode->namespacedName->toString();
        }

        return $classNode->name !== null ? $classNode->name->toString() : 'anonymous class';
    }

    private function isEphemeralHostClass(string $className): bool
    {
        return str_ends_with($className, 'Controller')
            || str_ends_with($className, 'Middleware')
            || str_ends_with($className, 'Request')
            || str_ends_with($className, 'Job')
            || str_ends_with($className, 'Test');
    }

    private function isMatchingEphemeralType(string $typeString, string $ephemeralClass): bool
    {
        return str_ends_with($typeString, $ephemeralClass)
            || $typeString === $ephemeralClass
            || str_ends_with($ephemeralClass, '\\'.$typeString);
    }

    private function containsGlobBrace(Expr $expr): bool
    {
        if ($expr instanceof ConstFetch && $expr->name->toString() === 'GLOB_BRACE') {
            return true;
        }

        if ($expr instanceof BitwiseOr) {
            return $this->containsGlobBrace($expr->left) || $this->containsGlobBrace($expr->right);
        }

        return false;
    }

    private function addViolation(string $message, int $line, string $identifier): void
    {
        $this->violations[] = [
            'message' => $message,
            'line' => $line,
            'identifier' => $identifier,
        ];
    }
}
