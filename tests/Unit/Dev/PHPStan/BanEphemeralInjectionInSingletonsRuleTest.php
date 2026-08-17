<?php

declare(strict_types=1);

namespace Tests\Unit\Dev\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use TheMattos\Leakless\Dev\PHPStan\Rules\BanEphemeralInjectionInSingletonsRule;

/**
 * @extends RuleTestCase<BanEphemeralInjectionInSingletonsRule>
 */
final class BanEphemeralInjectionInSingletonsRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new BanEphemeralInjectionInSingletonsRule;
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__.'/../../../../packages/dev/extension.neon',
        ];
    }

    public function test_it_reports_ephemeral_request_injection_in_services(): void
    {
        $this->analyse(
            [__DIR__.'/../../../Fixtures/PHPStan/EphemeralInjectionFixture.php'],
            [
                [
                    'Ephemeral request-scoped dependency Illuminate\Http\Request injected into constructor of service Tests\Fixtures\PHPStan\EphemeralInjectionFixture via $request. In persistent workers (FrankenPHP/Octane), singleton services hold references across requests, causing multi-tenant data leaks. Pass the request directly to the method, or inject a closure / Container resolver instead.',
                    12,
                ],
            ],
        );
    }
}
