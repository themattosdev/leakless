<?php

declare(strict_types=1);

namespace Tests\Integration\Documentation;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Leakless;

class SamplePsr15CartSession
{
    /** @var array<int, string> */
    public array $items = ['initial'];

    public function reset(): void
    {
        $this->items = [];
    }
}

class SamplePsr15UserStorage
{
    public static ?string $token = null;
}

final class SampleLeaklessMiddleware implements MiddlewareInterface
{
    public Leakless $guardian;

    public bool $recycled = false;

    public function __construct(?Config $config = null)
    {
        $this->guardian = new Leakless($config ?? new Config(
            maxDriftMb: 64,
            consecutiveViolationsThreshold: 5,
            checkTransactions: true,
            resettables: [
                SamplePsr15CartSession::class,
                fn () => SamplePsr15UserStorage::$token = null,
            ],
        ));
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->guardian->startRequest();

        try {
            return $handler->handle($request);
        } finally {
            $report = $this->guardian->endRequest([
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
            ]);

            if ($report->shouldRecycle) {
                $this->recycled = true;
            }
        }
    }
}

test('psr-15 middleware example from documentation intercepts request and cleans state', function () {
    $cartSession = new SamplePsr15CartSession;
    $middleware = new SampleLeaklessMiddleware(new Config(
        resettables: [
            $cartSession,
            fn () => SamplePsr15UserStorage::$token = null,
        ],
    ));

    $request = new ServerRequest('POST', 'https://example.com/api/cart');

    $handler = new class($cartSession) implements RequestHandlerInterface
    {
        public function __construct(private SamplePsr15CartSession $cart) {}

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $this->cart->items = ['item_1', 'item_2'];
            SamplePsr15UserStorage::$token = 'secret_token';

            return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['status' => 'added']));
        }
    };

    $response = $middleware->process($request, $handler);

    expect($response->getStatusCode())->toBe(200)
        ->and($cartSession->items)->toBeEmpty()
        ->and(SamplePsr15UserStorage::$token)->toBeNull();
});
