# Referência de Configuração

O Leakless é configurado através do objeto `TheMattos\Leakless\Config` no PHP vanilla, ou via `.env` / `config/leakless.php` no Laravel Octane.

---

## 1. Configuração no PHP Vanilla

Instancie o objeto `Config` com as opções desejadas:

```php
use TheMattos\Leakless\Config;
use TheMattos\Leakless\Leakless;

$config = new Config(
    enabled: true,             // Ativa ou desativa o Leakless
    maxRssMb: 256.0,           // Teto máximo de memória física RSS do Linux em MB
    maxRequests: 1000,         // Limite de requisições atendidas antes da reciclagem
    checkTransactions: true,   // Detecta e executa rollback em transações PDO abertas
    rollbackState: true,       // Restaura fuso horário, buffers de saída e níveis de erro
    logViolations: true,       // Emite logs de alerta quando anomalias são interceptadas
);

$leakless = new Leakless($config);
```

---

## 2. Configuração no Laravel Octane

Em aplicações Laravel, configure os parâmetros diretamente no arquivo `.env`:

```ini
LEAKLESS_ENABLED=true
LEAKLESS_MAX_RSS_MB=256
LEAKLESS_MAX_REQUESTS=1000
LEAKLESS_CHECK_TRANSACTIONS=true
LEAKLESS_ROLLBACK_STATE=true
LEAKLESS_LOG_VIOLATIONS=true
```

Ou publique o arquivo `config/leakless.php`:

```bash
php artisan vendor:publish --tag="leakless-config"
```

---

## 3. Tabela de Opções

| Opção | Variável de Ambiente | Padrão | Descrição |
| :--- | :--- | :---: | :--- |
| `enabled` | `LEAKLESS_ENABLED` | `true` | Chave mestra para ativar ou desativar a auditoria do Leakless. |
| `max_rss_mb` | `LEAKLESS_MAX_RSS_MB` | `256.0` | Teto de memória RSS real do Linux em MB. Ao ser ultrapassado, dispara a reciclagem do worker. |
| `max_requests` | `LEAKLESS_MAX_REQUESTS` | `null` | Limite de requisições por worker antes de reciclar (`null` = ilimitado). |
| `check_transactions` | `LEAKLESS_CHECK_TRANSACTIONS` | `true` | Audita e executa rollback automático em transações PDO abertas ao final da requisição. |
| `check_file_descriptors` | `LEAKLESS_CHECK_FILE_DESCRIPTORS` | `false` | Inspeciona `/proc/self/fd` para detectar arquivos ou sockets de rede esquecidos abertos. |
| `rollback_state` | `LEAKLESS_ROLLBACK_STATE` | `true` | Restaura fuso horário original, buffers de saída residuais e níveis de erro. |
| `log_violations` | `LEAKLESS_LOG_VIOLATIONS` | `true` | Emite logs diagnósticos quando transações órfãs ou anomalias de estado forem capturadas. |
