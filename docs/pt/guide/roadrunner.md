# Integração com RoadRunner

O [RoadRunner](https://roadrunner.dev/) é um servidor de aplicações e gerenciador de processos PHP de alta performance desenvolvido em Go. Ele executa aplicações PHP em processos workers persistentes que se comunicam via abstrações HTTP PSR-7 através de pipes binários de alta velocidade (Goridge).

O Leakless se integra de forma transparente a qualquer script de worker do RoadRunner para neutralizar crescimento descontrolado de memória, transações abertas esquecidas, descritores de arquivos e estado acumulado.

---

## O Script do Worker RoadRunner

No arquivo de entrada do seu worker RoadRunner (por exemplo, `worker.php` ou `psr-worker.php`), envolva o loop de requisições com o `Leakless`:

```php
<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Leakless;

require_once __DIR__ . '/vendor/autoload.php';

// 1. Inicializa o Leakless com limites e resettables
$config = new Config(
    maxDriftMb: 64,
    consecutiveViolationsThreshold: 5,
    recycleCooldownSeconds: 10,
    checkTransactions: true,
    resettables: [
        App\Services\CartSession::class,
        fn () => LegacyStaticRegistry::$cache = [],
    ],
);

$leakless = new Leakless($config);

// 2. Inicializa o PSR-7 Worker do RoadRunner
$psr17Factory = new Psr17Factory();
$worker = Worker::create();
$psr7 = new PSR7Worker($worker, $psr17Factory, $psr17Factory, $psr17Factory);

// 3. Loop Persistente de Requisições
while ($request = $psr7->waitRequest()) {
    $leakless->startRequest();

    try {
        // Envia a requisição para sua aplicação ou pipeline PSR-15
        $response = $app->handle($request);
        $psr7->respond($response);
    } catch (\Throwable $e) {
        $psr7->respond(new Response(500, [], 'Erro Interno do Servidor'));
    } finally {
        // 4. Audita transações, esvazia buffers, executa resettables e avalia o RSS Linux
        $report = $leakless->endRequest();
    }

    // 5. Reciclagem graciosa ao confirmar violações
    if ($report->shouldRecycle) {
        $psr7->getWorker()->stop();
        break;
    }
}
```

---

## Como o Leakless Protege os Workers do RoadRunner

1. **Monitoramento Real de RSS no Kernel (`/proc/self/statm`)**: O supervisor do RoadRunner tem limites próprios, mas o Leakless monitora o Resident Set Size físico *na fronteira de cada requisição* e executa `gc_collect_cycles()` para diferenciar vazamentos reais de extensões C de fragmentações passageiras da Zend VM.
2. **Rollback Defensivo de Transações**: Se uma exceção ou query mal-estruturada deixar uma transação PDO aberta, o Leakless executa o rollback antes que a próxima requisição seja puxada do Go.
3. **Resettables Zero-Reflection**: Todas as classes, singletons e arrays estáticos listados em `resettables` são restaurados para seus estados iniciais entre requisições.
4. **Parada Graciosa (`$worker->stop()`)**: Ao confirmar necessidade de reciclagem, a chamada `$psr7->getWorker()->stop()` avisa o supervisor em Go para finalizar o processo graciosamente e subir um worker novo sem derrubar requisições de clientes.
