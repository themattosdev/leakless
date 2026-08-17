<?php

declare(strict_types=1);

namespace Tests\Fixtures\PHPStan;

use TheMattos\Leakless\Attributes\AllowPersistentState;

class MutableStaticFixture
{
    public static array $leakyCache = [];

    #[AllowPersistentState(reason: 'Immutable warm boot cache')]
    public static array $allowedCache = [];

    readonly public static string $immutableKey;

    public string $instanceProperty = 'safe';
}

#[AllowPersistentState]
class AllowedClassFixture
{
    public static array $classLevelAllowed = [];
}
