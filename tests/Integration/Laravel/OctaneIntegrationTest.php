<?php

declare(strict_types=1);

namespace Tests\Integration\Laravel;

use Exception;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
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

        $config->set('leakless.max_rss_mb', 96);
        $config->set('leakless.check_transactions', true);
        $config->set('leakless.check_file_descriptors', true);
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
        $this->assertSame(96, $config->maxRssMb);
        $this->assertTrue($config->checkFileDescriptors);
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

    public function test_it_signals_octane_to_stop_worker_when_recycling_threshold_is_breached(): void
    {
        /** @var Application $app */
        $app = $this->app;

        $stopWorkerCalled = false;

        // Mock the Octane service in the Laravel container
        $octaneMock = new class($stopWorkerCalled)
        {
            public function __construct(public bool &$stopped) {}

            public function stopWorker(): void
            {
                $this->stopped = true;
            }
        };

        $app->instance('octane', $octaneMock);

        /** @var ConfigRepository $configRepo */
        $configRepo = $app->make('config');
        $configRepo->set('leakless.max_requests', 1);

        $app->forgetInstance(Config::class);
        $app->forgetInstance(Leakless::class);

        /** @var Leakless $configuredLeakless */
        $configuredLeakless = $app->make(Leakless::class);

        // Simulate Octane RequestReceived event
        Event::dispatch('Laravel\Octane\Events\RequestReceived', new class
        {
            public mixed $request = null;
        });

        // Simulate Octane RequestTerminated event
        Event::dispatch('Laravel\Octane\Events\RequestTerminated', new class
        {
            public mixed $request = null;

            public mixed $response = null;
        });

        $this->assertTrue($stopWorkerCalled);
        $report = $configuredLeakless->getLastReport();
        $this->assertNotNull($report);
        $this->assertTrue($report->shouldRecycle);
    }

    public function test_it_logs_error_when_octane_stop_worker_throws_exception(): void
    {
        /** @var Application $app */
        $app = $this->app;

        $octaneFaultyMock = new class
        {
            public function stopWorker(): void
            {
                throw new Exception('Octane runner unavailable');
            }
        };

        $app->instance('octane', $octaneFaultyMock);

        /** @var ConfigRepository $configRepo */
        $configRepo = $app->make('config');
        $configRepo->set('leakless.max_requests', 1);

        $app->forgetInstance(Config::class);
        $app->forgetInstance(Leakless::class);

        Log::shouldReceive('warning')->atLeast()->once();
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'Failed to signal Octane worker stop')
                    && isset($context['exception']);
            });

        // Simulate Request cycle
        Event::dispatch('Laravel\Octane\Events\RequestReceived', new class
        {
            public mixed $request = null;
        });

        Event::dispatch('Laravel\Octane\Events\RequestTerminated', new class
        {
            public mixed $request = null;

            public mixed $response = null;
        });
    }
}
