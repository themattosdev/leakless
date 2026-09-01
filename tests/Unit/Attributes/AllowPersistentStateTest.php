<?php

declare(strict_types=1);

namespace Tests\Unit\Attributes;

use Attribute;
use ReflectionClass;
use TheMattos\Leakless\Attributes\AllowPersistentState;

test('it instantiates AllowPersistentState attribute and has correct attribute targets', function () {
    $attr = new AllowPersistentState;
    expect($attr)->toBeInstanceOf(AllowPersistentState::class);

    $reflection = new ReflectionClass(AllowPersistentState::class);
    $attributes = $reflection->getAttributes(Attribute::class);

    expect($attributes)->toHaveCount(1);

    /** @var Attribute $attributeInstance */
    $attributeInstance = $attributes[0]->newInstance();
    expect($attributeInstance->flags & Attribute::TARGET_PROPERTY)->toBe(Attribute::TARGET_PROPERTY)
        ->and($attributeInstance->flags & Attribute::TARGET_CLASS)->toBe(Attribute::TARGET_CLASS);
});
