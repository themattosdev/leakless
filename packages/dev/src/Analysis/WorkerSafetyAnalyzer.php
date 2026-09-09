<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Dev\Analysis;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

final class WorkerSafetyAnalyzer
{
    private Parser $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? (new ParserFactory)->createForHostVersion();
    }

    /**
     * Analyze directories or files for persistent worker safety violations.
     *
     * @param  array<int, string>  $paths
     * @return array{
     *     totals: array{errors: int, file_errors: int},
     *     files: array<string, array{errors: int, messages: array<int, array{message: string, line: int, identifier: string}>}>
     * }
     */
    public function analyze(array $paths): array
    {
        $filesToAnalyze = $this->collectPhpFiles($paths);
        $filesReport = [];
        $totalErrors = 0;

        foreach ($filesToAnalyze as $filePath) {
            $violations = $this->analyzeFile($filePath);
            if (count($violations) > 0) {
                $filesReport[$filePath] = [
                    'errors' => count($violations),
                    'messages' => $violations,
                ];
                $totalErrors += count($violations);
            }
        }

        return [
            'totals' => [
                'errors' => 0,
                'file_errors' => count($filesReport),
            ],
            'files' => $filesReport,
        ];
    }

    /**
     * @return array<int, array{message: string, line: int, identifier: string}>
     */
    public function analyzeFile(string $filePath): array
    {
        $content = @file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        try {
            $stmts = $this->parser->parse($content);
            if ($stmts === null) {
                return [];
            }

            $visitor = new WorkerSafetyVisitor;
            $visitor->setFilePath($filePath);

            $traverser = new NodeTraverser;
            $traverser->addVisitor(new NameResolver);
            $traverser->addVisitor($visitor);
            $traverser->traverse($stmts);

            return $visitor->getViolations();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private function collectPhpFiles(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (is_file($path) && str_ends_with($path, '.php')) {
                $files[] = realpath($path) ?: $path;

                continue;
            }

            if (is_dir($path)) {
                $this->scanDirectory($path, $files);
            }
        }

        sort($files);

        return array_unique($files);
    }

    /**
     * @param  array<int, string>  $files
     */
    private function scanDirectory(string $directory, array &$files): void
    {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $realPath = $file->getRealPath();
                    $files[] = $realPath !== false ? $realPath : $file->getPathname();
                }
            }
        } catch (Throwable) {
            // Ignore unreadable directory
        }
    }
}
