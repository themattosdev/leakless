<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Attributes;

use Attribute;

/**
 * Attribute to mark static properties or classes that intentionally retain
 * immutable, boot-warmed persistent state across worker request cycles.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS)]
final readonly class AllowPersistentState
{
    public function __construct(
        public ?string $reason = null,
    ) {}
}
