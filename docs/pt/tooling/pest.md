# Expectativas Customizadas do Pest

O pacote `themattosdev/leakless-dev` registra automaticamente expectativas customizadas no **Pest** para validar a segurança de workers persistentes na sua suíte de testes automatizados.

---

## 1. `expect($target)->toBeLeakless()`

Executa uma auditoria estrutural profunda via reflexão em uma classe (`string`) ou objeto instanciado (`object`):

- Valida se a classe e todas as suas classes pai contêm **zero propriedades estáticas mutáveis** (exceto as anotadas com `#[AllowPersistentState]`).
- Inspeciona os parâmetros do construtor para garantir que dependências efêmeras (`Illuminate\Http\Request`, `Illuminate\Session\SessionManager`) não fiquem presas em singletons.

```php
use App\Services\PaymentService;
use App\Repositories\OrderRepository;

test('serviços do domínio são seguros para workers persistentes', function () {
    expect(PaymentService::class)->toBeLeakless();
    expect(OrderRepository::class)->toBeLeakless();
});
```

---

## 2. `expect($closure)->toRunCleanly(?float $maxDriftMb = null)`

Executa dinamicamente uma closure dentro de um ciclo protegido pelo Leakless:

- Audita transações de banco de dados PDO abertas antes e depois da execução da closure.
- Valida o esvaziamento de buffers de saída e a imutabilidade do fuso horário.
- Mede o RSS de memória física real do Linux antes e depois da execução.
- Se `$maxDriftMb` for informado, valida que a variação de memória RAM permaneceu abaixo do limite estabelecido.

```php
use App\Jobs\ProcessPendingInvoices;

test('processamento de faturas em lote executa sem vazamentos de memória', function () {
    expect(function () {
        $job = new ProcessPendingInvoices();
        $job->handle();
    })->toRunCleanly(maxDriftMb: 5.0); // Valida que o crescimento de RAM é <= 5MB
});
```

---

## Integração com PHPStan Strict Mode

Se você utiliza o PHPStan em nível estrito (Level 9) na sua suíte de testes, adicione esta regra no seu `phpstan.neon`:

```yaml
parameters:
    ignoreErrors:
        - '#Call to an undefined method Pest\\Expectation.*::(toBeLeakless|toRunCleanly)\(\)#'
```
