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
                $target = $this->resolveDescriptorTarget($entry);
                if ($target !== null) {
                    $descriptors[(int) $entry] = $target;
                }
            }

            closedir($handle);
        } catch (Throwable) {
            return [];
        }

        return $descriptors;
    }

    private function resolveDescriptorTarget(string $entry): ?string
    {
        if ($entry === '.' || $entry === '..' || ! ctype_digit($entry)) {
            return null;
        }

        $linkPath = "{$this->procFdPath}/{$entry}";
        if (! is_link($linkPath)) {
            return null;
        }

        $target = @readlink($linkPath);
        if ($target === false || $this->isTransientProcHandle($target)) {
            return null;
        }

        return $target;
    }

    private function isTransientProcHandle(string $target): bool
    {
        return str_contains($target, 'proc') && str_contains($target, 'fd');
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
            if (! array_key_exists($fd, $initialDescriptors) && ! $this->isTransientProcHandle($target)) {
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

