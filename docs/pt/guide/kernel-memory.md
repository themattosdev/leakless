# Rastreamento de Memória Real do Kernel (RSS)

Funções nativas do PHP como `memory_get_usage()` e `memory_get_peak_usage()` medem apenas a memória alocada dentro do **heap da Zend Engine** (o gerenciador interno para objetos, arrays e variáveis do PHP).

Elas são **completamente cegas** para memórias alocadas por:
- Extensões em C (`curl`, `imagick`, `gd`, `openssl`, `ext-sodium`)
- Alocações nativas de bibliotecas via `malloc()` / `jemalloc` / `mimalloc`
- Memória compartilhada entre o servidor Go/C do FrankenPHP e o PHP

---

## A Interface `/proc/self/statm` no Linux

No Linux, o kernel disponibiliza um arquivo virtual em pseudo-sistema de arquivos para cada processo: `/proc/self/statm`.

Ler esse arquivo não realiza I/O de disco físico; o kernel expõe a tabela de páginas de memória do processo em nanossegundos:

```
20480 12800 3840 256 0 10240 0
```

Onde cada coluna representa:
1. **Tamanho Total do Programa (Size)**: Espaço total de memória virtual (em páginas).
2. **Resident Set Size (RSS)**: Memória RAM física real atualmente ocupada pelo processo (em páginas).
3. **Páginas Compartilhadas (Shared)**: Páginas mapeadas de bibliotecas compartilhadas.
4. **Texto / Código (Text)**: Segmento de código executável.
5. **Biblioteca (Lib)**: Páginas de bibliotecas dinâmicas.
6. **Dados + Stack (Data)**: Segmento de dados e pilha do processo.
7. **Páginas Modificadas (Dirty)**: Páginas físicas alteradas na RAM.

---

## Como o Leakless Calcula o RSS Real

1. **Resolução Dinâmica de Página**: O Leakless consulta o tamanho exato da página do kernel Linux via `posix_sysconf(POSIX_PC_SC_PAGESIZE)` (normalmente `4096` bytes em x86_64 ou `65536` bytes em certas arquiteturas ARM).
2. **Parsing de Alta Performance**: Durante o `$leakless->startRequest()` e `$leakless->endRequest()`, o `ProcStatmParser` realiza a leitura de `/proc/self/statm` com zero alocação de memória intermediária.
3. **Conversão para Megabytes**: Converte a contagem de páginas diretamente para megabytes:
   $$\text{RSS (MB)} = \frac{\text{resident\_pages} \times \text{page\_size\_bytes}}{1024 \times 1024}$$
4. **Cálculo de Deriva de Memória (Memory Drift)**: Calcula a variação exata de RAM física ($\Delta \text{RSS}$) durante o ciclo da requisição:
   $$\Delta \text{RSS} = \text{RSS}_{\text{depois}} - \text{RSS}_{\text{antes}}$$

Caso o sistema de arquivos `/proc` não esteja acessível (como em desenvolvimento local no macOS ou Windows sem Docker), o Leakless adota automaticamente o fallback para `memory_get_usage(true)`.
