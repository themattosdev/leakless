# PHP Vanilla & FrankenPHP Worker Mode

Ao executar aplicações sem framework, microserviços ou rotinas customizadas com o **FrankenPHP Worker Mode**, o Leakless oferece o helper de integração: `TheMattos\Leakless\Integrations\FrankenPhp\FrankenPhp`.

---

## O Script do Worker

Crie o arquivo de entrada do worker (por exemplo, `worker.php`):

```php
<?php

declare(strict_types=1);

use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Integrations\FrankenPhp\FrankenPhp;

require_once __DIR__ . '/vendor/autoload.php';

// 1. Configurar limites e políticas de proteção
$config = new Config(
    maxDriftMb: 64,
    consecutiveViolationsThreshold: 5,
    recycleCooldownSeconds: 10,
    maxRequests: 1000,
    checkTransactions: true,
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
   - Executa `$leakless->endRequest()` no bloco `finally` para garantir auditoria de transações PDO, restauração de buffers/fuso horário e avaliação de drift relativo de memória.
4. **Encerramento Gracioso**: Se o drift persistir, o teto de emergência ou o limite de requisições for atingido, o `FrankenPhp::run()` sai do loop com segurança, permitindo que o gerenciador de processos do FrankenPHP inicie um worker novo e limpo.

---

## Loops de Eventos Customizados & Uso Manual

Se você estiver construindo seu próprio loop de eventos ou micro-framework, pode utilizar os métodos de ciclo de vida do `Leakless` diretamente:

```php
use TheMattos\Leakless\Leakless;
use TheMattos\Leakless\DTOs\Config;

$leakless = new Leakless(new Config(maxDriftMb: 64));

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

---

## Modo Multithread ZTS (Zend Thread Safety)

Quando o FrankenPHP executa em modo multithread com múltiplos workers em threads Go/C:

- **Processo do Sistema Operacional Compartilhado:** Todas as threads do worker compartilham o mesmo PID e a mesma medição de Resident Set Size física (`/proc/self/statm` RSS).
- **Zend Memory Manager Isolado (TSRM):** Cada thread possui seu próprio heap Zend isolado (`memory_get_usage()`).

### Proteção Contra "Noisy Neighbor" & Atribuição de Drift
Por padrão, o Leakless detecta automaticamente o ambiente (`defined('PHP_ZTS') && PHP_ZTS === 1`):
1. **Drift Atribuído à Thread:** Quando o RSS do processo excede `maxDriftMb`, o Leakless inspeciona o Zend Memory Manager da thread atual. Se ela reteve memória acima de `threadToleranceMb`, incrementa as violações consecutivas da thread.
2. **Proteção Contra Falsos Positivos:** Se uma thread vizinha causou o pico no RSS do processo, mas a thread atual manteve o ZMM estável, a thread inocente **não** é punida.
3. **Drift Não-Atribuído (Vazamentos Nativos em C):** Caso `maxRssMb` seja `null` e o RSS do processo continue subindo sem aumento correspondente no ZMM (indicando vazamento em bibliotecas C nativas como `GD` ou `libxml`), o Leakless aciona a reciclagem após atingir o limite `unattributedViolationsThreshold`.

