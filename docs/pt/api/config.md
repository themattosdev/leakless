# Referência de Configuração

O Leakless é configurado através do objeto `TheMattos\Leakless\Config` no PHP vanilla, ou via `.env` / `config/leakless.php` no Laravel Octane.

---

## 1. Configuração no PHP Vanilla

Instancie o objeto `Config` com as opções desejadas:

```php
use TheMattos\Leakless\DTOs\Config;

$config = new Config(
    maxDriftMb: 64,                     // Crescimento de memória permitido acima do baseline (MB)
    maxRssMb: null,                     // Teto de emergência absoluto (MB) contra OOM Killer (opcional)
    consecutiveViolationsThreshold: 5,  // Requisições consecutivas com violação antes de reciclar
    recycleCooldownSeconds: 10,         // Intervalo mínimo em segundos entre reciclagens do worker
    triggerGcOnBreach: true,            // Reavalia memória física após executar gc_collect_cycles()
    driftJitterPercentage: 10,          // Variação randômica para desincronizar reinicializações
    maxRequests: 1000,                  // Limite de requisições antes de reciclar o worker
    checkTransactions: true,            // Rollback automático de transações PDO abertas
    checkFileDescriptors: false,        // Inspeciona descritores de arquivos (/proc/self/fd)
    autoRecycleOnViolation: true,       // Reciclagem graciosa ao ultrapassar limites
    logViolations: true,                // Emite alertas de anomalias
    resettables: [                      // Array de classes, instâncias ou callbacks para auto-reset
        App\Services\CartSession::class,
        fn () => LegacyRegistry::$cache = [],
    ],
);
```

---

## 2. Variáveis de Ambiente (.env)

Em aplicações Laravel Octane, configure o Leakless diretamente no seu `.env`:

```ini
LEAKLESS_ENABLED=true
LEAKLESS_MAX_DRIFT_MB=64
LEAKLESS_MAX_RSS_MB=null
LEAKLESS_CONSECUTIVE_VIOLATIONS=5
LEAKLESS_RECYCLE_COOLDOWN=10
LEAKLESS_TRIGGER_GC=true
LEAKLESS_DRIFT_JITTER=10
LEAKLESS_MAX_REQUESTS=1000
LEAKLESS_CHECK_TRANSACTIONS=true
LEAKLESS_CHECK_FILE_DESCRIPTORS=false
LEAKLESS_AUTO_RECYCLE=true
LEAKLESS_LOG_VIOLATIONS=true
```

Ou publique o arquivo `config/leakless.php`:

```bash
php artisan vendor:publish --tag="leakless-config"
```

---

## 3. Tabela de Referência

| Chave | Variável de Ambiente | Padrão | Descrição |
| :--- | :--- | :---: | :--- |
| `enabled` | `LEAKLESS_ENABLED` | `true` | Interruptor mestre para ativar ou desativar o Leakless. |
| `max_drift_mb` | `LEAKLESS_MAX_DRIFT_MB` | `64` | Crescimento relativo de memória RSS (em MB) permitido acima do baseline antes de avaliar reciclagem. |
| `max_rss_mb` | `LEAKLESS_MAX_RSS_MB` | `null` | Teto físico de emergência absoluto (em MB) para proteção contra o Linux OOM Killer SIGKILL. |
| `consecutive_violations` | `LEAKLESS_CONSECUTIVE_VIOLATIONS` | `5` | Histerese: requisições consecutivas com violação pós-GC necessárias para confirmar reciclagem. |
| `recycle_cooldown` | `LEAKLESS_RECYCLE_COOLDOWN` | `10` | Janela de cooldown em segundos entre reciclagens por worker (evita tempestades de reinício). |
| `trigger_gc` | `LEAKLESS_TRIGGER_GC` | `true` | Executa `gc_collect_cycles()` e relê `/proc/self/statm` antes de confirmar uma violação. |
| `drift_jitter` | `LEAKLESS_DRIFT_JITTER` | `10` | Variação percentual aplicada ao `max_drift_mb` para desincronizar reinicializações entre workers. |
| `max_requests` | `LEAKLESS_MAX_REQUESTS` | `null` | Limite de requisições por worker antes de reciclar (`null` = ilimitado). |
| `check_transactions` | `LEAKLESS_CHECK_TRANSACTIONS` | `true` | Audita e executa rollback automático em transações PDO abertas ao final da requisição. |
| `check_file_descriptors` | `LEAKLESS_CHECK_FILE_DESCRIPTORS` | `false` | Inspeciona `/proc/self/fd` para detectar arquivos ou sockets de rede esquecidos abertos. |
| `auto_recycle` | `LEAKLESS_AUTO_RECYCLE` | `true` | Dispara automaticamente a parada graciosa do worker quando limites ou corrupções persistirem. |
| `log_violations` | `LEAKLESS_LOG_VIOLATIONS` | `true` | Emite logs diagnósticos quando transações órfãs ou anomalias de estado forem capturadas. |
| `resettables` | — | `[]` | Lista de class-strings, objetos ou callbacks para resetar automaticamente ao final de cada requisição. |
