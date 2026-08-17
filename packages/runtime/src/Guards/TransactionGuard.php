<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Guards;

use PDO;
use Throwable;

final class TransactionGuard
{
    /**
     * @var array<int, PDO>
     */
    private array $connections = [];

    /**
     * Register a PDO connection to be monitored.
     */
    public function registerConnection(PDO $connection): self
    {
        if (! in_array($connection, $this->connections, true)) {
            $this->connections[] = $connection;
        }

        return $this;
    }

    /**
     * Remove all registered connections from the guard.
     */
    public function clearConnections(): void
    {
        $this->connections = [];
    }

    /**
     * Inspect all registered PDO connections for dangling transactions.
     * If an active uncommitted transaction is found, defensively roll it back.
     *
     * @param  array<int, PDO>  $additionalConnections  Extra connections to audit on the fly.
     * @return array{
     *     detected: bool,
     *     rolledBackCount: int,
     *     errors: array<int, string>
     * }
     */
    public function auditAndRollback(array $additionalConnections = []): array
    {
        $allConnections = array_merge($this->connections, $additionalConnections);
        $rolledBackCount = 0;
        $errors = [];

        foreach ($allConnections as $connection) {
            try {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                    $rolledBackCount++;
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        return [
            'detected' => $rolledBackCount > 0,
            'rolledBackCount' => $rolledBackCount,
            'errors' => $errors,
        ];
    }
}
