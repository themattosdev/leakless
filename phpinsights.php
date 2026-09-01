<?php

declare(strict_types=1);

use NunoMaduro\PhpInsights\Domain\Insights\CyclomaticComplexityIsHigh;
use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenTraits;
use NunoMaduro\PhpInsights\Domain\Insights\MethodCyclomaticComplexityIsHigh;
use NunoMaduro\PhpInsights\Domain\Sniffs\ForbiddenSetterSniff;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Files\LineLengthSniff;
use SlevomatCodingStandard\Sniffs\Classes\ForbiddenPublicPropertySniff;
use SlevomatCodingStandard\Sniffs\Functions\FunctionLengthSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\DisallowMixedTypeHintSniff;

return [
    'preset' => 'default',
    'ide' => 'vscode',
    'exclude' => [
        'tests',
        'vendor',
        'packages/dev/src/PHPStan/Rules',
    ],
    'add' => [],
    'remove' => [
        ForbiddenTraits::class,
        DisallowMixedTypeHintSniff::class,
        ForbiddenPublicPropertySniff::class,
        ForbiddenSetterSniff::class,
    ],

    'config' => [
        CyclomaticComplexityIsHigh::class => [
            'maxComplexity' => 20,
        ],
        MethodCyclomaticComplexityIsHigh::class => [
            'maxMethodComplexity' => 7,
        ],
        FunctionLengthSniff::class => [
            'maxLength' => 40,
        ],

        LineLengthSniff::class => [
            'lineLimit' => 120,
            'absoluteLineLimit' => 140,
        ],
    ],
    'requirements' => [
        'min-quality' => 85,
        'min-complexity' => 80,
        'min-architecture' => 85,
        'min-style' => 85,
    ],
];
