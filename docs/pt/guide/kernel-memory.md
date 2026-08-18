# Memória Real do Kernel (RSS) & File Descriptors

Funções nativas do PHP como `memory_get_usage()` e `memory_get_peak_usage()` medem apenas a memória alocada dentro do **heap da Zend Engine** (o gerenciador interno para objetos, arrays e variáveis do PHP).

Elas são **completamente cegas** para:
- Extensões em C (`curl`, `imagick`, `gd`, `openssl`, `ext-sodium`)
- Alocações nativas via `malloc()` / `jemalloc` / `mimalloc`
- File Descriptors e sockets esquecidos abertos (`/proc/self/fd`)

---

## 1. A Interface `/proc/self/statm` no Linux

No Linux, o kernel disponibiliza um arquivo virtual para cada processo: `/proc/self/statm`.

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

### Como o Leakless Calcula o RSS Real
1. **Resolução de Página**: Consulta o tamanho da página Linux via `posix_sysconf(POSIX_PC_SC_PAGESIZE)` (normalmente `4096` bytes).
2. **Parsing de Alta Performance**: `ProcStatmParser` lê `/proc/self/statm` nos limites da requisição.
3. **Conversão para Megabytes**: Converte a contagem de páginas diretamente para megabytes:
   $$\text{RSS (MB)} = \frac{\text{resident\_pages} \times \text{page\_size\_bytes}}{1024 \times 1024}$$

---

## 2. Guardião de File Descriptors (`/proc/self/fd`)

Em workers persistentes, arquivos não fechados (`fopen()`), streams temporárias ou sockets de rede (`fsockopen()`, handles cURL) acumulam na tabela de processos do Linux.

Com o tempo, isso atinge o limite do sistema (`ulimit -n`), provocando o erro fatal:
`Too many open files`

### Como o FileDescriptorGuard Opera
Quando `checkFileDescriptors: true` (ou `LEAKLESS_CHECK_FILE_DESCRIPTORS=true`):
1. **Snapshot Inicial**: Lê os symlinks de `/proc/self/fd` no `startRequest()`.
2. **Auditoria de Pós-Requisição**: Reinspeciona `/proc/self/fd` no `endRequest()`.
3. **Identificação de Vazamentos**: Detecta descritores abertos durante a requisição que não foram fechados.
4. **Logs Diagnósticos**: Emite alertas detalhados com os caminhos dos arquivos e marca `$report->fileDescriptorsLeaked = true`.
