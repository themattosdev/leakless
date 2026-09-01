---
layout: home

hero:
  name: "Leakless"
  text: "Prevenção de Estado & Vazamento de Memória"
  tagline: "Motor de runtime autônomo e análise estática para workers persistentes em PHP (FrankenPHP e Laravel Octane)."
  actions:
    - theme: brand
      text: Primeiros Passos →
      link: /pt/guide/getting-started
    - theme: alt
      text: Documentação
      link: /pt/guide/why-leakless
    - theme: alt
      text: GitHub
      link: https://github.com/themattosdev/leakless

features:
  - title: Métricas Reais do Kernel (RSS)
    details: Leitura direta de /proc/self/statm no Linux para medir o Resident Set Size real e capturar alocações de extensões C fora da heap da Zend VM.
  - title: Transaction Guard Automatizado
    details: Inspeciona conexões PDO ativas ao término de cada requisição, detecta transações órfãs, gera logs de alerta e executa rollbacks imediatos.
  - title: Rollback de Estado & Resettables
    details: Restaura fusos horários, esvazia buffers e executa reset automático de serviços e propriedades #[ResetOnRequest] com zero reflexão no hot path.
  - title: Reciclagem Graciosa de Workers
    details: Intercepta o ciclo quando limites de memória RSS ou limites de requisições são atingidos, finalizando a requisição ativa com segurança.
  - title: Linter Estático CLI
    details: Analisador de linha de comando (vendor/bin/leakless analyze) com interface Termwind para identificar anti-patterns de workers no CI/CD.
  - title: Asserções no Pest e PHPUnit
    details: Asserções nativas de teste com expect($service)->toBeLeakless(), expect($closure)->toRunCleanly() e expect(app())->toResetContainerState().
---

<div class="vp-doc" style="max-width: 960px; margin: 3rem auto 0 auto;">

### Instalação Rápida

::: code-group
```bash [Composer (Runtime)]
composer require themattosdev/leakless
```
```bash [Composer (Ferramentas Dev)]
composer require --dev themattosdev/leakless-dev
```
:::

### Exemplo de Proteção de Worker

::: code-group
```ini [Laravel Octane (.env)]
LEAKLESS_ENABLED=true
LEAKLESS_MAX_DRIFT_MB=64
LEAKLESS_CHECK_TRANSACTIONS=true
LEAKLESS_CHECK_FILE_DESCRIPTORS=false
```

```php [Vanilla FrankenPHP]
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Integrations\FrankenPhp\FrankenPhp;

FrankenPhp::run(
    app: fn () => print(json_encode(['status' => 'ok'])),
    config: new Config(
        maxDriftMb: 64,
        checkTransactions: true,
        resettables: [
            App\Services\CartSession::class,
            fn () => LegacyStatic::$cache = [],
        ],
    ),
);
```

```php [Asserções Pest PHP]
test('endpoint de pedidos executa de forma limpa sem vazamentos', function () {
    // 1. Checagem de reflexão estrutural
    expect(PaymentGateway::class)->toBeLeakless();

    // 2. Checagem de ciclo de requisição e drift de RSS
    expect(function () {
        (new ProcessInvoicesJob())->handle();
    })->toRunCleanly(maxDriftMb: 0.25);

    // 3. Snapshot de mutações de estado em singletons do container
    expect(app())->toResetContainerState(function () {
        $this->postJson('/api/checkout', ['plan' => 'pro']);
    });
});
```

```bash [CLI de Análise Estática]
vendor/bin/leakless analyze
```
:::

</div>
