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
use TheMattos\Leakless\Dev\Analysis\WorkerSafetyAnalyzer;

final class AnalyzeCommand extends Command
{
    /**
     * @var (callable(array<int, string>): array{int, string, string})|WorkerSafetyAnalyzer|null
     */
    private $analyzerOrRunner;

    /**
     * @param  (callable(array<int, string>): array{int, string, string})|WorkerSafetyAnalyzer|null  $analyzerOrRunner
     */
    public function __construct(callable|WorkerSafetyAnalyzer|null $analyzerOrRunner = null)
    {
        parent::__construct();
        $this->analyzerOrRunner = $analyzerOrRunner;
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
                'Path to a custom configuration file (kept for compatibility)',
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
        $jsonOutput = (bool) $input->getOption('json');
        $resolvedPaths = $this->resolvePaths($paths);

        [$exitCode, $decoded, $stdout, $stderr] = $this->runAnalysis($resolvedPaths);

        if ($jsonOutput) {
            $output->writeln($stdout);

            return $exitCode === 0 ? Command::SUCCESS : Command::FAILURE;
        }

        $this->renderTerminalReport($decoded, $stderr, $resolvedPaths);

        if ($decoded === null || $exitCode !== 0) {
            return Command::FAILURE;
        }

        $fileErrors = $decoded['totals']['file_errors'] ?? 0;
        $totalErrors = $decoded['totals']['errors'] ?? 0;

        return $fileErrors === 0 && $totalErrors === 0
            ? Command::SUCCESS
            : Command::FAILURE;
    }

    /**
     * @param  array<int, string>  $resolvedPaths
     * @return array{int, array{totals?: array{errors?: int, file_errors?: int}, files?: array<string, array{errors?: int, messages?: array<int, array{message: string, line: int, ignorable?: bool, identifier?: string}>}>}|null, string, string}
     */
    private function runAnalysis(array $resolvedPaths): array
    {
        if (is_callable($this->analyzerOrRunner)) {
            [$exitCode, $stdout, $stderr] = ($this->analyzerOrRunner)($resolvedPaths);
            /** @var array{totals?: array{errors?: int, file_errors?: int}, files?: array<string, array{errors?: int, messages?: array<int, array{message: string, line: int, ignorable?: bool, identifier?: string}>}>}|null $decoded */
            $decoded = json_decode($stdout, true);

            return [$exitCode, $decoded, $stdout, $stderr];
        }

        $analyzer = $this->analyzerOrRunner instanceof WorkerSafetyAnalyzer
            ? $this->analyzerOrRunner
            : new WorkerSafetyAnalyzer;

        $report = $analyzer->analyze($resolvedPaths);
        $fileErrors = $report['totals']['file_errors'];
        $totalErrors = $report['totals']['errors'];
        $exitCode = ($fileErrors === 0 && $totalErrors === 0) ? Command::SUCCESS : Command::FAILURE;
        $stdout = (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return [$exitCode, $report, $stdout, ''];
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

        $this->renderReportResults($report);
    }

    /**
     * @param  array{totals?: array{errors?: int, file_errors?: int}, files?: array<string, array{errors?: int, messages?: array<int, array{message: string, line: int, ignorable?: bool, identifier?: string}>}>}  $report
     */
    private function renderReportResults(array $report): void
    {
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

        $this->renderViolatingFiles($report['files'] ?? []);

        render(<<<'HTML'
<div class="mt-2 text-gray-400">
    💡 Hint: Use #[AllowPersistentState] on intentional static properties, or wrap request-scoped dependencies.
</div>
HTML);
    }

    /**
     * @param  array<string, array{errors?: int, messages?: array<int, array{message: string, line: int, ignorable?: bool, identifier?: string}>}>  $files
     */
    private function renderViolatingFiles(array $files): void
    {
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
    }
}
