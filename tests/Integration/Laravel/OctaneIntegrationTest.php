<?php

declare(strict_types=1);

namespace Tests\Integration\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Integrations\Laravel\LeaklessServiceProvider;
use TheMattos\Leakless\Leakless;

final class OctaneIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LeaklessServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        /** @var ConfigRepository $config */
        $config = $app['config'];

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $config->set('leakless.max_rss_mb', 256);
        $config->set('leakless.check_transactions', true);
        $config->set('leakless.log_violations', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function ($table): void {
            $table->id();
            $table->string('name');
        });
    }

    public function test_it_registers_singleton_in_laravel_container(): void
    {
        /** @var Application $app */
        $app = $this->app;

        $leakless = $app->make(Leakless::class);
        $config = $app->make(Config::class);

        $this->assertInstanceOf(Leakless::class, $leakless);
        $this->assertInstanceOf(Config::class, $config);
        $this->assertSame(256, $config->maxRssMb);
        $this->assertSame($leakless, $app->make('leakless'));
    }

    public function test_it_listens_to_octane_lifecycle_and_rolls_back_dangling_transactions(): void
    {
        /** @var Application $app */
        $app = $this->app;

        /** @var Leakless $leakless */
        $leakless = $app->make(Leakless::class);

        // 1. Simulate Octane RequestReceived event
        Event::dispatch('Laravel\Octane\Events\RequestReceived', new class
        {
            public mixed $request = null;
        });

        $this->assertSame(1, $leakless->getRequestCount());

        // 2. Open dangling database transaction in Laravel without committing
        DB::beginTransaction();
        DB::table('users')->insert(['name' => 'Alice']);

        $this->assertTrue(DB::getPdo()->inTransaction());

        // 3. Simulate Octane RequestTerminated event
        Event::dispatch('Laravel\Octane\Events\RequestTerminated', new class
        {
            public mixed $request = null;

            public mixed $response = null;
        });

        // 4. Assert transaction was automatically audited and rolled back
        $this->assertFalse(DB::getPdo()->inTransaction());
        $this->assertSame(0, DB::table('users')->count());

        $report = $leakless->getLastReport();
        $this->assertNotNull($report);
        $this->assertTrue($report->danglingTransactionsDetected);
        $this->assertSame(1, $report->danglingTransactionsCount);
    }
}
