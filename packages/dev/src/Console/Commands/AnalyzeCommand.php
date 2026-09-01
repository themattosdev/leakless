<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Dev\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Termwind\render;

use Termwind\Termwind;

final class AnalyzeCommand extends Command
{
    /**
     * @var (callable(array<int, string>): array{int, string, string})|null
     */
    private $processRunner;

    /**
     * @param  (callable(array<int, string>): array{int, string, string})|null  $processRunner
     */
    public function __construct(?callable $processRunner = null)
    {
        parent::__construct();
        $this->processRunner = $processRunner;
    }

    protected function configure(): void
    {
        $this
            ->setName('analyze')
            ->setDescription('Run static analysis on application source code for persistent worker safety')
            ->addArgument(
                'paths',
                InputArgument::IS_ARRAY,
                'Directories or files to analyze (defaults to app and src)',
                [],
            )
            ->addOption(
                'configuration',
                'c',
                InputOption::VALUE_REQUIRED,
                'Path to a custom phpstan.neon configuration file',
            )
            ->addOption(
                'memory-limit',
                'm',
                InputOption::VALUE_REQUIRED,
                'Memory limit for the analyzer',
                '256M',
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output results as JSON',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Termwind::renderUsing($output);

        /** @var array<int, string> $paths */
        $paths = (array) $input->getArgument('paths');
        /** @var string|null $configPath */
        $configPath = $input->getOption('configuration');
        $memoryLimitRaw = $input->getOption('memory-limit');
        $memoryLimit = is_string($memoryLimitRaw) ? $memoryLimitRaw : '256M';
        $jsonOutput = (bool) $input->getOption('json');

        $resolvedPaths = $this->resolvePaths($paths);
        $extensionNeonPath = realpath(__DIR__.'/../../../extension.neon');

        if ($extensionNeonPath === false) {
            $extensionNeonPath = __DIR__.'/../../../extension.neon';
        }

        $command = [
            PHP_BINARY,
            $this->locatePhpstanBinary(),
            'analyse',
            '--error-format=json',
            '--no-progress',
            '--level=0',
            '--memory-limit='.$memoryLimit,
            '-c',
            $configPath ?? $extensionNeonPath,
            ...$resolvedPaths,
        ];

        if ($this->processRunner !== null) {
            [$exitCode, $stdout, $stderr] = ($this->processRunner)($command);
        } else {
            $process = proc_open(
                $command,
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                getcwd() ?: null,
            );

            if (! is_resource($process)) {
                $output->writeln('<error>Failed to execute PHPStan analysis engine.</error>');

                return Command::FAILURE;
            }

            fclose($pipes[0]);
            $stdout = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);
        }

        /** @var array{totals?: array{errors?: int, file_errors?: int}, files?: array<string, array{errors?: int, messages?: array<int, array{message: string, line: int, ignorable?: bool, identifier?: string}>}>}|null $decoded */
        $decoded = json_decode($stdout, true);

        if ($jsonOutput) {
            $output->writeln($stdout);

            return $exitCode === 0 ? Command::SUCCESS : Command::FAILURE;
        }

        $this->renderTerminalReport($decoded, $stderr, $resolvedPaths);

        if ($decoded === null || $exitCode !== 0) {
            return Command::FAILURE;
        }

        return ($decoded['totals']['file_errors'] ?? 0) === 0 && ($decoded['totals']['errors'] ?? 0) === 0
            ? Command::SUCCESS
            : Command::FAILURE;
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private function resolvePaths(array $paths): array
    {
        if (count($paths) > 0) {
            return $paths;
        }

        $defaults = [];
        if (is_dir('app')) {
            $defaults[] = 'app';
        }
        if (is_dir('src')) {
            $defaults[] = 'src';
        }

        return count($defaults) > 0 ? $defaults : ['.'];
    }

    private function locatePhpstanBinary(): string
    {
        $candidates = [
            'vendor/bin/phpstan',
            __DIR__.'/../../../../../vendor/bin/phpstan',
            __DIR__.'/../../../../bin/phpstan',
        ];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return 'vendor/bin/phpstan';
    }

    /**
     * @param  array{totals?: array{errors?: int, file_errors?: int}, files?: array<string, array{errors?: int, messages?: array<int, array{message: string, line: int, ignorable?: bool, identifier?: string}>}>}|null  $report
     * @param  array<int, string>  $paths
     */
    private function renderTerminalReport(?array $report, string $stderr, array $paths): void
    {
        $pathsStr = implode(', ', $paths);

        render(<<<HTML
<div class="mt-1 mb-1">
    <span class="px-1 bg-blue-600 text-white font-bold">LEAKLESS</span>
    <span class="text-gray-400 font-bold ml-1">Static Worker Analysis</span>
    <span class="text-gray-500 ml-1">({$pathsStr})</span>
</div>
HTML);

        if ($report === null) {
            $safeStderr = htmlspecialchars($stderr);
            render(<<<HTML
<div class="my-1 text-red-400">
    Analysis engine execution failed:
    <pre class="text-gray-400">{$safeStderr}</pre>
</div>
HTML);

            return;
        }

        $fileErrors = $report['totals']['file_errors'] ?? 0;
        $totalErrors = $report['totals']['errors'] ?? 0;
        $allErrorsCount = $fileErrors + $totalErrors;

        if ($allErrorsCount === 0) {
            render(<<<'HTML'
<div class="my-1 p-1 bg-green-700 text-white font-bold">
    ✓ PASS: No memory leaks or persistent worker anti-patterns detected.
</div>
HTML);

            return;
        }

        render(<<<HTML
<div class="my-1 p-1 bg-red-700 text-white font-bold">
    ✕ FAIL: Found {$allErrorsCount} worker violation(s) in codebase.
</div>
HTML);

        $files = $report['files'] ?? [];
        foreach ($files as $filePath => $fileData) {
            $messages = $fileData['messages'] ?? [];
            if (count($messages) === 0) {
                continue;
            }

            $relativePath = str_replace(getcwd().'/', '', $filePath);

            render(<<<HTML
<div class="mt-1 font-bold text-yellow-400">
    {$relativePath}
</div>
HTML);

            foreach ($messages as $msg) {
                $line = $msg['line'];
                $messageText = htmlspecialchars($msg['message']);
                $identifier = htmlspecialchars($msg['identifier'] ?? 'leakless.violation');

                render(<<<HTML
<div class="ml-2 text-gray-300">
    <span class="text-gray-500 font-bold">Line {$line}:</span> {$messageText}
    <span class="text-gray-500">({$identifier})</span>
</div>
HTML);
            }
        }

        render(<<<'HTML'
<div class="mt-2 text-gray-400">
    💡 Hint: Use #[AllowPersistentState] on intentional static properties, or wrap request-scoped dependencies.
</div>
HTML);
    }
}
