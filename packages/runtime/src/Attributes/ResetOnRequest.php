<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Attributes;

use Attribute;

/**
 * Attribute to mark properties, classes, or methods that require explicit state reset
 * at the end of each request cycle in persistent runtimes.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class ResetOnRequest
{
    /**
     * @param  string|null  $resetter  Optional method name or custom callback identifier to execute on reset.
     * @param  mixed  $default  Optional fallback default value to restore upon request completion.
     */
    public function __construct(
        public ?string $resetter = null,
        public mixed $default = null,
    ) {}
}
