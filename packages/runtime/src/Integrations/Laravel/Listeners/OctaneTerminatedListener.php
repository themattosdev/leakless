<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Integrations\Laravel\Listeners;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use PDO;
use Psr\Log\LoggerInterface;
use TheMattos\Leakless\Leakless;
use Throwable;

final class OctaneTerminatedListener
{
    public function __construct(
        private readonly Leakless $guardian,
        private readonly Application $app,
    ) {}

    public function handle(mixed $event = null): void
    {
        $activePdos = $this->collectActivePdoConnections();
        $metadata = $this->extractMetadata($event);

        $report = $this->guardian->endRequest($metadata, $activePdos);

        if ($report->shouldRecycle) {
            $this->signalOctaneRecycle();
        }
    }

    /**
     * Inspect Laravel DatabaseManager to extract all active PDO instances.
     *
     * @return array<int, PDO>
     */
    private function collectActivePdoConnections(): array
    {
        if (! $this->app->bound('db')) {
            return [];
        }

        try {
            /** @var DatabaseManager $db */
            $db = $this->app->make('db');
            /** @var array<string, Connection> $connections */
            $connections = $db->getConnections();

            return $this->extractUniquePdos($connections);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, Connection>  $connections
     * @return array<int, PDO>
     */
    private function extractUniquePdos(array $connections): array
    {
        $pdos = [];
        foreach ($connections as $connection) {
            $pdo = $this->extractConnectionPdo($connection);
            if ($pdo !== null && ! in_array($pdo, $pdos, true)) {
                $pdos[] = $pdo;
            }
        }

        return $pdos;
    }


    private function extractConnectionPdo(Connection $connection): ?PDO
    {
        try {
            return $connection->getPdo();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Extract request/response metadata if available from the Octane event.
     *
     * @return array<string, mixed>
     */
    private function extractMetadata(mixed $event): array
    {
        if (! is_object($event)) {
            return [];
        }

        $metadata = [];
        $this->extractRequestMetadata($event, $metadata);
        $this->extractResponseMetadata($event, $metadata);

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function extractRequestMetadata(object $event, array &$metadata): void
    {
        if (! isset($event->request) || ! is_object($event->request) || ! method_exists($event->request, 'path')) {
            return;
        }

        $metadata['path'] = (string) $event->request->path();
        $metadata['method'] = method_exists($event->request, 'method') ? (string) $event->request->method() : 'GET';
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function extractResponseMetadata(object $event, array &$metadata): void
    {
        if (isset($event->response) && is_object($event->response) && method_exists($event->response, 'getStatusCode')) {
            $metadata['status'] = (int) $event->response->getStatusCode();
        }
    }


    /**
     * Notify Laravel Octane to gracefully stop the current worker and spawn a fresh replacement.
     */
    private function signalOctaneRecycle(): void
    {
        if (! $this->app->bound('octane')) {
            return;
        }

        try {
            $octane = $this->app->make('octane');
            if (is_object($octane) && method_exists($octane, 'stopWorker')) {
                $octane->stopWorker();
            }
        } catch (Throwable $e) {
            $this->logOctaneError($e);
        }
    }

    private function logOctaneError(Throwable $e): void
    {
        $message = "[Leakless] Failed to signal Octane worker stop: {$e->getMessage()}";

        if (! $this->app->bound('log')) {
            error_log($message);

            return;
        }

        try {
            /** @var LoggerInterface $log */
            $log = $this->app->make('log');
            $log->error($message, ['exception' => $e]);
        } catch (Throwable) {
            error_log($message);
        }
    }
}

