# Como Detectar & Corrigir Vazamentos de Memória em Workers PHP

Ao executar PHP em runtimes persistentes de longa duração (**FrankenPHP**, **Laravel Octane**, **RoadRunner**, **Swoole** ou **workers de fila CLI**), vazamentos de memória (*memory leaks*) são a causa número 1 de instabilidade, paradas em produção e reinicializações inesperadas de containers.

Este guia explica por que workers PHP persistentes acumulam memória, por que as funções padrão do PHP falham em diagnosticar o problema e como eliminar o *memory drift* em definitivo.

---

## Sintomas Comuns de Vazamento de Memória no PHP

| Sintoma | O Que Significa | Causa Raiz |
| :--- | :--- | :--- |
| **Docker reiniciando com `exit 137` (SIGKILL)** | O Linux OOM Killer encerrou o processo bruscamente. | O Resident Set Size (RSS) físico do processo ultrapassou o limite do container. |
| **Workers caindo juntos em picos de tráfego** | Todos os pods atingiram o teto de RAM no mesmo instante (*restart storms*). | Ausência de janelas de cooldown de reciclagem e falta de jitter de desincronização. |
| **`memory_get_usage()` está baixo, mas a RAM do Docker está em 95%** | A memória interna da Zend VM está limpa, mas a memória física do SO está cheia. | Alocações nativas de extensões em C (fora da heap do PHP). |
| **Worker de fila ficando lento após 1.000 jobs** | O Garbage Collector gasta cada vez mais CPU com memória fragmentada. | Referências circulares não coletadas e arrays estáticos sem limite. |

---

## Por Que `memory_get_usage()` Falha em Workers Persistentes

A grande maioria dos desenvolvedores PHP utiliza `memory_get_usage()` para monitorar a aplicação:

```php
// ALERTA: Esta função mede apenas a heap interna da Zend Engine!
$memoryMb = memory_get_usage(true) / 1024 / 1024;
```

### O Problema: Heap da Zend vs. RSS Real do Kernel Linux
1. **Alocador da Zend Engine (`emalloc`)**: `memory_get_usage()` rastreia somente blocos alocados pelo gerenciador de memória interno do PHP (ZMM).
2. **Alocações Nativas em C (`malloc`)**: Extensões PHP amplamente utilizadas (`ext-imagick`, `ext-gd`, `ext-curl`, `ext-pdo`, `ext-xml`, `ext-grpc`, `ext-protobuf`) alocam memória diretamente através da biblioteca C do sistema operacional (`malloc()`).
3. **Fragmentação de Memória**: Em processos que rodam por dias, a fragmentação da `glibc` impede que páginas de memória retornem ao kernel Linux, mesmo após as variáveis PHP serem liberadas com `unset()`.

**Resultado:** `memory_get_usage()` reporta estáveis 30 MB, enquanto o kernel Linux mede o processo em 512 MB e o finaliza abruptamente com `SIGKILL`.

---

## A Solução: Medição do RSS Real via `/proc/self/statm`

No Linux, a única fonte real e precisa de memória física de um processo é o sistema virtual de arquivos `/proc`.

O Leakless lê `/proc/self/statm` diretamente com impacto de microssegundos:

```php
use TheMattos\Leakless\Support\ProcStatmParser;

$parser = new ProcStatmParser();
$metrics = $parser->parse();

echo "RAM Física Real no Kernel: {$metrics->rssMb} MB";
```

---

## Estratégia em 3 Passos para Eliminar Vazamentos em Workers

### Passo 1: Filtrar Picos Passageiros com Reavaliação Pós-GC
Nunca recicle um worker por causa de um pico pontual de uma única requisição pesada. Ao ultrapassar o limite, o Leakless executa `gc_collect_cycles()` e relê a RAM física antes de confirmar uma violação:

```php
$config = new Config(
    maxDriftMb: 64,          // Permite até 64 MB de crescimento acima do baseline do worker
    triggerGcOnBreach: true, // Coleta lixo cíclico antes de confirmar
);
```

### Passo 2: Utilizar Histerese (Violações Consecutivas)
Um worker só deve ser reiniciado se permanecer acima do teto de forma persistente por $N$ requisições consecutivas:

```php
$config = new Config(
    consecutiveViolationsThreshold: 5, // Deve estourar 5 vezes seguidas
);
```

### Passo 3: Prevenir Tempestades de Reinício com Jitter & Cooldown
Quando todos os workers recebem cargas de tráfego semelhantes, eles tendem a atingir limites de memória no mesmo momento. O Leakless introduz:
- **`driftJitterPercentage: 10`**: Aplica uma variação pseudo-aleatória ao teto de cada worker, desincronizando reinicializações.
- **`recycleCooldownSeconds: 10`**: Garante um intervalo mínimo seguro entre reciclagens.

---

## Proteção Automatizada com o Leakless

```php
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Leakless;

$guardian = new Leakless(new Config(
    maxDriftMb: 64,
    consecutiveViolationsThreshold: 5,
    recycleCooldownSeconds: 10,
    triggerGcOnBreach: true,
    driftJitterPercentage: 10,
));

$guardian->startRequest();

try {
    $response = $app->handle($request);
} finally {
    $report = $guardian->endRequest();

    if ($report->shouldRecycle) {
        // Recicla o worker graciosamente após entregar a resposta HTTP atual
        $server->recycle();
    }
}
```
