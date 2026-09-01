# Rollback Defensivo de Estado

Workers persistentes não reinicializam o estado global do processo PHP automaticamente entre requisições.

Se o código da aplicação ou de um pacote terceiro alterar configurações globais (como o fuso horário padrão, níveis de reporte de erro ou abrir buffers de saída sem fechá-los), essas alterações permanecem e poluem as requisições subsequentes.

---

## Entidades de Estado Gerenciadas

Durante o ciclo de cada requisição, o Leakless captura e restaura automaticamente três pontos críticos de estado:

### 1. Restauração do Fuso Horário Padrão (Timezone)

Se alguma rotina invocar `date_default_timezone_set()`:
- O Leakless registra o fuso horário inicial no `$leakless->startRequest()`.
- Se o timezone tiver sido alterado durante a requisição, o Leakless executa `date_default_timezone_set($originalTimezone)` no `$leakless->endRequest()`.

### 2. Esvaziamento de Buffers de Saída Residuais

Se um erro ou fluxo não fechar buffers de saída (`ob_start()` sem `ob_end_clean()` ou `ob_get_clean()`):
- O Leakless mede a profundidade inicial dos buffers (`ob_get_level()`) no `startRequest()`.
- No `endRequest()`, o Leakless esvazia os buffers adicionais com `ob_end_clean()` até restaurar o nível original, impedindo que fragmentos de HTML ou JSON vazem para as próximas respostas.

### 3. Níveis de Reporte de Erro (error_reporting)

Se o código alterar temporariamente o nível de reporte (`error_reporting(E_ALL)` ou desativar avisos):
- O Leakless grava o bitmask original de `error_reporting()`.
- Restaura o nível original ao término da requisição.

---

## 4. Engine de Resettables Zero-Reflection (`StateResetter`)

Além das configurações globais do PHP, serviços persistentes e singletons com propriedades mutáveis frequentemente acumulam estado entre requisições (como caches, buffers e sessões).

O Leakless inclui um motor de alta performance baseado no modelo **Zero Reflection no Hot Path**:

```
registerTarget($target) / Config::$resettables  ──► [Warmup / Registro (Reflection roda 1 vez)]
                                                              │
                                                              ▼
                                                   Compila Closures nativas
                                                              │
                                                              ▼
endRequest() ─────────────────────────────────────► foreach ($closures) { $closure(); }
                                                    [Hot Path: Closures nativas puras]
```

### Tipos de Alvos Suportados

1. **Callbacks / Closures Anônimas**:
   ```php
   $config = new Config(
       resettables: [
           fn () => LegacyRegistry::$cache = [],
       ],
   );
   ```

2. **Métodos Convencionais de Reset (`ResetInterface` / `reset()` / `cleanup()`)**:
   ```php
   class CartSession
   {
       public array $items = [];

       public function reset(): void
       {
           $this->items = [];
       }
   }
   ```

3. **Métodos Estáticos de Limpeza em Classes**:
   ```php
   class MetricsBuffer
   {
       public static array $logs = [];

       public static function resetState(): void
       {
           self::$logs = [];
       }
   }
   ```

4. **Atributo Declarativo `#[ResetOnRequest]`**:
   ```php
   use TheMattos\Leakless\Attributes\ResetOnRequest;

   class UserContext
   {
       #[ResetOnRequest(default: [])]
       public array $permissions = ['admin'];

       #[ResetOnRequest]
       public static ?string $token = null;
   }
   ```

Ao término de cada requisição (`endRequest()`), o Leakless executa todas as closures compiladas sem nenhum overhead de reflexão em tempo de execução.
