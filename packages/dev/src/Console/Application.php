<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Dev\Console;

use Symfony\Component\Console\Application as BaseApplication;

final class Application extends BaseApplication
{
    public function __construct()
    {
        parent::__construct('Leakless Dev CLI', '0.1.0');
    }
}
