# Extensão de Regras de AST do PHPStan

O pacote `themattosdev/leakless-dev` oferece uma extensão oficial do PHPStan (`extension.neon`) contendo regras de análise estática na AST do código para garantir compatibilidade com workers persistentes.

---

## Configuração

Inclua a extensão no seu arquivo `phpstan.neon`:

```yaml
includes:
    - vendor/themattosdev/leakless-dev/extension.neon

parameters:
    level: max
    paths:
        - app
        - src
    ignoreErrors:
        - '#Call to an undefined method Pest\\Expectation.*::(toBeLeakless|toRunCleanly|toResetContainerState|toHaveStatelessInstances)\(\)#'
        - '#Call to an undefined method Illuminate\\Testing\\TestResponse.*::(assertNoDanglingTransactions|assertNoMemoryDrift|assertCleanWorkerState)\(\)#'
```

---

## Regras de AST Incluídas

| Classe da Regra | O que ela Valida |
| :--- | :--- |
| `BanMutableStaticPropertiesRule` | Bloqueia propriedades `static` mutáveis em classes sem o atributo `#[AllowPersistentState]`. |
| `BanEphemeralInjectionInSingletonsRule` | Impede injeção de dependências de `Request` / `Session` no construtor de singletons. |
| `BanSuperglobalsAndTerminatorsRule` | Bloqueia superglobais `$_GET`, `$_POST`, `$_SESSION`, `exit()`, `die()` e `session_start()`. |
| `BanIncompatibleWorkerFunctionsRule` | Detecta `get_browser()`, `GLOB_BRACE` no Alpine Linux, `ext-imap` e cabeçalhos procedurais. |
