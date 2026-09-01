# Integração com Swoole & OpenSwoole

O [Swoole](https://www.swoole.co.uk/) e o [OpenSwoole](https://openswoole.com/) são runtimes orientados a eventos e corrotinas assíncronas para PHP que mantêm os processos workers ativos indefinidamente na memória.

Ao utilizar servidores HTTP ou task workers do Swoole, o Leakless atua como uma camada defensiva para auditar transações de banco de dados esquecidas abertas, esvaziar buffers de saída não fechados, monitorar o drift de RSS real no kernel Linux e sinalizar recargas graciosas do worker antes que estouros de memória causem `SIGKILL` pelo sistema operacional.

---

## Integração em Servidores HTTP Swoole

No script do seu servidor Swoole (por exemplo, `server.php`), instancie o `Leakless` no início do worker e envolva o evento `request`:

```php
<?php

declare(strict_types=1);

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Leakless;

// 1. Cria a instância do Servidor Swoole
$server = new Server('0.0.0.0', 9501);

$config = new Config(
    maxDriftMb: 64,
    consecutiveViolationsThreshold: 5,
    recycleCooldownSeconds: 10,
    checkTransactions: true,
    resettables: [
        App\Services\WorkerMetricsBuffer::class,
    ],
);

/** @var Leakless|null $leakless */
$leakless = null;

// 2. Inicializa o Leakless quando o processo worker inicia (WorkerStart)
$server->on('WorkerStart', function (Server $server, int $workerId) use ($config, &$leakless) {
    $leakless = new Leakless($config);
    $leakless->captureBaselineMetrics();
});

// 3. Atendimento de requisições HTTP
$server->on('request', function (Request $request, Response $response) use ($server, &$leakless) {
    $leakless?->startRequest();

    try {
        // Lógica de atendimento da sua aplicação
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode(['status' => 'sucesso']));
    } finally {
        // 4. Audita transações, esvazia buffers e avalia o drift de memória física
        $report = $leakless?->endRequest();

        // 5. Reinicia o worker graciosamente ao confirmar necessidade de reciclagem
        if ($report !== null && $report->shouldRecycle) {
            $server->reload();
        }
    }
});

$server->start();
```

---

## Considerações Importantes sobre Corrotinas

Ao utilizar o modo com corrotinas ativas (`enable_coroutine = true`), lembre-se:

- **Estado por Requisição**: Nunca armazene dados específicos de um usuário ou request em propriedades `static` globais, pois múltiplas corrotinas rodando simultaneamente dentro do mesmo processo compartilhariam essa memória. Utilize `Swoole\Coroutine::getContext()` ou containers contextuais do framework.
- **Recursos do Processo Worker**: Os `resettables`, `StateRollback` e `TransactionGuard` do Leakless protegem recursos globais do worker, pools de conexões e configurações de ambiente (`timezone`, `error_reporting`), prevenindo exaustão de memória física do processo.
