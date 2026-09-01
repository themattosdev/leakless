# Prevenindo Transações PDO Órfãs e Deadlocks em Workers PHP

No PHP-FPM tradicional, se uma requisição fosse interrompida por uma exceção não tratada, timeout ou erro fatal dentro de uma transação de banco de dados (`$pdo->beginTransaction()`), o processo PHP morria e o próprio servidor de banco de dados executava o rollback automático ao fechar o socket TCP.

Em runtimes PHP persistentes (**FrankenPHP**, **Laravel Octane**, **RoadRunner**, **Swoole**), **a conexão com o banco de dados é mantida aberta entre as requisições**.

Se uma transação aberta não for explicitamente confirmada (`commit()`) ou revertida (`rollBack()`), a conexão PDO volta para o pool de conexões **ainda em estado transacional** (`$pdo->inTransaction() === true`).

---

## O Perigo: Como Transações Órfãs Travam a Produção

```
Requisição 1 (Checkout):
  ├── $pdo->beginTransaction();
  ├── UPDATE estoque SET saldo = saldo - 1 WHERE id = 42; (Linha Bloqueada!)
  └── throw new PagamentoRecusadoException(); (Requisição encerra sem rollback!)
      └── Conexão nº 1 retorna ao pool mantendo o LOCK ativo no banco!

Requisição 2 (Outro usuário acessa a Home):
  ├── Reutiliza a Conexão nº 1
  ├── SELECT * FROM produtos;
  └── $pdo->beginTransaction(); ➔ ERRO FATAL: "There is already an active transaction"!
      └── Ou queries travam esperando o Lock nº 42 ➔ Esgotamento do Pool do Banco de Dados!
```

---

## Por Que Blocos Try/Catch Manuais Não São Suficientes

Mesmo com boas práticas, transações podem ficar abertas devido a:
1. **Instruções `return` antecipadas** que pulam a linha do `commit()`.
2. **Pacotes de terceiros** que abrem transações aninhadas e não tratam exceções internas.
3. **Erros de rede/desconexão temporária** durante a confirmação da transação.
4. **Erros não capturáveis ou memory limits** dentro de blocos críticos.

---

## Como o TransactionGuard do Leakless Resolve Automaticamente

O Leakless possui um `TransactionGuard` nativo que executa no bloco `finally` ao término de cada requisição.

```
Ciclo da Requisição
        │
        ▼
$leakless->startRequest()
        │
        ▼
Lógica da Aplicação (Processa HTTP / Job)
        │
        ▼
finally {
    $leakless->endRequest()
          │
          ▼
    O TransactionGuard audita todas as conexões PDO ativas:
          ├── $pdo->inTransaction() === true?
          ├── SIM ➔ Executa $pdo->rollBack() imediatamente!
          └── Registra log de diagnóstico com detalhes!
}
```

---

## Configuração & Uso

A auditoria de transações vem ativada por padrão no Leakless:

```php
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Leakless;

$config = new Config(
    checkTransactions: true, // Ativado por padrão
);

$leakless = new Leakless($config);

// No Laravel Octane: o Leakless audita automaticamente todas as conexões do DatabaseManager.
// No PHP Vanilla: registre instâncias PDO customizadas no boot:
$leakless->registerConnection($pdo);
```

Quando uma transação órfã é interceptada, o Leakless executa o rollback defensivo e emite um log estruturado:

```
[Leakless] 🚨 Dangling database transaction(s) detected and rolled back (1 transaction(s)).
```
