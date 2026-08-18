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
