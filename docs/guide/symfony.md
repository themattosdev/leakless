# Symfony Integration

When running [Symfony](https://symfony.com/) applications in long-running persistent runtimes (such as FrankenPHP worker mode or the `baldinof/roadrunner-bundle`), the Symfony kernel and service container persist across requests.

While Symfony provides `Symfony\Contracts\Service\ResetInterface` for services managed inside its container, Leakless acts as an external safety guardian to handle:
- Memory drift monitoring at the Linux kernel level (`/proc/self/statm`).
- Automatic rollback of uncommitted PDO/Doctrine transactions.
- Cleanup of static properties and non-container legacy services.
- Restoration of unclosed output buffers, timezones, and error levels.

---

## Symfony Event Listener Implementation

You can integrate Leakless into Symfony using a dedicated Event Subscriber or Event Listener listening to kernel lifecycle events:

```php
<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Leakless;

final class LeaklessKernelSubscriber implements EventSubscriberInterface
{
    private Leakless $guardian;

    public function __construct()
    {
        $this->guardian = new Leakless(new Config(
            maxDriftMb: 64,
            consecutiveViolationsThreshold: 5,
            checkTransactions: true,
            resettables: [
                // Non-container services or legacy caches
                fn () => LegacySessionHolder::$data = [],
            ],
        ));
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 1024],
            KernelEvents::TERMINATE => ['onKernelTerminate', -1024],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (! $event->isMainRequest()) {
            return;
        }

        $this->guardian->startRequest();
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (! $event->isMainRequest()) {
            return;
        }

        $report = $this->guardian->endRequest([
            'path' => $event->getRequest()->getPathInfo(),
            'status' => $event->getResponse()->getStatusCode(),
        ]);

        if ($report->shouldRecycle) {
            // Signal worker stop based on runtime environment (e.g. frankenphp / roadrunner)
            if (function_exists('frankenphp_finish_request')) {
                exit(0);
            }
        }
    }
}
```

---

## Interoperability with `ResetInterface`

Symfony services implementing `Symfony\Contracts\Service\ResetInterface` are reset by Symfony's `ServicesResetter`.

Leakless complements this by:
1. Supporting any class or instance with a `reset()` method in `Config::$resettables`.
2. Providing the `#[ResetOnRequest]` attribute for fine-grained property-level resets.
3. Catching leaks that occur **outside** Symfony's container (e.g., direct PDO connections, C-extension memory growth, global `$GLOBALS` and static properties).
