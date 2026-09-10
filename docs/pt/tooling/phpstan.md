# Extensão de Regras do PHPStan (Alternativa ao CLI)

Além do CLI autônomo nativo (`vendor/bin/leakless analyze`), o pacote `themattosdev/leakless-dev` oferece uma **extensão oficial do PHPStan** (`extension.neon`). 

Essa alternativa é indicada para projetos que **já utilizam o PHPStan no pipeline de CI/CD** e preferem unificar todas as checagens estáticas em um único comando (`vendor/bin/phpstan analyse`), sem precisar rodar um comando adicional.

---

## Quando Escolher Cada Abordagem?

| Abordagem | Como Executa | Velocidade | Dependências | Quando Usar |
| :--- | :--- | :--- | :--- | :--- |
| **CLI Nativo (`leakless analyze`)** *(Recomendado)* | Motor Pure AST em memória | **Ultrarrápido** (~milissegundos) | Zero dependências pesadas (<20MB RAM) | Recomendado para a maioria dos projetos; não exige PHPStan instalado. |
| **Extensão do PHPStan (`extension.neon`)** | Integrado ao pipeline do PHPStan | Depende do cache do PHPStan | Requer `phpstan/phpstan: ^2.0` no projeto | Ideal se seu projeto já roda PHPStan no CI e quer centralizar tudo num comando só. |

---

## Instalação dos Requisitos

Como o Leakless não força mais o PHPStan como dependência obrigatória, instale o PHPStan nas dependências de desenvolvimento caso ainda não o tenha:

```bash
composer require --dev phpstan/phpstan:^2.0
```

---

## Configuração

Adicione a extensão ao seu arquivo `phpstan.neon`:

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

Se o seu projeto utiliza o pacote [`phpstan/extension-installer`](https://github.com/phpstan/extension-installer), o `extension.neon` será descoberto e registrado automaticamente sem necessidade de inclusão manual.

---

## Execução

Execute o PHPStan normalmente no seu terminal ou pipeline de integração contínua:

```bash
vendor/bin/phpstan analyse
```

Se alguma regra de worker persistente for violada, o PHPStan reportará o erro no formato nativo com identificador (ex: `leakless.mutableStaticProperty`).

---

## Regras Incluídas

| Classe da Regra | Identificador | O que ela Valida |
| :--- | :--- | :--- |
| `BanMutableStaticPropertiesRule` | `leakless.mutableStaticProperty` | Bloqueia propriedades `static` mutáveis em classes sem o atributo `#[AllowPersistentState]` ou `#[ResetOnRequest]`. |
| `BanEphemeralInjectionInSingletonsRule` | `leakless.ephemeralSingletonInjection` | Impede injeção de dependências de `Request` / `Session` no construtor de singletons. |
| `BanSuperglobalsAndTerminatorsRule` | `leakless.superglobal`<br>`leakless.processTerminator`<br>`leakless.sessionStart` | Bloqueia superglobais `$_GET`, `$_POST`, `$_SESSION`, `exit()`, `die()` e `session_start()`. |
| `BanIncompatibleWorkerFunctionsRule` | `leakless.incompatibleFunction`<br>`leakless.globBraceIncompatible`<br>`leakless.imapNotThreadSafe` | Detecta `get_browser()`, `GLOB_BRACE` no Alpine Linux, `ext-imap` e cabeçalhos procedurais (`setcookie`, `header`). |

::: tip Análise Estática vs. Registro em Runtime
Marcar uma propriedade estática com `#[ResetOnRequest]` avisa o PHPStan de que a limpeza do estado foi planejada. **No entanto, você deve registrar a classe no `'resettables'`** (em `config/leakless.php` ou via `$leakless->registerResetTarget()`) para que o Leakless de fato a resete a cada requisição em tempo de execução.
:::

