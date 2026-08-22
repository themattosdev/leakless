# Integração com Laravel Octane

O Leakless oferece integração nativa e com zero configuração para o **Laravel Octane** (utilizando o driver de servidor **FrankenPHP**).

> **Nota:** A integração com os drivers RoadRunner e Swoole está planejada no roadmap do projeto.

---

## Como Funciona

Quando o pacote `themattosdev/leakless` é instalado em uma aplicação Laravel:

1. **Auto-Discovery**: O sistema de descoberta de pacotes do Laravel registra automaticamente o `TheMattos\Leakless\Integrations\Laravel\LeaklessServiceProvider`.
2. **Hooks do Ciclo de Vida**: O Leakless escuta automaticamente os eventos do Octane:
   - `Laravel\Octane\Events\WorkerStarting` / `OctaneStarted` ➔ Captura a memória baseline inicial limpa ($M_0$) pós-boot do Laravel.
   - `Laravel\Octane\Events\RequestReceived` ➔ Captura o snapshot inicial de conexões PDO ativas e métricas do kernel Linux.
   - `Laravel\Octane\Events\RequestTerminated` ➔ Audita transações PDO não commitadas, executa rollback automático se houver transações órfãs, restaura buffers de saída e fusos horários, e avalia o drift de memória relativo.
3. **Reciclagem Graciosa com Cooldown**: Se o drift de memória persistir por $N$ requisições consecutivas pós-GC, o Leakless finaliza o worker com segurança após a entrega da resposta ativa, respeitando a janela de cooldown para evitar tempestades de reinicialização.

---

## Configuração

Publique o arquivo de configuração padrão:

```bash
php artisan vendor:publish --tag="leakless-config"
```

O arquivo `config/leakless.php` será criado:

```php
return [
    'enabled' => env('LEAKLESS_ENABLED', true),

    'max_drift_mb' => env('LEAKLESS_MAX_DRIFT_MB') !== null ? (int) env('LEAKLESS_MAX_DRIFT_MB') : 64,

    'max_rss_mb' => env('LEAKLESS_MAX_RSS_MB') ? (int) env('LEAKLESS_MAX_RSS_MB') : null,

    'consecutive_violations' => (int) env('LEAKLESS_CONSECUTIVE_VIOLATIONS', 5),

    'recycle_cooldown' => (int) env('LEAKLESS_RECYCLE_COOLDOWN', 10),

    'trigger_gc' => env('LEAKLESS_TRIGGER_GC', true),

    'drift_jitter' => (int) env('LEAKLESS_DRIFT_JITTER', 10),

    'max_requests' => env('LEAKLESS_MAX_REQUESTS') ? (int) env('LEAKLESS_MAX_REQUESTS') : null,

    'check_transactions' => env('LEAKLESS_CHECK_TRANSACTIONS', true),

    'check_file_descriptors' => env('LEAKLESS_CHECK_FILE_DESCRIPTORS', false),

    'auto_recycle' => env('LEAKLESS_AUTO_RECYCLE', true),

    'log_violations' => env('LEAKLESS_LOG_VIOLATIONS', true),
];
```

### Variáveis de Ambiente (.env)

| Variável | Tipo | Padrão | Descrição |
| :--- | :---: | :---: | :--- |
| `LEAKLESS_ENABLED` | `bool` | `true` | Ativa ou desativa a auditoria do Leakless. |
| `LEAKLESS_MAX_DRIFT_MB` | `int\|null` | `64` | Crescimento relativo de RSS (MB) permitido acima do baseline. |
| `LEAKLESS_MAX_RSS_MB` | `int\|null` | `null` | Teto físico de emergência absoluto em MB (opcional). |
| `LEAKLESS_CONSECUTIVE_VIOLATIONS` | `int` | `5` | Violações pós-GC consecutivas necessárias para confirmar reciclagem. |
| `LEAKLESS_RECYCLE_COOLDOWN` | `int` | `10` | Intervalo mínimo em segundos entre reciclagens por worker. |
| `LEAKLESS_TRIGGER_GC` | `bool` | `true` | Executa `gc_collect_cycles()` em caso de suspeita de estouro. |
| `LEAKLESS_DRIFT_JITTER` | `int` | `10` | Percentual de variação para desincronizar reinicializações entre workers. |
| `LEAKLESS_MAX_REQUESTS` | `int\|null` | `null` | Limite de requisições por worker antes da reciclagem. |
| `LEAKLESS_CHECK_TRANSACTIONS` | `bool` | `true` | Detecta e executa rollback automático em transações PDO abertas. |
| `LEAKLESS_CHECK_FILE_DESCRIPTORS` | `bool` | `false` | Inspeciona `/proc/self/fd` para detectar arquivos e sockets esquecidos abertos. |
| `LEAKLESS_AUTO_RECYCLE` | `bool` | `true` | Sinaliza parada graciosa do worker ao Octane em caso de violação confirmada. |
| `LEAKLESS_LOG_VIOLATIONS` | `bool` | `true` | Registra logs detalhados quando anomalias ou vazamentos são interceptados. |

---

## Macros de Teste HTTP no Laravel

Ao instalar o `themattosdev/leakless-dev`, o Leakless injeta macros de asserção no `TestResponse` do Laravel:

```php
test('endpoint de checkout mantém o worker em estado limpo', function () {
    $response = $this->postJson('/api/checkout', [
        'cart_id' => 1001,
    ]);

    $response->assertOk()
        ->assertNoDanglingTransactions()
        ->assertNoMemoryDrift(maxAllowedMb: 0.25)
        ->assertCleanWorkerState();
});
```
