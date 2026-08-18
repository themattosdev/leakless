# Transaction Guard Automatizado (PDO)

Em workers persistentes, as conexões de banco de dados (instâncias PDO) têm vida longa e são reutilizadas ao longo de requisições consecutivas.

Se uma requisição HTTP iniciar uma transação de banco (`$pdo->beginTransaction()` ou `DB::beginTransaction()`), mas não executar o commit ou rollback (devido a uma exceção não tratada, return antecipado ou esquecimento):
- A transação permanece aberta na conexão persistente.
- A conexão é reaproveitada no próximo ciclo do worker.
- A **requisição do próximo usuário** roda silenciosamente dentro da transação aberta do usuário anterior.
- Isso provoca travamento de tabelas (locks), deadlocks e corrupção de dados entre clientes distintos.

---

## Como o TransactionGuard Opera

Ao final de cada ciclo de requisição (`$leakless->endRequest()`), o motor `TransactionGuard` executa uma auditoria automatizada:

1. **Auto-Descoberta de Conexões**:
   - No **Laravel Octane**, inspeciona `DB::getConnections()` para obter todas as conexões ativas do container (`Illuminate\Database\Connection`).
   - No **PHP Vanilla**, conexões PDO adicionais podem ser registradas via `$leakless->registerPdo($pdo)`.
2. **Inspeção de Estado de Transação**:
   - Executa `$pdo->inTransaction()` em todas as conexões ativas.
3. **Rollback Automático e Seguro**:
   - Se qualquer conexão contiver uma transação em aberto, o `TransactionGuard` executa imediatamente `$pdo->rollBack()`.
4. **Logs Diagnósticos e Relatório**:
   - Registra um alerta detalhado no log:
     `[Leakless] 🚨 Dangling database transaction(s) detected and rolled back (1 transaction(s)).`
   - Sinaliza `hasTransactionLeak = true` no objeto `Report`.

---

## Registrando Conexões PDO Customizadas (PHP Vanilla)

Em projetos sem framework ou fora do Laravel, você pode registrar suas instâncias de PDO no Leakless:

```php
$pdo = new PDO('mysql:host=localhost;dbname=app', 'usuario', 'senha');

// Registra a conexão PDO para auditoria automática ao final de cada requisição
$leakless->registerPdo($pdo);
```
