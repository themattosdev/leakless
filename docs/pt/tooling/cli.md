# CLI de Análise Estática (`leakless analyze`)

O CLI do Leakless fornece uma ferramenta de linha de comando independente para analisar sua base de código contra todas as regras de workers persistentes sem exigir configurações manuais no PHPStan.

> [!TIP]
> O comando `leakless analyze` utiliza um motor nativo de Pure AST em memória, executando em milissegundos com consumo mínimo de memória (<20MB). Caso prefira rodar essas validações diretamente no seu `phpstan analyse` existente, consulte o [Guia da Extensão do PHPStan](./phpstan.md).

---

## Uso

Execute a análise nos diretórios padrão da aplicação (`app/`, `src/`):

```bash
vendor/bin/leakless analyze
```

### Analisando Caminhos Personalizados

Informe arquivos ou diretórios específicos para inspeção:

```bash
vendor/bin/leakless analyze app/Services src/Infrastructure
```

---

## Opções do CLI

| Opção | Flag | Padrão | Descrição |
| :--- | :---: | :---: | :--- |
| `--memory-limit` | `-m` | `256M` | Define o limite de memória para o processo do analisador. |
| `--configuration` | `-c` | `null` | Mantida para retrocompatibilidade. *(Nota: O motor Pure AST nativo opera zero-config e não lê o `phpstan.neon`. Caso precise de `ignoreErrors` ou configurações do PHPStan, use a [Extensão do PHPStan](./phpstan.md)).* |
| `--json` | | `false` | Exporta o resultado em JSON puro para pipelines de CI/CD. |

---

## Regras Validadas pelo Analisador

O motor Pure AST percorre todos os arquivos PHP nos diretórios informados e valida as seguintes regras:

| Identificador da Regra | Risco Identificado | Como Corrigir |
| :--- | :--- | :--- |
| `leakless.mutableStaticProperty` | Propriedades `static` mutáveis retendo estado entre requisições de workers. | Marque como `readonly`, converta em propriedade de instância ou anote com `#[AllowPersistentState]` / `#[ResetOnRequest]`. |
| `leakless.ephemeralSingletonInjection` | Injeção de dependências de escopo temporário (`Request`, `Session`) no construtor de serviços. | Injete o request diretamente no método da ação ou injete uma closure / Container resolver. |
| `leakless.superglobal` | Acesso direto a superglobais `$_GET`, `$_POST`, `$_SESSION`, `$_REQUEST` ou `$_FILES`. | Utilize a abstração de Request ou Session do framework. |
| `leakless.processTerminator` | Chamadas diretas a `exit()` ou `die()` que derrubam todo o processo do worker. | Retorne um objeto Response ou lance uma exceção. |
| `leakless.sessionStart` | Chamadas diretas a `session_start()` nativo que corrompem a concorrência do worker. | Utilize o gerenciador de Sessão do framework. |
| `leakless.incompatibleFunction` | Funções incompatíveis com workers (`get_browser`, `header`, `setcookie`, `session_*`, `flush`). | Utilize as abstrações de Response / Cookie / Sessão do framework. |
| `leakless.globBraceIncompatible` | Flag `GLOB_BRACE` em `glob()`, não suportada no Alpine Linux musl libc (retorna false). | Utilize múltiplas chamadas a `glob()` ou o Symfony Finder. |
| `leakless.imapNotThreadSafe` | Funções `imap_*` da extensão `ext-imap` que não são thread-safe. | Utilize pacotes modernos em userland (ex: `webklex/php-imap`). |

---

## Formatos de Saída

### Modo Terminal (Interface Termwind)

Em terminais interativos, o Leakless exibe um relatório com contagem de erros, arquivos agrupados, números de linha e dicas de correção:

```text
 LEAKLESS  Static Worker Analysis (app)

 ✕ FAIL: Found 2 worker violation(s) in codebase.

app/Repositories/CachedUserRepository.php
  Line 14: Mutable static property App\Repositories\CachedUserRepository::$cache retention detected. (leakless.mutableStaticProperty)

app/Services/AuditService.php
  Line 19: Direct exit() or die() call detected. In persistent worker environments, calling exit terminates the entire worker process. (leakless.processTerminator)

💡 Hint: Use #[AllowPersistentState] on intentional static properties, or wrap request-scoped dependencies.
```

### Modo JSON (`--json`)

Em pipelines de CI/CD, utilize `--json` para processar os resultados programaticamente:

```bash
vendor/bin/leakless analyze --json
```

```json
{
  "totals": {
    "errors": 0,
    "file_errors": 2
  },
  "files": {
    "/app/src/Service.php": {
      "errors": 1,
      "messages": [
        {
          "message": "Mutable static property...",
          "line": 14,
          "identifier": "leakless.mutableStaticProperty"
        }
      ]
    }
  }
}
```

---

## Códigos de Saída (Exit Codes)

- `0`: Análise concluída com **zero violações**.
- `1`: Foram detectadas violações de workers persistentes na base de código.

