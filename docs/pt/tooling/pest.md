# Testes: Asserções para Pest & PHPUnit

O pacote `themattosdev/leakless-dev` fornece utilitários de teste de primeira classe tanto para o **Pest PHP** quanto para o **PHPUnit**, permitindo garantir a segurança e higiene de workers em sua suíte automatizada.

---

## 1. Expectations no Pest

Ao utilizar o Pest, o Leakless registra expectations customizadas automaticamente:

### `expect($target)->toBeLeakless()`
Executa uma auditoria estrutural via Reflection em uma classe (`string`) ou objeto (`object`):
- Garante que a classe e suas classes pai contenham **zero propriedades estáticas mutáveis** (a menos que anotadas com `#[AllowPersistentState]`).
- Inspeciona os parâmetros do construtor para proibir a captura de dependências efêmeras de requisição (`Illuminate\Http\Request`, `Session`) em serviços singleton/longa vida.

```php
use App\Services\PaymentService;
use App\Repositories\OrderRepository;

test('serviços de domínio são seguros para workers', function () {
    expect(PaymentService::class)->toBeLeakless();
    expect(OrderRepository::class)->toBeLeakless();
});
```

### `expect($closure)->toRunCleanly(?float $maxDriftMb = null)`
Executa dinamicamente uma Closure dentro de um ciclo vigiado pelo Leakless:
- Audita transações PDO não commitadas e descritores de arquivos abertos.
- Valida a restauração de fuso horário e buffers de saída.
- Mede a variação de memória RAM física do kernel Linux ($\Delta\text{RSS}$).

```php
test('processamento em lote executa de forma limpa', function () {
    expect(function () {
        $service = new ReportGenerator();
        $service->generate();
    })->toRunCleanly(maxDriftMb: 0.25); // Garante que a RAM não cresceu mais que 0.25MB (256KB)
});
```

### `expect($target)->toResetContainerState(callable $callback, int $maxDepth = 4)`
*Alias: `expect($target)->toHaveStatelessInstances(callable $callback, int $maxDepth = 4)`*

Captura um snapshot profundo do estado de propriedades de objetos (instâncias isoladas, arrays de objetos, containers PSR-11 ou o container `$app` do Laravel) antes e após a execução do callback para garantir que singletons de longa duração permaneçam sem retenção de estado:

```php
test('serviços não sofrem mutação de estado entre requisições', function () {
    $globalsBag = new ViewGlobalsBag();
    $cacheService = new MetadataCache();

    expect([$globalsBag, $cacheService])->toResetContainerState(function () use ($globalsBag) {
        $globalsBag->set('csrf_token', 'temporary-token');
        // Falhará se o $globalsBag não for resetado ao final do ciclo!
    });
});
```

Você também pode passar o container da aplicação diretamente, com um limite de profundidade opcional:

```php
test('singletons do laravel mantêm propriedades stateless', function () {
    expect(app())->toResetContainerState(function () {
        $this->postJson('/api/checkout', ['item' => 'pro']);
    }, maxDepth: 2); // Profundidade customizada (padrão: 4)
});
```

---

## 2. Asserções Nativas para PHPUnit (`AssertsLeakless`)

Se seu projeto utiliza classes de teste padrão do PHPUnit (`TestCase`) em vez do Pest, utilize a trait `AssertsLeakless`:

```php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use TheMattos\Leakless\Dev\PHPUnit\AssertsLeakless;
use App\Services\PaymentService;

final class PaymentServiceTest extends TestCase
{
    use AssertsLeakless;

    public function test_service_is_worker_safe(): void
    {
        $this->assertIsLeakless(PaymentService::class);
    }

    public function test_request_cycle_runs_cleanly(): void
    {
        $this->assertRunsCleanly(function () {
            $service = new PaymentService();
            $service->process();
        }, maxDriftMb: 0.25);
    }

    public function test_container_maintains_clean_state(): void
    {
        $this->assertResetsContainerState($this->app, function () {
            $this->postJson('/api/users');
        }, maxDepth: 4);
    }
}
```

### Métodos Disponíveis no PHPUnit

| Método | Descrição |
| :--- | :--- |
| `$this->assertIsLeakless($target)` | Assere que uma classe/objeto não possui estado estático mutável ou injeções ilegais. |
| `$this->assertRunsCleanly($callable, $config, $maxDriftMb)` | Executa um callback sob o Leakless e assere estado 100% limpo. |
| `$this->assertResetsContainerState($target, $callback, $msg, $maxDepth)` | Captura snapshot de objetos/singletons do container para asserir ausência de mutações. |
| `$this->assertStatelessInstances($target, $callback, $msg, $maxDepth)` | Alias para `assertResetsContainerState`. |
| `$this->assertNoDanglingTransactions($reportOrResponse)` | Assere que nenhuma transação PDO permaneceu aberta. |
| `$this->assertCleanWorkerState($reportOrResponse)` | Assere que o estado do worker terminou 100% limpo. |
| `$this->assertNoMemoryDrift($reportOrResponse, $maxMb)` | Assere que o drift de memória física não ultrapassou o teto. |
