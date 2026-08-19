# PHP Vanilla & FrankenPHP Worker Mode

Ao executar aplicações sem framework, microserviços ou rotinas customizadas com o **FrankenPHP Worker Mode**, o Leakless oferece o helper de integração: `TheMattos\Leakless\Integrations\FrankenPhp\FrankenPhp`.

---

## O Script do Worker

Crie o arquivo de entrada do worker (por exemplo, `worker.php`):

```php
<?php

declare(strict_types=1);

use TheMattos\Leakless\Config;
use TheMattos\Leakless\Integrations\FrankenPhp\FrankenPhp;

require_once __DIR__ . '/vendor/autoload.php';

// 1. Configurar limites e políticas de proteção
$config = new Config(
    maxRssMb: 96.0,
    maxRequests: 1000,
    checkTransactions: true,
    rollbackState: true,
    logViolations: true,
);

// 2. Envolver o handler da aplicação no loop do FrankenPHP
FrankenPhp::run(function () {
    // Lógica de atendimento da requisição
    header('Content-Type: application/json');
    echo json_encode([
        'message' => 'Processado com segurança pelo worker persistente',
        'worker_pid' => getmypid(),
        'timestamp' => microtime(true),
    ]);
}, $config);
```

---

## Como o `FrankenPhp::run()` Opera

Internamente, o `FrankenPhp::run()` orquestra o ciclo de vida de execução persistente:

1. **Inicialização do Worker**: Instancia o motor `Leakless` com sua configuração `Config`.
2. **Polling Nativo do FrankenPHP**: Utiliza `frankenphp_handle_request()` para aguardar requisições HTTP recebidas do servidor Caddy/Go.
3. **Encapsulamento Automático**:
   - Invoca `$leakless->startRequest()` antes da execução do seu handler.
   - Executa o handler da aplicação dentro de um bloco protegido `try / finally`.
   - Executa `$leakless->endRequest()` no bloco `finally` para garantir auditoria de transações PDO, restauração de buffers/fuso horário e validação do teto de RAM.
4. **Encerramento Gracioso**: Se o teto de memória RSS ou o limite de requisições for atingido, o `FrankenPhp::run()` sai do loop com segurança, permitindo que o gerenciador de processos do FrankenPHP inicie um worker novo e limpo.

---

## Loops de Eventos Customizados & Uso Manual

Se você estiver construindo seu próprio loop de eventos ou micro-framework, pode utilizar os métodos de ciclo de vida do `Leakless` diretamente:

```php
use TheMattos\Leakless\Leakless;
use TheMattos\Leakless\Config;

$leakless = new Leakless(new Config(maxRssMb: 256.0));

while ($request = $server->accept()) {
    $leakless->startRequest();

    try {
        $response = $app->handle($request);
        $server->send($response);
    } finally {
        $report = $leakless->endRequest();

        if ($report->shouldRecycle) {
            // Finaliza o loop graciosamente
            break;
        }
    }
}
```
