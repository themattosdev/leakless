<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Dev\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Node>
 */
final class BanSuperglobalsAndTerminatorsRule implements Rule
{
    private const FORBIDDEN_SUPERGLOBALS = [
        '_GET',
        '_POST',
        '_SESSION',
        '_REQUEST',
        '_FILES',
    ];

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        // 1. Check Superglobal Access
        if ($node instanceof Variable && is_string($node->name) && in_array($node->name, self::FORBIDDEN_SUPERGLOBALS, true)) {
            return [
                RuleErrorBuilder::message(
                    sprintf(
                        'Direct access to superglobal $%s detected. In persistent worker environments (FrankenPHP/Octane), superglobals are not reset between requests or can leak data across multi-tenant requests. Use framework Request or Session abstraction instead.',
                        $node->name,
                    ),
                )
                    ->identifier('leakless.superglobal')
                    ->line($node->getStartLine())
                    ->build(),
            ];
        }

        // 2. Check exit() or die()
        if ($node instanceof Exit_) {
            return [
                RuleErrorBuilder::message(
                    'Direct exit() or die() call detected. In persistent worker environments (FrankenPHP/Octane), calling exit terminates the entire worker process and drops pending requests. Return a response or throw an exception instead.',
                )
                    ->identifier('leakless.processTerminator')
                    ->line($node->getStartLine())
                    ->build(),
            ];
        }

        // 3. Check session_start()
        if ($node instanceof FuncCall && $node->name instanceof Name && strtolower($node->name->toString()) === 'session_start') {
            return [
                RuleErrorBuilder::message(
                    'Direct session_start() call detected. In persistent worker environments, native PHP sessions corrupt worker concurrency and session headers. Use the framework Session manager instead.',
                )
                    ->identifier('leakless.sessionStart')
                    ->line($node->getStartLine())
                    ->build(),
            ];
        }

        return [];
    }
}
