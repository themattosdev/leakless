<?php

declare(strict_types=1);

namespace Tests\Integration\Documentation;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Leakless;

class SymfonySampleLegacySession
{
    /** @var array<string, mixed> */
    public static array $data = [];
}

final class SampleLeaklessKernelSubscriber implements EventSubscriberInterface
{
    public Leakless $guardian;

    public bool $recycled = false;

    public function __construct()
    {
        $this->guardian = new Leakless(new Config(
            maxDriftMb: 64,
            consecutiveViolationsThreshold: 5,
            checkTransactions: true,
            resettables: [
                fn () => SymfonySampleLegacySession::$data = [],
            ],
        ));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
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
            $this->recycled = true;
        }
    }
}

test('symfony event subscriber example from documentation executes and cleans state', function () {
    $dispatcher = new EventDispatcher;
    $subscriber = new SampleLeaklessKernelSubscriber;
    $dispatcher->addSubscriber($subscriber);

    $kernel = new class implements HttpKernelInterface
    {
        public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
        {
            return new Response('OK');
        }
    };

    $request = Request::create('/api/checkout', 'POST');
    $response = new Response('OK', 200);

    // 1. Dispatch KernelEvents::REQUEST
    $requestEvent = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    $dispatcher->dispatch($requestEvent, KernelEvents::REQUEST);

    // 2. Simulate application logic mutating legacy session
    SymfonySampleLegacySession::$data = ['user_id' => 1234];
    expect(SymfonySampleLegacySession::$data)->not->toBeEmpty();

    // 3. Dispatch KernelEvents::TERMINATE
    $terminateEvent = new TerminateEvent($kernel, $request, $response);
    $dispatcher->dispatch($terminateEvent, KernelEvents::TERMINATE);

    // 4. Assert that state was cleaned
    expect(SymfonySampleLegacySession::$data)->toBeEmpty();
});
