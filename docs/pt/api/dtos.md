# Atributos & Relatórios Diagnósticos

Esta seção demonstra como interagir com os relatórios de diagnóstico e atributos fornecidos pelo Leakless.

---

## 1. Utilizando o Objeto `Report`

Ao final de cada ciclo de requisição, o `$leakless->endRequest()` retorna um objeto `Report` contendo dados de diagnóstico e métricas de memória.

### Exemplo Prático de Uso

```php
$report = $leakless->endRequest();

// 1. Verificar se a requisição executou mantendo o estado 100% limpo
if (! $report->isClean()) {
    logger()->warning('Anomalia de estado detectada no worker');
}

// 2. Verificar se transações de banco abertas foram interceptadas e revertidas
if ($report->danglingTransactionsDetected) {
    // Enviar alerta ou métrica para Prometheus, Datadog ou Sentry
    metrics()->increment('worker.transactions.rolled_back');
}

// 3. Inspecionar métricas de memória física real do Linux (RSS)
echo "Memória física consumida nesta requisição: {$report->memoryDriftMb} MB\n";
echo "RAM física atual do worker (RSS): {$report->finalMetrics->rssMb} MB\n";

// 4. No modo ZTS, inspecionar atribuição da thread
if ($report->isZts) {
    if ($report->unattributedProcessDrift) {
        logger()->warning('RSS do processo subiu sem crescimento no ZMM da thread (ruído de vizinho ou leak em C)');
    }
}

// 5. Verificar se o worker atingiu o teto de memória ou limite de requisições
if ($report->shouldRecycle) {
    // Finalizar o loop ou sinalizar o gerenciador de processos
    $worker->stop();
}
```

### Propriedades e Métodos Disponíveis

| Propriedade / Método | Tipo | Descrição |
| :--- | :---: | :--- |
| `$report->isClean()` | `bool` | Método: retorna `true` se nenhuma transação vazou, nenhum descritor vazou e nenhuma reciclagem foi acionada. |
| `$report->danglingTransactionsDetected` | `bool` | `true` se uma ou mais transações PDO abertas foram revertidas automaticamente. |
| `$report->danglingTransactionsCount` | `int` | Quantidade de transações PDO interceptadas e revertidas. |
| `$report->fileDescriptorsLeaked` | `bool` | `true` se arquivos ou sockets de rede foram esquecidos abertos. |
| `$report->fileDescriptorsLeakedCount` | `int` | Quantidade de file descriptors não fechados. |
| `$report->fileDescriptorsLeakedMap` | `array<int, string>` | Mapa de descritores vazados `[fd => caminho]`. |
| `$report->shouldRecycle` | `bool` | `true` se o teto de memória, limite de drift persistente ou limite de requisições foi ultrapassado. |
| `$report->recycleReason` | `string\|null` | Motivo legível pelo qual a reciclagem do worker foi solicitada. |
| `$report->memoryDriftMb` | `float` | Variação de memória física ($\Delta\text{RSS}$) em megabytes durante a requisição. |
| `$report->zendMemoryDriftMb` | `float` | Variação no Zend Memory Manager da thread ativa durante a requisição. |
| `$report->driftOverBaselineMb` | `float` | Drift acumulado de memória do processo em relação ao baseline do worker. |
| `$report->initialMetrics` | `ProcessMetrics` | Snapshot de memória antes do início da requisição. |
| `$report->finalMetrics` | `ProcessMetrics` | Snapshot de memória após o término da requisição. |
| `$report->isZts` | `bool` | `true` se o runtime estiver executando em modo multithread Zend Thread Safety (ZTS). |
| `$report->driftAttributedToThread` | `bool` | Em modo ZTS: `true` se o drift do RSS foi confirmado pelo crescimento de Zend memory da thread ativa. |
| `$report->unattributedProcessDrift` | `bool` | Em modo ZTS: `true` se o RSS subiu sem crescimento de Zend memory na thread atual. |
| `$report->consecutiveViolationsCount` | `int` | Contagem atual de violações consecutivas de drift. |
| `$report->cooldownActive` | `bool` | `true` se a reciclagem foi suspensa pela janela de cooldown. |

