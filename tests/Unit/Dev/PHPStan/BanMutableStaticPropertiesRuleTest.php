<?php

declare(strict_types=1);

namespace Tests\Unit\Dev\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use TheMattos\Leakless\Dev\PHPStan\Rules\BanMutableStaticPropertiesRule;

/**
 * @extends RuleTestCase<BanMutableStaticPropertiesRule>
 */
final class BanMutableStaticPropertiesRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new BanMutableStaticPropertiesRule;
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__.'/../../../../packages/dev/extension.neon',
        ];
    }

    public function test_it_reports_mutable_static_properties_without_attribute(): void
    {
        $this->analyse(
            [__DIR__.'/../../../Fixtures/PHPStan/MutableStaticFixture.php'],
            [
                [
                    'Mutable static property Tests\Fixtures\PHPStan\MutableStaticFixture::$leakyCache retention detected. In persistent worker environments (FrankenPHP/Octane), mutable static properties retain state across requests and leak data between users. Mark as readonly, convert to instance property, or annotate with #[AllowPersistentState] if intentionally cached.',
                    11,
                ],
            ],
        );
    }
}
