<?php

declare(strict_types=1);

namespace Tests\Unit\Dev\Support;

use Illuminate\Http\Request;
use TheMattos\Leakless\Attributes\AllowPersistentState;
use TheMattos\Leakless\Dev\Support\ClassLeakInspector;

class InspectorCleanService
{
    public function __construct(
        public string $name,
    ) {}
}

class InspectorController
{
    public function __construct(
        public Request $request,
    ) {}
}

class InspectorNoConstructorService
{
    public string $name = 'test';
}

class InspectorUntypedService
{
    public function __construct(public mixed $untypedParam = null) {}
}

class InspectorUnionService
{
    public function __construct(public string|int $unionParam = '') {}
}

class InspectorPropertyAllowedService
{
    /** @var array<string, mixed> */
    #[AllowPersistentState]
    public static array $cache = [];
}

class InspectorClassAllowedService
{
    /** @var array<string, mixed> */
    #[AllowPersistentState]
    public static array $cache = [];
}

class InspectorLeakyService
{
    /** @var array<string, mixed> */
    public static array $dirty = [];

    public function __construct(
        public Request $request,
    ) {}
}

test('it inspects classes and reports violations accurately', function () {
    $inspector = new ClassLeakInspector;

    expect($inspector->inspect(InspectorCleanService::class))->toBeEmpty()
        ->and($inspector->inspect(InspectorController::class))->toBeEmpty()
        ->and($inspector->inspect(InspectorNoConstructorService::class))->toBeEmpty()
        ->and($inspector->inspect(InspectorUntypedService::class))->toBeEmpty()
        ->and($inspector->inspect(InspectorUnionService::class))->toBeEmpty()
        ->and($inspector->inspect(InspectorClassAllowedService::class))->toBeEmpty()
        ->and($inspector->inspect(InspectorPropertyAllowedService::class))->toBeEmpty();

    $violations = $inspector->inspect(InspectorLeakyService::class);
    expect($violations)->toHaveCount(2)
        ->and($violations[0])->toContain('Mutable static property')
        ->and($violations[1])->toContain('Ephemeral request-scoped dependency');
});