---

## 2. Métricas de Memória do Processo (`ProcessMetrics`)

As propriedades `$report->initialMetrics` e `$report->finalMetrics` contêm detalhes de memória extraídos do kernel Linux:

```php
$metrics = $report->finalMetrics;


// RAM física real ocupada pelo processo em MB (Resident Set Size)
$rssMb = $metrics->rssMb;

// Tamanho total de memória virtual em MB
$virtualMb = $metrics->sizeMb;

// Contagem bruta de páginas do kernel
$residentPages = $metrics->residentPages;
```

---

## 3. O Atributo `#[AllowPersistentState]`

Utilize este atributo para declarar caches estáticos intencionais e thread-safe, excluindo-os de alertas de análise estática e testes de reflexão:

```php
use TheMattos\Leakless\Attributes\AllowPersistentState;

class DatabaseSchemaRegistry
{
    // Permitido: metadados imutáveis e seguros de inicialização
    #[AllowPersistentState]
    public static array $tableDefinitions = [];
}
```

---

## 4. O Atributo `#[ResetOnRequest]`

Utilize este atributo em classes, propriedades ou métodos para declarar estado que deve ser automaticamente restaurado para os valores padrão no término de cada requisição quando registrado no motor de `resettables` do Leakless:

```php
use TheMattos\Leakless\Attributes\ResetOnRequest;

class UserSessionContext
{
    // Restaura o valor padrão null (propriedade estática: resetada registrando a string da classe)
    #[ResetOnRequest]
    public static ?string $activeToken = null;

    // Executa método de limpeza customizado no encerramento da requisição
    #[ResetOnRequest(resetter: 'cleanup')]
    public static array $inMemoryEvents = [];

    // Restaura o valor padrão [] (propriedade de instância: resetada registrando a instância do objeto)
    #[ResetOnRequest(default: [])]
    public array $permissions = [];

    public static function cleanup(): void
    {
        self::$inMemoryEvents = [];
    }
}
```

### Alvos e Parâmetros Suportados

| Parâmetro | Tipo | Padrão | Descrição |
| :--- | :---: | :---: | :--- |
| `resetter` | `string\|null` | `null` | Nome de método customizado na classe a ser invocado no reset. |
| `default` | `mixed` | `null` | Valor de fallback explícito a ser atribuído à propriedade no reset. |
| **Alvos do Atributo** | `Property`, `Class`, `Method` | — | Pode ser colocado em propriedades estáticas ou de instância, classes ou métodos de limpeza. |

---

### Como o ResetOnRequest Funciona em Tempo de Execução

::: tip Requisito de Registro
O `#[ResetOnRequest]` **não executa varredura global de classes ou arquivos**. Ele serve como um conjunto de instruções pré-compiladas para os alvos explicitamente registrados no motor de `resettables`:
- No Laravel: adicione os alvos ao array `'resettables'` em `config/leakless.php`.
- No Symfony/PHP Vanilla: registre os alvos via `Config::$resettables` ou chamando `$leakless->registerResetTarget($alvo)`.
:::

#### Propriedades Estáticas vs. Propriedades de Instância

| Tipo de Registro | Exemplo | O Que é Resetado |
| :--- | :--- | :--- |
| **String da Classe** | `UserSessionContext::class` | **Propriedades estáticas** anotadas com `#[ResetOnRequest]` e **métodos de limpeza estáticos** (ou convencionais estáticos `resetState` / `cleanup`). Propriedades de instância são ignoradas porque nenhuma instância foi informada. |
| **Instância do Objeto** | `$userSessionContext` | **Propriedades de instância** anotadas com `#[ResetOnRequest]`, métodos de limpeza da instância e métodos convencionais `reset()` daquele objeto específico. |

::: warning PHPStan vs. Execução em Produção
A regra de análise estática `BanMutableStaticPropertiesRule` considera propriedades `static` com `#[ResetOnRequest]` como seguras. **Lembre-se sempre de adicionar a classe ao `'resettables'` na sua configuração**, caso contrário a propriedade não será resetada em tempo de execução e continuará vazando dados entre requisições!
:::


