<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Integrations\FrankenPhp;

use Closure;
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Leakless;

final class FrankenPhp
{
    /**
     * Run a guarded FrankenPHP worker loop with zero-config or custom Config.
     *
     * @param  (Closure(): mixed)  $app  The application handler closure to execute on every request.
     * @param  Config|null  $config  Optional custom Leakless configuration.
     * @param  Leakless|null  $guardian  Optional pre-configured Leakless instance.
     * @param  (Closure(Closure): bool)|null  $requestHandlerRunner  Optional custom request runner for testing or custom dispatch.
     * @param  int|null  $maxLoops  Maximum loops to run (null for infinite until broken).
     */
    public static function run(
        Closure $app,
        ?Config $config = null,
        ?Leakless $guardian = null,
        ?Closure $requestHandlerRunner = null,
        ?int $maxLoops = null,
    ): Leakless {
        $guardian ??= new Leakless($config);

        $loops = 0;

        while ($maxLoops === null || $loops < $maxLoops) {
            $loops++;
            $guardian->startRequest();

            $shouldBreak = false;

            try {
                if ($requestHandlerRunner !== null) {
                    $continue = $requestHandlerRunner($app);
                    if (! $continue) {
                        $shouldBreak = true;
                    }
                } elseif (function_exists('frankenphp_handle_request')) {
                    $continue = frankenphp_handle_request($app);
                    if (! $continue) {
                        $shouldBreak = true;
                    }
                } else {
                    // Direct invocation fallback if running in CLI without native frankenphp module
                    $app();
                }
            } finally {
                $report = $guardian->endRequest();

                if ($report->shouldRecycle) {
                    $shouldBreak = true;
                }
            }

            if ($shouldBreak) {
                break;
            }
        }

        return $guardian;
    }
}
