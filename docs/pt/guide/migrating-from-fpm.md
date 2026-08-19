# Migrando do PHP-FPM para Workers Persistentes

Migrar uma aplicação legada do PHP-FPM tradicional para workers persistentes (**FrankenPHP Worker Mode** ou **Laravel Octane**) costuma gerar receio. No PHP-FPM, o código podia confiar que o sistema operacional limparia memória, travas de banco de dados e variáveis globais após cada requisição.

**O Leakless atua como sua rede de segurança durante a migração**, permitindo que sua equipe faça a transição em três fases estruturadas.

---

## Fase 1: Auditoria Estática Pré-Migração

Antes de habilitar o worker mode em homologação ou produção, execute o analisador estático do Leakless em toda a sua base de código:

```bash
vendor/bin/leakless analyze
```

O analisador inspeciona a AST do seu código para identificar padrões perigosos em workers persistentes:

1. **Terminadores de Processo**: Chamadas a `exit()` ou `die()` que derrubariam o worker inteiro e cancelariam requisições concorrentes.
2. **Sessões Nativas**: Chamadas a `session_start()` e `session_id()` que corrompem a concorrência persistente.
3. **Envio Direto de Cabeçalhos**: Chamadas a `header()` e `setcookie()` que ignoram a abstração de Response do framework e vazam cabeçalhos para os próximos usuários.
4. **Propriedades Estáticas Mutáveis**: Variáveis estáticas retendo dados ou cache entre requisições sem o atributo `#[AllowPersistentState]`.
5. **Injeções em Singletons**: Dependências de escopo de requisição (como `Request` ou `Session`) injetadas no construtor de singletons de longa duração.

Corrija as ocorrências apontadas ou anote caches estáticos intencionais com `#[AllowPersistentState]`.

---

## Fase 2: Rede de Proteção em Runtime

Ao ativar o modo worker em homologação ou produção, instale o `themattosdev/leakless`:

```bash
composer require themattosdev/leakless
```

O Leakless fornece um guardião autônomo em tempo de execução:

- **Transações Órfãs**: Se um controller ou pacote terceiro falhar em commitar uma transação de banco de dados, o Leakless detecta a transação PDO aberta, registra um log e executa o rollback antes que a próxima requisição seja atendida.
- **Buffers de Saída Não Fechados**: Se o código legado chamar `ob_start()` sem `ob_end_clean()`, o Leakless esvazia o buffer residual.
- **Limites de Memória Nativa**: Se operações de manipulação de imagens ou chamadas de rede acumularem memória em extensões C, o Leakless monitora o RSS real no Linux e recicla o worker graciosamente após a conclusão da requisição ativa.

Configure o `.env` para auditar e registrar violações:

```ini
LEAKLESS_ENABLED=true
LEAKLESS_LOG_VIOLATIONS=true
LEAKLESS_MAX_RSS_MB=256
LEAKLESS_MAX_REQUESTS=1000
```

---

## Fase 3: Blindagem da Suíte de Testes

Adicione asserções de workers persistentes à sua suíte de testes com o Pest:

```php
use App\Services\LegacyInvoiceService;

test('serviço de faturamento legado não possui estado estático mutável', function () {
    expect(LegacyInvoiceService::class)->toBeLeakless();
});

test('fluxo de registro de usuário executa de forma limpa', function () {
    expect(function () {
        (new RegisterUserController())->handle();
    })->toRunCleanly(maxDriftMb: 0.25);
});
```

E em testes de integração do Laravel:

```php
test('api de checkout não vaza transações ou memória', function () {
    $response = $this->postJson('/api/checkout', [
        'plan' => 'pro',
    ]);

    $response->assertOk()
        ->assertNoDanglingTransactions()
        ->assertNoMemoryDrift(maxAllowedMb: 0.25)
        ->assertCleanWorkerState();
});
```
