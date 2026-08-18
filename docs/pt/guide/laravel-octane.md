# Integração com Laravel Octane

O Leakless oferece integração nativa e com zero configuração para o **Laravel Octane** (utilizando o driver de servidor **FrankenPHP**).

> **Nota:** A integração com os drivers RoadRunner e Swoole está planejada no roadmap do projeto.

---

## Como Funciona

Quando o pacote `themattosdev/leakless` é instalado em uma aplicação Laravel:

1. **Auto-Discovery**: O sistema de descoberta de pacotes do Laravel registra automaticamente o `TheMattos\Leakless\Integrations\Laravel\LeaklessServiceProvider`.
2. **Hooks do Ciclo de Vida**: O Leakless escuta automaticamente os eventos do Octane:
   - `Laravel\Octane\Events\RequestReceived` ➔ Captura o snapshot inicial de conexões PDO ativas e métricas do kernel Linux.
   - `Laravel\Octane\Events\RequestTerminated` ➔ Audita transações PDO não commitadas, executa rollback automático se houver transações órfãs, restaura buffers de saída e fusos horários, e avalia os limites de memória RSS.
3. **Reciclagem Graciosa de Workers**: Se um worker ultrapassar o teto definido em `LEAKLESS_MAX_RSS_MB`, o Leakless finaliza o worker com segurança após a entrega da resposta ativa, sinalizando ao Octane para iniciar um novo worker limpo.

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

    'max_rss_mb' => env('LEAKLESS_MAX_RSS_MB', 256),

    'max_requests' => env('LEAKLESS_MAX_REQUESTS', null),

    'check_transactions' => env('LEAKLESS_CHECK_TRANSACTIONS', true),

    'rollback_state' => env('LEAKLESS_ROLLBACK_STATE', true),

    'log_violations' => env('LEAKLESS_LOG_VIOLATIONS', true),
];
```

### Variáveis de Ambiente (.env)

| Variável | Tipo | Padrão | Descrição |
| :--- | :---: | :---: | :--- |
| `LEAKLESS_ENABLED` | `bool` | `true` | Ativa ou desativa a auditoria do Leakless. |
| `LEAKLESS_MAX_RSS_MB` | `int\|float` | `256` | Teto de memória RSS real do Linux (em MB) antes de reciclar. |
| `LEAKLESS_MAX_REQUESTS` | `int\|null` | `null` | Limite de requisições por worker antes da reciclagem. |
| `LEAKLESS_CHECK_TRANSACTIONS` | `bool` | `true` | Detecta e executa rollback automático em transações PDO abertas. |
| `LEAKLESS_ROLLBACK_STATE` | `bool` | `true` | Restaura fusos horários, buffers de saída e níveis de erro. |
| `LEAKLESS_LOG_VIOLATIONS` | `bool` | `true` | Registra logs detalhados quando anomalias são interceptadas. |

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
        ->assertNoMemoryDrift(maxAllowedMb: 2.0)
        ->assertCleanWorkerState();
});
```
