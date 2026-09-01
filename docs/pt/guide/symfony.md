# Integração com Symfony

Ao executar aplicações [Symfony](https://symfony.com/) em runtimes persistentes (como o modo worker do FrankenPHP ou o pacote `baldinof/roadrunner-bundle`), o kernel do Symfony e o container de serviços permanecem ativos na memória entre as requisições.

Enquanto o Symfony fornece a interface `Symfony\Contracts\Service\ResetInterface` para serviços gerenciados pelo seu container, o Leakless atua como um guardião externo de segurança para cobrir:
- Monitoramento de drift de memória física no kernel Linux (`/proc/self/statm`).
- Rollback automático de transações PDO/Doctrine esquecidas abertas.
- Limpeza de propriedades estáticas e serviços legados fora do container.
- Restauração de buffers de saída residuais, fusos horários e níveis de erro.

---

## Implementação com Event Subscriber do Symfony

Você pode integrar o Leakless ao Symfony criando um Event Subscriber que escuta os eventos do ciclo de vida do kernel:

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
                // Serviços fora do container ou caches legados
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
            // Sinaliza parada do worker conforme o ambiente (frankenphp / roadrunner)
            if (function_exists('frankenphp_finish_request')) {
                exit(0);
            }
        }
    }
}
```

---

## Interoperabilidade com `ResetInterface`

Serviços do Symfony que implementam `Symfony\Contracts\Service\ResetInterface` são resetados pelo `ServicesResetter` do framework.

O Leakless complementa esse ecossistema:
1. Suportando qualquer classe ou instância com método `reset()` em `Config::$resettables`.
2. Fornecendo o atributo declarativo `#[ResetOnRequest]` para reset granular de propriedades.
3. Capturando vazamentos que ocorrem **fora** do container do Symfony (ex: conexões PDO diretas, alocações de extensões C, variáveis `$GLOBALS` e propriedades `static`).
