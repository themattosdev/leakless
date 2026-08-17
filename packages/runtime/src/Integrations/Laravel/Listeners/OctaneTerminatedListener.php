<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Integrations\Laravel\Listeners;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use PDO;
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

        $this->guardian->endRequest($metadata, $activePdos);
    }

    /**
     * Inspect Laravel DatabaseManager to extract all active PDO instances.
     *
     * @return array<int, PDO>
     */
    private function collectActivePdoConnections(): array
    {
        $pdos = [];

        if (! $this->app->bound('db')) {
            return $pdos;
        }

        try {
            /** @var DatabaseManager $db */
            $db = $this->app->make('db');
            /** @var array<string, Connection> $connections */
            $connections = $db->getConnections();

            foreach ($connections as $connection) {
                try {
                    $pdo = $connection->getPdo();
                    if (! in_array($pdo, $pdos, true)) {
                        $pdos[] = $pdo;
                    }
                } catch (Throwable) {
                    // Connection not yet established or already closed
                }
            }
        } catch (Throwable) {
            // Database service unavailable
        }

        return $pdos;
    }

    /**
     * Extract request/response metadata if available from the Octane event.
     *
     * @return array<string, mixed>
     */
    private function extractMetadata(mixed $event): array
    {
        $metadata = [];

        if (is_object($event)) {
            if (isset($event->request) && is_object($event->request) && method_exists($event->request, 'path')) {
                $metadata['path'] = (string) $event->request->path();
                $metadata['method'] = method_exists($event->request, 'method') ? (string) $event->request->method() : 'GET';
            }

            if (isset($event->response) && is_object($event->response) && method_exists($event->response, 'getStatusCode')) {
                $metadata['status'] = (int) $event->response->getStatusCode();
            }
        }

        return $metadata;
    }
}
