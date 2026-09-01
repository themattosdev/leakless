# Workers de Fila & Daemons CLI

A execução persistente em PHP não se limita a servidores web HTTP. Consumidores de filas em segundo plano (como RabbitMQ, Redis Queues, Amazon SQS, Apache Kafka, Gearman) e daemons CLI de longa duração frequentemente rodam por horas, dias ou semanas dentro de containers Docker.

Em workers de mensageria e filas, uma transação de banco de dados não finalizada, um array acumulador estático que cresce continuamente ou fragmentação nativa de extensões C (`ext-gd`, `ext-imagick`, `ext-curl`) acabam provocando o `SIGKILL` do Linux OOM Killer, cancelando jobs em processamento e corrompendo confirmações de mensagens (*acks*).

O Leakless fornece exatamente a mesma proteção e isolamento para loops de filas e jobs CLI persistentes.

---

## Loop Genérico de Consumidor de Filas CLI

Seja consumindo de mensageria customizada, de um worker do Symfony Messenger ou de um script PHP puro:

```php
<?php

declare(strict_types=1);

use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Leakless;

require_once __DIR__ . '/vendor/autoload.php';

// 1. Configura o Leakless para processamento contínuo de jobs
$config = new Config(
    maxDriftMb: 64,                     // Crescimento de RSS físico máximo permitido acima do baseline
    consecutiveViolationsThreshold: 3,  // Jobs consecutivos com excesso de memória antes de reciclar
    maxRequests: 5000,                  // Recicla o worker após 5.000 jobs processados
    checkTransactions: true,            // Executa rollback defensivo em transações PDO órfãs
    resettables: [
        App\Services\JobContext::class,
        fn () => LegacyProcessor::$batchData = [],
    ],
);

$leakless = new Leakless($config);

// 2. Consome mensagens em loop persistente
while ($message = $queueConsumer->fetchNextMessage()) {
    // Trata cada mensagem/job como um ciclo de execução isolado
    $leakless->startRequest();

    try {
        $processor->process($message);
        $message->ack();
    } catch (\Throwable $e) {
        $logger->error('Falha no processamento do job: ' . $e->getMessage());
        $message->nack();
    } finally {
        // 3. Rollback de transações, restauração de buffers e avaliação do RSS real
        $report = $leakless->endRequest([
            'job_id' => $message->getId(),
            'queue' => $message->getQueueName(),
        ]);
    }

    // 4. Encerramento Gracioso do Daemon (supervisor/Docker iniciará um novo processo)
    if ($report->shouldRecycle) {
        $logger->info("Reciclando daemon de fila: {$report->recycleReason}");
        exit(0);
    }
}
```

---

## Por Que Workers de Fila Precisam do Leakless

1. **Transações Órfãs entre Jobs**: Se o Job nº 1 lançar uma exceção não tratada dentro de um `beginTransaction()`, a conexão PDO permanece aberta em transação. Quando o Job nº 2 iniciar no mesmo processo, suas queries ficarão silenciosamente presas dentro da transação do Job anterior. O Leakless garante o rollback automático entre jobs.
2. **Auditoria Real de Memória no Kernel**: As funções padrão do PHP (`memory_get_usage()`) só enxergam a heap da máquina virtual Zend. Extensões em C (geradores de PDF, redimensionamento de imagens, parsers XML, gRPC) alocam memória via `malloc()`. O Leakless monitora o RSS físico real em `/proc/self/statm`.
3. **Proteção contra Tempestades de Reinício**: Se uma rajada de mensagens pesadas elevar o uso de memória, o **jitter** e a **histerese (violações consecutivas)** do Leakless impedem que todos os 50 pods de fila reiniciem exatamente no mesmo segundo.
