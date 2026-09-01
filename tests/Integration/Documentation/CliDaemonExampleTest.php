<?php

declare(strict_types=1);

namespace Tests\Integration\Documentation;

use PDO;
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Leakless;

class SampleQueueJobContext
{
    /** @var array<string, mixed> */
    public static array $batchData = [];

    public static function cleanup(): void
    {
        self::$batchData = [];
    }
}

class SampleQueueMessage
{
    public function __construct(
        private string $id,
        private string $queue,
        public bool $failTransaction = false,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getQueueName(): string
    {
        return $this->queue;
    }
}

test('cli queue daemon example from documentation audits jobs, rolls back transactions, and cleans state', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE queue_events (id INTEGER PRIMARY KEY, name TEXT)');

    $config = new Config(
        maxDriftMb: 64,
        consecutiveViolationsThreshold: 3,
        maxRequests: 3,
        checkTransactions: true,
        resettables: [
            SampleQueueJobContext::class,
        ],
    );

    $leakless = new Leakless($config);
    $leakless->registerConnection($pdo);

    $messages = [
        new SampleQueueMessage('job-1', 'emails'),
        new SampleQueueMessage('job-2', 'emails', failTransaction: true),
        new SampleQueueMessage('job-3', 'emails'),
    ];

    $processed = 0;
    $shouldRecycle = false;

    foreach ($messages as $message) {
        $leakless->startRequest();
        $shouldRecycleJob = false;

        try {
            SampleQueueJobContext::$batchData = ['job' => $message->getId()];

            if ($message->failTransaction) {
                $pdo->beginTransaction();
                $pdo->exec("INSERT INTO queue_events (name) VALUES ('failed_job')");
                // Simulating an exception that skips commit
                throw new \RuntimeException('Job crashed during transaction');
            }
        } catch (\Throwable $e) {
            // Handled
        } finally {
            $report = $leakless->endRequest([
                'job_id' => $message->getId(),
                'queue' => $message->getQueueName(),
            ]);

            $shouldRecycleJob = $report->shouldRecycle;
            $processed++;
        }

        if ($shouldRecycleJob) {
            $shouldRecycle = true;
            break;
        }
    }

    expect($processed)->toBe(3)
        ->and($shouldRecycle)->toBeTrue()
        ->and($pdo->inTransaction())->toBeFalse()
        ->and(SampleQueueJobContext::$batchData)->toBeEmpty();
});
