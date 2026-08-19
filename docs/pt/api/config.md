# Referência de Configuração

O Leakless é configurado através do objeto `TheMattos\Leakless\Config` no PHP vanilla, ou via `.env` / `config/leakless.php` no Laravel Octane.

---

## 1. Configuração no PHP Vanilla

Instancie o objeto `Config` com as opções desejadas:

```php
use TheMattos\Leakless\DTOs\Config;

$config = new Config(
    maxRssMb: 96.0,            // Teto máximo de memória física RSS do Linux em MB
    maxRequests: 1000,         // Limite de requisições antes de reciclar o worker
    checkTransactions: true,   // Rollback automático de transações PDO abertas
    checkFileDescriptors: false, // Inspeciona descritores de arquivos (/proc/self/fd)
    autoRecycleOnViolation: true, // Reciclagem graciosa ao ultrapassar limites
    logViolations: true,       // Emite alertas de anomalias
);
```

---

## 2. Variáveis de Ambiente (.env)

Em aplicações Laravel Octane, configure o Leakless diretamente no seu `.env`:

```ini
LEAKLESS_ENABLED=true
LEAKLESS_MAX_RSS_MB=96
LEAKLESS_MAX_REQUESTS=1000
LEAKLESS_CHECK_TRANSACTIONS=true
LEAKLESS_CHECK_FILE_DESCRIPTORS=false
LEAKLESS_AUTO_RECYCLE=true
LEAKLESS_LOG_VIOLATIONS=true
```

---

## 3. Tabela de Referência

| Chave | Variável de Ambiente | Padrão | Descrição |
| :--- | :--- | :---: | :--- |
| `enabled` | `LEAKLESS_ENABLED` | `true` | Interruptor mestre para ativar ou desativar o Leakless. |
| `max_rss_mb` | `LEAKLESS_MAX_RSS_MB` | `96.0` | Teto de memória RSS real do Linux em MB. Ao ser ultrapassado, dispara a reciclagem do worker. |
| `max_requests` | `LEAKLESS_MAX_REQUESTS` | `null` | Limite de requisições por worker antes de reciclar (`null` = ilimitado). |
| `check_transactions` | `LEAKLESS_CHECK_TRANSACTIONS` | `true` | Audita e executa rollback automático em transações PDO abertas ao final da requisição. |
| `check_file_descriptors` | `LEAKLESS_CHECK_FILE_DESCRIPTORS` | `false` | Inspeciona `/proc/self/fd` para detectar arquivos ou sockets de rede esquecidos abertos. |
| `rollback_state` | `LEAKLESS_ROLLBACK_STATE` | `true` | Restaura fuso horário original, buffers de saída residuais e níveis de erro. |
| `log_violations` | `LEAKLESS_LOG_VIOLATIONS` | `true` | Emite logs diagnósticos quando transações órfãs ou anomalias de estado forem capturadas. |
