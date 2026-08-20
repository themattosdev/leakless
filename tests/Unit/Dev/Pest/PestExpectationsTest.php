<?php

declare(strict_types=1);

namespace Tests\Unit\Dev\Pest;

use TheMattos\Leakless\Attributes\AllowPersistentState;
use Throwable;

class SafeService
{
    /** @var array<string, mixed> */
    #[AllowPersistentState]
    public static array $bootCache = [];

    public readonly string $version;

    public function __construct(
        private string $serviceName,
    ) {
        $this->version = '1.0.0';
    }

    public function getServiceName(): string
    {
        return $this->serviceName;
    }
}

class LeakyStaticService
{
    /** @var array<string, mixed> */
    public static array $unprotectedState = [];
}

test('expect toBeLeakless passes on safe classes and objects', function () {
    expect(SafeService::class)->toBeLeakless()
        ->and(new SafeService('auth'))->toBeLeakless();
});

test('expect toBeLeakless fails when class contains mutable static properties', function () {
    $failed = false;

    try {
        expect(LeakyStaticService::class)->toBeLeakless();
    } catch (Throwable $e) {
        $failed = true;
        expect($e->getMessage())->toContain('Mutable static property');
    }

    expect($failed)->toBeTrue();
});

test('expect toRunCleanly asserts clean execution and memory drift ceiling', function () {
    expect(function () {
        $a = 1 + 1;
    })->toRunCleanly()
        ->and(function () {
            $data = ['item' => 'value'];
        })->toRunCleanly(maxDriftMb: 0.25);
});

test('expect toResetContainerState and toHaveStatelessInstances pass on clean execution', function () {
    $service = new SafeService('test');

    expect([$service])->toResetContainerState(function () use ($service) {
        $name = $service->getServiceName();
    });

    expect($service)->toHaveStatelessInstances(function () use ($service) {
        $name = $service->getServiceName();
    });
});

test('expect toResetContainerState fails when instance state is mutated', function () {
    $leaky = new class
    {
        /** @var array<int, string> */
        public array $items = [];
    };

    $failed = false;
    try {
        expect($leaky)->toResetContainerState(function () use ($leaky) {
            $leaky->items[] = 'polluted';
        });
    } catch (Throwable $e) {
        $failed = true;
        expect($e->getMessage())->toContain('Property [');
    }

    expect($failed)->toBeTrue();
});
