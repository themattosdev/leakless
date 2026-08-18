<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Guards;

use Throwable;

final class FileDescriptorGuard
{
    private readonly string $procFdPath;

    public function __construct(string $procFdPath = '/proc/self/fd')
    {
        $this->procFdPath = $procFdPath;
    }

    /**
     * Capture a snapshot map of currently open file descriptors [fd => targetPath].
     *
     * @return array<int, string>
     */
    public function captureOpenDescriptors(): array
    {
        if (! is_dir($this->procFdPath) || ! is_readable($this->procFdPath)) {
            return [];
        }

        $descriptors = [];

        try {
            $handle = @opendir($this->procFdPath);
            if ($handle === false) {
                return [];
            }

            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..' || ! ctype_digit($entry)) {
                    continue;
                }

                $fd = (int) $entry;
                $linkPath = "{$this->procFdPath}/{$entry}";

                if (! is_link($linkPath)) {
                    continue;
                }

                $target = @readlink($linkPath);
                if ($target === false) {
                    continue;
                }

                // Ignore transient opendir handle for /proc/self/fd itself
                if (str_contains($target, 'proc') && str_contains($target, 'fd')) {
                    continue;
                }

                $descriptors[$fd] = $target;
            }

            closedir($handle);
        } catch (Throwable) {
            return [];
        }

        return $descriptors;
    }

    /**
     * Audit currently open file descriptors against an initial snapshot.
     * Identifies descriptors that were opened during the request cycle and left unclosed.
     *
     * @param  array<int, string>  $initialDescriptors
     * @return array{
     *     detected: bool,
     *     leakedCount: int,
     *     leakedDescriptors: array<int, string>
     * }
     */
    public function audit(array $initialDescriptors): array
    {
        $currentDescriptors = $this->captureOpenDescriptors();

        if (empty($initialDescriptors) && empty($currentDescriptors)) {
            return [
                'detected' => false,
                'leakedCount' => 0,
                'leakedDescriptors' => [],
            ];
        }

        $leaked = [];

        foreach ($currentDescriptors as $fd => $target) {
            // If the descriptor was not present in the initial snapshot
            if (! array_key_exists($fd, $initialDescriptors)) {
                // Ignore transient directory handles for /proc/self/fd
                if (str_contains($target, 'proc') && str_contains($target, 'fd')) {
                    continue;
                }

                $leaked[$fd] = $target;
            }
        }

        return [
            'detected' => count($leaked) > 0,
            'leakedCount' => count($leaked),
            'leakedDescriptors' => $leaked,
        ];
    }
}
