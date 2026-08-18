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
  - title: Rollback Defensivo de Estado
    details: Restaura fusos horários alterados, esvazia buffers de saída não fechados e redefine níveis de erro no encerramento de cada requisição.
  - title: Reciclagem Graciosa de Workers
    details: Intercepta o ciclo quando limites de memória RSS ou limites de requisições são atingidos, finalizando a requisição ativa com segurança.
  - title: Linter Estático CLI
    details: Analisador de linha de comando (vendor/bin/leakless analyze) com interface Termwind para identificar anti-patterns de workers no CI/CD.
  - title: Expectativas Customizadas no Pest
    details: Asserções nativas de teste com expect($service)->toBeLeakless() e expect($closure)->toRunCleanly() para garantias estritas.
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
# Zero-Config: O Leakless é descoberto automaticamente e intercepta os eventos do Octane
LEAKLESS_MAX_RSS_MB=256
LEAKLESS_MAX_REQUESTS=1000
LEAKLESS_CHECK_TRANSACTIONS=true
LEAKLESS_ROLLBACK_STATE=true
LEAKLESS_LOG_VIOLATIONS=true
```

```php [FrankenPHP Worker Loop]
use TheMattos\Leakless\Config;
use TheMattos\Leakless\Integrations\FrankenPhp\FrankenPhp;

$config = new Config(
    maxRssMb: 256,
    maxRequests: 1000,
    checkTransactions: true,
);

FrankenPhp::run(function () {
    // Tratamento da requisição da aplicação
    echo json_encode(['status' => 'ok', 'timestamp' => time()]);
}, $config);
```

```php [Asserções de Teste Pest]
use App\Services\OrderProcessor;

test('processador de pedidos executa sem drift de memória ou transações órfãs', function () {
    expect(OrderProcessor::class)->toBeLeakless();

    expect(function () {
        (new OrderProcessor())->handleBatch();
    })->toRunCleanly(maxDriftMb: 5.0);
});
```

```bash [CLI de Análise Estática]
vendor/bin/leakless analyze
```
:::

</div>
