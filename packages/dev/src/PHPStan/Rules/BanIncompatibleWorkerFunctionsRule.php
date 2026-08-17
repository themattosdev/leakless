<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Dev\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\BitwiseOr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<FuncCall>
 */
final class BanIncompatibleWorkerFunctionsRule implements Rule
{
    private const INCOMPATIBLE_FUNCTIONS = [
        // FrankenPHP Known Issues (known-issues.md)
        'get_browser' => 'get_browser() causes severe memory degradation over worker cycles. Use an external user-agent parser or cache results in Redis/APCu.',

        // Direct HTTP Header / Cookie emission bypassing framework Response abstraction
        'setcookie' => 'Direct setcookie() call bypasses framework response cookie jars and can corrupt persistent worker response headers.',
        'setrawcookie' => 'Direct setrawcookie() call bypasses framework response cookie jars and can corrupt persistent worker response headers.',
        'header' => 'Direct header() call bypasses framework Response abstraction and can leak headers to subsequent worker requests.',
        'header_remove' => 'Direct header_remove() call bypasses framework Response abstraction.',
        'http_response_code' => 'Direct http_response_code() call bypasses framework Response abstraction.',

        // Native PHP Session functions conflicting with worker concurrency
        'session_id' => 'Native session functions corrupt worker concurrency. Use framework Session abstraction instead.',
        'session_regenerate_id' => 'Native session functions corrupt worker concurrency. Use framework Session abstraction instead.',
        'session_destroy' => 'Native session functions corrupt worker concurrency. Use framework Session abstraction instead.',

        // Output flushes breaking worker lifecycle
        'flush' => 'Direct flush() call interferes with worker response buffering lifecycle.',
        'ob_implicit_flush' => 'Direct ob_implicit_flush() call interferes with worker response buffering lifecycle.',
    ];

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @param  FuncCall  $node
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Name) {
            return [];
        }

        $funcName = strtolower($node->name->toString());

        // 1. Check glob() with GLOB_BRACE flag (FrankenPHP musl libc / Alpine incompatibility)
        if ($funcName === 'glob' && isset($node->args[1])) {
            $flagArg = $node->args[1];
            if ($flagArg instanceof Node\Arg && $this->containsGlobBrace($flagArg->value)) {
                return [
                    RuleErrorBuilder::message(
                        'The GLOB_BRACE flag in glob() is unsupported in FrankenPHP Alpine-based Docker images (musl libc). glob() will fail and return false. Use multiple glob() calls or Symfony Finder instead.',
                    )
                        ->identifier('leakless.globBraceIncompatible')
                        ->line($node->getStartLine())
                        ->build(),
                ];
            }
        }

        // 2. Check IMAP extension functions (known-issues.md: not thread-safe in FrankenPHP)
        if (str_starts_with($funcName, 'imap_')) {
            return [
                RuleErrorBuilder::message(
                    sprintf(
                        'Function %s() belongs to the ext-imap extension which is not thread-safe and unsupported in FrankenPHP. Use a modern userland package like DirectoryTree/ImapEngine or webklex/php-imap instead.',
                        $funcName,
                    ),
                )
                    ->identifier('leakless.imapNotThreadSafe')
                    ->line($node->getStartLine())
                    ->build(),
            ];
        }

        // 3. Check General Incompatible Functions
        if (array_key_exists($funcName, self::INCOMPATIBLE_FUNCTIONS)) {
            return [
                RuleErrorBuilder::message(
                    sprintf(
                        'Incompatible persistent worker function %s() detected. %s',
                        $funcName,
                        self::INCOMPATIBLE_FUNCTIONS[$funcName],
                    ),
                )
                    ->identifier('leakless.incompatibleFunction')
                    ->line($node->getStartLine())
                    ->build(),
            ];
        }

        return [];
    }

    /**
     * Recursively inspect expression for GLOB_BRACE constant.
     */
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
}
