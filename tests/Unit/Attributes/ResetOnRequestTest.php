<?php

declare(strict_types=1);

use TheMattos\Leakless\Attributes\ResetOnRequest;

test('it instantiates ResetOnRequest attribute with defaults and custom options', function () {
    $defaultAttr = new ResetOnRequest;
    expect($defaultAttr->resetter)->toBeNull()
        ->and($defaultAttr->default)->toBeNull();

    $customAttr = new ResetOnRequest(resetter: 'resetState', default: ['initial']);
    expect($customAttr->resetter)->toBe('resetState')
        ->and($customAttr->default)->toBe(['initial']);

    $reflection = new ReflectionClass(ResetOnRequest::class);
    $attributes = $reflection->getAttributes(Attribute::class);
    expect($attributes)->toHaveCount(1);

    /** @var Attribute $attributeInstance */
    $attributeInstance = $attributes[0]->newInstance();
    expect($attributeInstance->flags & Attribute::TARGET_PROPERTY)->not->toBe(0)
        ->and($attributeInstance->flags & Attribute::TARGET_CLASS)->not->toBe(0)
        ->and($attributeInstance->flags & Attribute::TARGET_METHOD)->not->toBe(0);
});
