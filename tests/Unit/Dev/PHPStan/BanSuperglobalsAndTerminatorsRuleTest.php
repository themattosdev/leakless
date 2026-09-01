<?php

declare(strict_types=1);

namespace Tests\Unit\Dev\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use TheMattos\Leakless\Dev\PHPStan\Rules\BanSuperglobalsAndTerminatorsRule;

/**
 * @extends RuleTestCase<BanSuperglobalsAndTerminatorsRule>
 */
final class BanSuperglobalsAndTerminatorsRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new BanSuperglobalsAndTerminatorsRule;
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [];
    }

    public function test_it_reports_superglobals_terminators_and_native_sessions(): void
    {
        $this->analyse(
            [__DIR__.'/../../../Fixtures/PHPStan/SuperglobalsFixture.php'],
            [
                [
                    'Direct access to superglobal $_GET detected. In persistent worker environments (FrankenPHP/Octane), superglobals are not reset between requests or can leak data across multi-tenant requests. Use framework Request or Session abstraction instead.',
                    11,
                ],
                [
                    'Direct access to superglobal $_POST detected. In persistent worker environments (FrankenPHP/Octane), superglobals are not reset between requests or can leak data across multi-tenant requests. Use framework Request or Session abstraction instead.',
                    12,
                ],
                [
                    'Direct access to superglobal $_SESSION detected. In persistent worker environments (FrankenPHP/Octane), superglobals are not reset between requests or can leak data across multi-tenant requests. Use framework Request or Session abstraction instead.',
                    13,
                ],
                [
                    'Direct access to superglobal $_REQUEST detected. In persistent worker environments (FrankenPHP/Octane), superglobals are not reset between requests or can leak data across multi-tenant requests. Use framework Request or Session abstraction instead.',
                    14,
                ],
                [
                    'Direct access to superglobal $_FILES detected. In persistent worker environments (FrankenPHP/Octane), superglobals are not reset between requests or can leak data across multi-tenant requests. Use framework Request or Session abstraction instead.',
                    15,
                ],
                [
                    'Direct session_start() call detected. In persistent worker environments, native PHP sessions corrupt worker concurrency and session headers. Use the framework Session manager instead.',
                    17,
                ],
                [
                    'Direct exit() or die() call detected. In persistent worker environments (FrankenPHP/Octane), calling exit terminates the entire worker process and drops pending requests. Return a response or throw an exception instead.',
                    20,
                ],
            ],
        );
    }
}
