<?php

declare(strict_types=1);

namespace Tests\Fixtures\PHPStan;

use TheMattos\Leakless\Attributes\AllowPersistentState;
use TheMattos\Leakless\Attributes\ResetOnRequest;

class MutableStaticFixture
{
    public static array $leakyCache = [];

    #[AllowPersistentState(reason: 'Immutable warm boot cache')]
    public static array $allowedCache = [];

    #[ResetOnRequest]
    public static array $resetOnRequestCache = [];

    readonly public static string $immutableKey;

    public string $instanceProperty = 'safe';
}

#[AllowPersistentState]
class AllowedClassFixture
{
    public static array $classLevelAllowed = [];
}

#[ResetOnRequest]
class ResetOnRequestClassFixture
{
    public static array $classLevelReset = [];
}
