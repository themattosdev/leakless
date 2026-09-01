<?php

declare(strict_types=1);

namespace Tests\Unit\Dev\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use TheMattos\Leakless\Dev\PHPStan\Rules\BanIncompatibleWorkerFunctionsRule;

/**
 * @extends RuleTestCase<BanIncompatibleWorkerFunctionsRule>
 */
final class BanIncompatibleWorkerFunctionsRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new BanIncompatibleWorkerFunctionsRule;
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [];
    }

    public function test_it_reports_incompatible_functions(): void
    {
        $this->analyse(
            [__DIR__.'/../../../Fixtures/PHPStan/IncompatibleFunctionsFixture.php'],
            [
                [
                    'Incompatible persistent worker function get_browser() detected. get_browser() causes severe memory degradation over worker cycles. Use an external user-agent parser or cache results in Redis/APCu.',
                    11,
                ],
                [
                    'Incompatible persistent worker function setcookie() detected. Direct setcookie() call bypasses framework response cookie jars and can corrupt persistent worker response headers.',
                    12,
                ],
                [
                    'Incompatible persistent worker function header() detected. Direct header() call bypasses framework Response abstraction and can leak headers to subsequent worker requests.',
                    13,
                ],
                [
                    'Incompatible persistent worker function http_response_code() detected. Direct http_response_code() call bypasses framework Response abstraction.',
                    14,
                ],
                [
                    'Incompatible persistent worker function session_id() detected. Native session functions corrupt worker concurrency. Use framework Session abstraction instead.',
                    15,
                ],
                [
                    'Incompatible persistent worker function flush() detected. Direct flush() call interferes with worker response buffering lifecycle.',
                    16,
                ],
                [
                    'The GLOB_BRACE flag in glob() is unsupported in FrankenPHP Alpine-based Docker images (musl libc). glob() will fail and return false. Use multiple glob() calls or Symfony Finder instead.',
                    17,
                ],
                [
                    'The GLOB_BRACE flag in glob() is unsupported in FrankenPHP Alpine-based Docker images (musl libc). glob() will fail and return false. Use multiple glob() calls or Symfony Finder instead.',
                    18,
                ],
                [
                    'Function imap_open() belongs to the ext-imap extension which is not thread-safe and unsupported in FrankenPHP. Use a modern userland package like DirectoryTree/ImapEngine or webklex/php-imap instead.',
                    21,
                ],
            ],
        );
    }
}
