# PSR-15 & Microframeworks (Slim, Mezzio)

O [PSR-15](https://www.php-fig.org/psr/psr-15/) é o padrão oficial de Handlers e Middlewares HTTP utilizado por frameworks PHP modernos como [Slim Framework](https://www.slimframework.com/), [Mezzio](https://docs.mezzio.dev/) e microserviços customizados.

Ao criar um Middleware PSR-15 simples, você protege qualquer aplicação compatível com PSR-15 em execução sob workers persistentes (FrankenPHP, RoadRunner, Swoole, ReactPHP) com zero complexidade.

---

## Middleware PSR-15 do Leakless

Crie uma classe de middleware na sua aplicação:

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
                // Registre seus serviços com estado ou callbacks de reset
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
                // Finaliza o worker de acordo com o servidor
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

## Uso no Slim Framework

No bootstrap da sua aplicação Slim:

```php
use Slim\Factory\AppFactory;
use App\Middleware\LeaklessMiddleware;
use TheMattos\Leakless\DTOs\Config;

$app = AppFactory::create();

// Adiciona o Leakless como o middleware mais externo
$app->add(new LeaklessMiddleware(new Config(
    maxDriftMb: 64,
    checkTransactions: true,
)));

$app->get('/api/health', function ($request, $response) {
    $response->getBody()->write(json_encode(['status' => 'saudavel']));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
```

---

## Vantagens para Microframeworks

- **Sem dependência de framework**: Funciona com qualquer roteador ou container compatível com PSR-7 e PSR-15.
- **Rollback Defensivo de Transações**: Resgata transações PDO esquecidas independentemente da biblioteca de banco (PDO puro, Doctrine DBAL, Eloquent Capsule).
- **Zero Reflection no Ciclo de Requisição**: Warmup compila todos os resetters, garantindo latências de microssegundos em microserviços de alto tráfego.
