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

---

## 2. Asserções Nativas no PHPUnit (`AssertsLeakless`)

Se o seu projeto utiliza classes `TestCase` padrão do PHPUnit em vez do Pest, utilize o trait `AssertsLeakless`:

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
}
```

### Métodos Disponíveis no PHPUnit

| Método | Descrição |
| :--- | :--- |
| `$this->assertIsLeakless($target)` | Assere que uma classe/objeto não retém estado estático mutável ou injeções efêmeras. |
| `$this->assertRunsCleanly($callable, $config, $maxDriftMb)` | Executa um callback sob o Leakless e assere estado 100% limpo. |
| `$this->assertNoDanglingTransactions($reportOrResponse)` | Assere que nenhuma transação PDO permaneceu aberta. |
| `$this->assertCleanWorkerState($reportOrResponse)` | Assere que o estado do worker terminou íntegro após a requisição. |
| `$this->assertNoMemoryDrift($reportOrResponse, $maxMb)` | Assere que a variação de memória física não excedeu o teto. |
