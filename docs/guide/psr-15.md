# PSR-15 & Microframeworks (Slim, Mezzio)

[PSR-15](https://www.php-fig.org/psr/psr-15/) is the standard HTTP Server Request Handler and Middleware specification across modern PHP frameworks like [Slim Framework](https://www.slimframework.com/), [Mezzio](https://docs.mezzio.dev/), and custom microservices.

By creating a simple PSR-15 Middleware, you can protect any PSR-15 compliant application running on persistent workers (FrankenPHP, RoadRunner, Swoole, ReactPHP) with zero boilerplate.

---

## Leakless PSR-15 Middleware

Create a middleware class in your application:

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Leakless;

final class LeaklessMiddleware implements MiddlewareInterface
{
    private Leakless $guardian;

    public function __construct(?Config $config = null)
    {
        $this->guardian = new Leakless($config ?? new Config(
            maxDriftMb: 64,
            consecutiveViolationsThreshold: 5,
            checkTransactions: true,
            resettables: [
                // Register your stateful services or reset callbacks
                App\Services\CartSession::class,
                fn () => App\Legacy\UserStorage::$token = null,
            ],
        ));
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->guardian->startRequest();

        try {
            $response = $handler->handle($request);

            return $response;
        } finally {
            $report = $this->guardian->endRequest([
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
            ]);

            if ($report->shouldRecycle) {
                // Exit or trigger server reload
                if (function_exists('frankenphp_finish_request')) {
                    @frankenphp_finish_request();
                    exit(0);
                }
            }
        }
    }
}
```

---

## Usage in Slim Framework

In your Slim application bootstrap:

```php
use Slim\Factory\AppFactory;
use App\Middleware\LeaklessMiddleware;
use TheMattos\Leakless\DTOs\Config;

$app = AppFactory::create();

// Add Leakless as the outermost middleware
$app->add(new LeaklessMiddleware(new Config(
    maxDriftMb: 64,
    checkTransactions: true,
)));

$app->get('/api/health', function ($request, $response) {
    $response->getBody()->write(json_encode(['status' => 'healthy']));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
```

---

## Benefits for Microframeworks

- **No Framework Lock-in**: Works with any router, container, or request handler complying with PSR-7 and PSR-15.
- **Defensive Transaction Rollback**: Automatically rescues orphaned PDO transactions regardless of database library (PDO, Doctrine DBAL, Eloquent Capsule).
- **Zero-Reflection in Request Cycle**: Warmup registers all resetters, ensuring microsecond execution speeds on high-throughput microservices.
