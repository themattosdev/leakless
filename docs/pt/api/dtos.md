# Atributos & Relatórios Diagnósticos

Esta seção demonstra como interagir com os relatórios de diagnóstico e atributos fornecidos pelo Leakless.

---

## 1. Utilizando o Objeto `Report`

Ao final de cada ciclo de requisição, o `$leakless->endRequest()` retorna um objeto `Report` contendo dados de diagnóstico e métricas de memória.

### Exemplo Prático de Uso

```php
$report = $leakless->endRequest();

// 1. Verificar se a requisição executou mantendo o estado 100% limpo
if (! $report->isClean) {
    logger()->warning('Anomalia de estado detectada no worker');
}

// 2. Verificar se transações de banco abertas foram interceptadas e revertidas
if ($report->hasTransactionLeak) {
    // Enviar alerta ou métrica para Prometheus, Datadog ou Sentry
    metrics()->increment('worker.transactions.rolled_back');
}

// 3. Inspecionar métricas de memória física real do Linux (RSS)
echo "Memória física consumida nesta requisição: {$report->memoryDriftMb} MB\n";
echo "RAM física atual do worker (RSS): {$report->metricsAfter->rssMb} MB\n";

// 4. Verificar se o worker atingiu o teto de memória ou limite de requisições
if ($report->shouldRecycle) {
    // Finalizar o loop ou sinalizar o gerenciador de processos
    $worker->stop();
}
```

### Propriedades Disponíveis

| Propriedade | Tipo | Descrição |
| :--- | :---: | :--- |
| `$report->isClean` | `bool` | `true` se nenhuma transação vazou e nenhum limite de reciclagem foi atingido. |
| `$report->hasTransactionLeak` | `bool` | `true` se uma ou mais transações PDO abertas foram revertidas automaticamente. |
| `$report->shouldRecycle` | `bool` | `true` se o teto de memória ou limite de requisições foi ultrapassado. |
| `$report->memoryDriftMb` | `float` | Variação de memória física ($\Delta\text{RSS}$) em megabytes durante a requisição. |
| `$report->metricsBefore` | `ProcessMetrics` | Snapshot de memória antes do início da requisição. |
| `$report->metricsAfter` | `ProcessMetrics` | Snapshot de memória após o término da requisição. |

---

## 2. Métricas de Memória do Processo (`ProcessMetrics`)

As propriedades `$report->metricsBefore` e `$report->metricsAfter` contêm detalhes de memória extraídos do kernel Linux:

```php
$metrics = $report->metricsAfter;

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
