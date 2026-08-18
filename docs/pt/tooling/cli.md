# CLI de Análise Estática (`leakless analyze`)

O CLI do Leakless fornece uma ferramenta de linha de comando independente para analisar sua base de código contra todas as regras de workers persistentes sem exigir configurações manuais no PHPStan.

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
| `--memory-limit` | `-m` | `512M` | Define o limite de memória para o processo do analisador. |
| `--configuration` | `-c` | `null` | Caminho para um arquivo de configuração customizado `phpstan.neon`. |
| `--json` | | `false` | Exporta o resultado em JSON puro para pipelines de CI/CD. |

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

---

## Códigos de Saída (Exit Codes)

- `0`: Análise concluída com **zero violações**.
- `1`: Foram detectadas violações de workers persistentes na base de código.
