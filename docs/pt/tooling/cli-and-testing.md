# Visão Geral das Ferramentas Dev

O pacote `themattosdev/leakless-dev` oferece um conjunto de ferramentas para detectar e eliminar riscos de workers persistentes antes que o código chegue em produção:

- [**CLI de Análise Estática (`leakless analyze`)**](./cli.md): Analisador estático de AST independente com relatório visual em Termwind e suporte a JSON.
- [**Expectativas Customizadas do Pest**](./pest.md): `expect($class)->toBeLeakless()` e `expect($closure)->toRunCleanly()` para testes automatizados.
- [**Macros de Teste HTTP do Laravel**](./laravel-macros.md): `$response->assertNoDanglingTransactions()`, `$response->assertNoMemoryDrift()` e `$response->assertCleanWorkerState()`.
- [**Extensão de AST do PHPStan**](./phpstan.md): Regras estritas para PHPStan Level 9 (`extension.neon`) integradas ao seu pipeline de CI/CD.

---

## Instalação

```bash
composer require --dev themattosdev/leakless-dev
```
