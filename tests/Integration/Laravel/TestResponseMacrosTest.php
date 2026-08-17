<?php

declare(strict_types=1);

namespace Tests\Integration\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase;
use TheMattos\Leakless\Dev\Laravel\Testing\TestResponseMacros;
use TheMattos\Leakless\Integrations\Laravel\LeaklessServiceProvider;
use TheMattos\Leakless\Leakless;

final class TestResponseMacrosTest extends TestCase
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
        $config->set('leakless.max_rss_mb', 256);
        $config->set('leakless.log_violations', false);
    }

    protected function setUp(): void
    {
        parent::setUp();
        TestResponseMacros::register();
    }

    public function test_it_asserts_clean_worker_state_on_test_response(): void
    {
        /** @var Application $app */
        $app = $this->app;

        /** @var Leakless $leakless */
        $leakless = $app->make(Leakless::class);

        // Simulate request cycle
        Event::dispatch('Laravel\Octane\Events\RequestReceived', new class
        {
            public mixed $request = null;
        });

        Event::dispatch('Laravel\Octane\Events\RequestTerminated', new class
        {
            public mixed $request = null;

            public mixed $response = null;
        });

        /** @var mixed $testResponse */
        $testResponse = TestResponse::fromBaseResponse(new JsonResponse(['status' => 'ok']));

        // Assert macros execute without throwing
        // @phpstan-ignore-next-line
        $testResponse->assertNoDanglingTransactions()
            ->assertNoMemoryDrift(10.0)
            ->assertCleanWorkerState();
    }
}
