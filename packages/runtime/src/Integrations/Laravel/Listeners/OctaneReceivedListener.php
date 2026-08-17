<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Integrations\Laravel\Listeners;

use TheMattos\Leakless\Leakless;

final class OctaneReceivedListener
{
    public function __construct(
        private readonly Leakless $guardian,
    ) {}

    public function handle(mixed $event = null): void
    {
        $this->guardian->startRequest();
    }
}
