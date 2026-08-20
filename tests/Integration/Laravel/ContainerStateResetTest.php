<?php

declare(strict_types=1);

namespace Tests\Integration\Laravel;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use TheMattos\Leakless\Attributes\AllowPersistentState;
use TheMattos\Leakless\Dev\PHPUnit\AssertsLeakless;
use TheMattos\Leakless\Integrations\Laravel\LeaklessServiceProvider;

class LeakyTenantService
{
    public ?string $activeTenant = null;

    public function setTenant(string $tenant): void
    {
        $this->activeTenant = $tenant;
    }
}

class CleanResettingService
{
    /** @var array<int, string> */
    public array $buffer = [];

    public function process(string $data): void
    {
        $this->buffer[] = $data;
        // simulate reset hook at end of job/request
        $this->buffer = [];
    }
}

class AllowedCacheService
{
    /** @var array<string, mixed> */
    #[AllowPersistentState]
    public array $cache = [];

    public function remember(string $key, mixed $val): void
    {
        $this->cache[$key] = $val;
    }
}

final class ContainerStateResetTest extends TestCase
{
    use AssertsLeakless;

    protected function getPackageProviders($app): array
    {
        return [
            LeaklessServiceProvider::class,
        ];
    }

    public function test_it_detects_mutated_singletons_in_laravel_container(): void
    {
        /** @var Application $app */
        $app = $this->app;

        $app->singleton(LeakyTenantService::class, fn () => new LeakyTenantService);
        /** @var LeakyTenantService $service */
        $service = $app->make(LeakyTenantService::class);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('LeakyTenantService::$activeTenant');

        self::assertResetsContainerState($app, function () use ($service): void {
            $service->setTenant('tenant_abc_123');
        });
    }

    public function test_it_passes_when_laravel_singletons_reset_properly(): void
    {
        /** @var Application $app */
        $app = $this->app;

        $app->singleton(CleanResettingService::class, fn () => new CleanResettingService);
        /** @var CleanResettingService $service */
        $service = $app->make(CleanResettingService::class);

        self::assertResetsContainerState($app, function () use ($service): void {
            $service->process('temporary-payload');
        });

        $this->assertEmpty($service->buffer);
    }

    public function test_it_ignores_singletons_with_allow_persistent_state(): void
    {
        /** @var Application $app */
        $app = $this->app;

        $app->singleton(AllowedCacheService::class, fn () => new AllowedCacheService);
        /** @var AllowedCacheService $service */
        $service = $app->make(AllowedCacheService::class);

        self::assertResetsContainerState($app, function () use ($service): void {
            $service->remember('key', 'persisted-value');
        });

        $this->assertSame(['key' => 'persisted-value'], $service->cache);
    }
}
