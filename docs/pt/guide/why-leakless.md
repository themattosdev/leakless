# Por que o Leakless?

Em ambientes tradicionais com PHP-FPM, o PHP opera sob a arquitetura **Shared-Nothing (Nada Compartilhado)**:

1. Uma requisição HTTP recebida aloca um processo worker isolado.
2. A aplicação inicializa, processa a requisição e gera a resposta.
3. O processo worker descarrega os buffers, encerra todas as conexões de banco de dados, libera a memória e **termina** (ou reinicia completamente o estado da máquina virtual).

No PHP-FPM, vazamentos de memória, transações de banco de dados não commitadas, fusos horários alterados ou caches estáticos poluídos eram praticamente inofensivos porque o sistema operacional limpava tudo ao término da requisição.

---

## A Mudança de Paradigma: Workers Persistentes

Runtimes modernos de PHP como o **FrankenPHP Worker Mode** e o **Laravel Octane** mantêm os processos workers persistentemente residentes na memória RAM ao longo de milhares de requisições consecutivas:

```
[Requisição #1] ───► Worker Inicializado (RAM) ───► Resposta #1
                           │ (O Worker continua vivo!)
[Requisição #2] ───► Mesmo Worker na Memória ────► Resposta #2
                           │
[Requisição #N] ───► Mesmo Worker na Memória ────► Resposta #N
```

Essa arquitetura de execução persistente entrega **de 10x a 50x mais requisições por segundo** e tempos de resposta sub-milissegundos ao evitar a reinicialização constante do framework.

No entanto, manter workers vivos na memória introduz riscos arquiteturais críticos:

### 1. Crescimento Silencioso de Memória Nativa (Extensões C)

Os analisadores de memória nativos do PHP (`memory_get_usage()`) rastreiam apenas a memória alocada dentro do **heap da Zend Engine**.

Quando sua aplicação utiliza extensões em C (`ext-curl`, `ext-imagick`, `ext-gd`, `ext-openssl`, `ext-pdo`):
- A memória é alocada via biblioteca padrão do C (`malloc()`) fora do motor do PHP.
- O `memory_get_usage()` é completamente cego a essas alocações.
- Ao longo de centenas de requisições, a memória nativa não monitorada acumula até que o OOM Killer do kernel Linux derruba o processo bruscamente.

### 2. Transações de Banco de Dados Órfãs (Dangling Transactions)

Se ocorrer uma exceção não tratada ou um `commit()` esquecido dentro de um fluxo da aplicação:
- Um bloco `BEGIN TRANSACTION` ativo permanece aberto na conexão PDO persistente.
- A próxima requisição HTTP vinda de um usuário completamente diferente reutiliza essa conexão.
- As consultas executadas na segunda requisição rodam dentro da transação não commitada do primeiro usuário, causando vazamento de dados entre clientes e travamentos de tabelas.

### 3. Mutação de Estado Global

Workers persistentes retêm alterações feitas no processo:
- Alterar o fuso horário padrão via `date_default_timezone_set()` afeta todas as requisições subsequentes naquele worker.
- Buffers de saída não fechados criados com `ob_start()` vazam pedaços de HTML ou JSON para as próximas requisições.
- Alterar o nível de reporte de erros (`error_reporting()`) modifica permanentemente o comportamento da aplicação.

---

## Como o Leakless Protege sua Aplicação

O **Leakless** fornece um guardião autônomo de sobrecarga zero projetado especificamente para PHP persistente:

| Pilar de Proteção | Mecanismo | O que Protege |
| :--- | :--- | :--- |
| **RSS Real do Kernel** | Leitura direta do `/proc/self/statm` | Rastreia a memória real do Linux incluindo extensões em C |
| **Transaction Guard** | Reflexão e auditoria de conexões PDO | Detecta e executa rollback automático em transações órfãs |
| **State Rollback** | Bloco defensivo no ciclo de vida `finally` | Restaura fuso horário, buffers de saída e níveis de erro |
| **Reciclagem Graciosa** | Interceptador de limites de requisição/RAM | Dispara reinício do worker sem derrubar requisições ativas |
| **Linter Estático CLI** | Inspeção de AST via PHPStan | Detecta anti-patterns de workers no CI/CD antes do deploy |
| **Asserções no Pest** | `toBeLeakless()` e `toRunCleanly()` | Testes automatizados unitários e de integração |
