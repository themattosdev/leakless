<?php

declare(strict_types=1);

namespace Tests\Fixtures\PHPStan;

use Illuminate\Http\Request;

class EphemeralInjectionFixture
{
    public function __construct(
        private Request $request,
    ) {}
}

class EphemeralInjectionController
{
    public function __construct(
        private Request $request,
    ) {}
}

class EphemeralSafeMethod
{
    public function handle(Request $request): void {}
}

class EphemeralUntypedParam
{
    public function __construct($untyped) {}
}

