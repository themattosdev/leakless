<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Dev\Console;

use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\InputInterface;
use TheMattos\Leakless\Dev\Console\Commands\AnalyzeCommand;

final class Application extends BaseApplication
{
    public const VERSION = '0.5.0';

    public function __construct()
    {
        parent::__construct('Leakless', self::VERSION);

        $this->addCommand(new AnalyzeCommand);
    }

    /**
     * Default command name when none is provided.
     */
    protected function getCommandName(InputInterface $input): string
    {
        $firstArg = $input->getFirstArgument();

        return is_string($firstArg) && $firstArg !== '' ? $firstArg : 'analyze';
    }
}
