<?php

declare(strict_types=1);

arch('strict types are enforced across all files')
    ->expect(['TheMattos\Leakless', 'TheMattos\Leakless\Dev', 'Tests'])
    ->toUseStrictTypes();

arch('no debugging statements are left in the codebase')
    ->expect(['TheMattos\Leakless', 'TheMattos\Leakless\Dev'])
    ->not->toUse(['dd', 'dump', 'var_dump', 'print_r', 'ray']);

arch('runtime package does not depend on dev package or symfony console')
    ->expect('TheMattos\Leakless')
    ->not->toUse([
        'TheMattos\Leakless\Dev',
        'Symfony\Component\Console',
        'PHPStan',
    ]);
