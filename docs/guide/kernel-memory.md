# Real Kernel RSS & File Descriptor Tracking

Standard PHP functions like `memory_get_usage()` and `memory_get_peak_usage()` only measure memory allocated inside the **Zend Engine heap** (the internal memory manager for PHP objects, arrays, and variables).

They are **completely blind** to:
- C-extensions (`curl`, `imagick`, `gd`, `openssl`, `ext-sodium`)
- Native glibc `malloc()` / `jemalloc` / `mimalloc` allocations
- Unclosed File Descriptors (`/proc/self/fd`) and lingering sockets

---

## 1. The Linux `/proc/self/statm` Interface

On Linux, the kernel maintains an ultra-fast pseudo-filesystem file for every process: `/proc/self/statm`.

Reading this file does not trigger disk I/O; the kernel directly exposes the process memory page table:

```
20480 12800 3840 256 0 10240 0
```

Where the values represent:
1. **Total Program Size (Size)**: Total virtual memory size (in pages).
2. **Resident Set Size (RSS)**: Real physical RAM currently occupied by the process (in pages).
3. **Shared Pages (Shared)**: Pages mapped to shared libraries.
4. **Text / Code (Text)**: Executable code segment.
5. **Library (Lib)**: Shared library pages.
6. **Data + Stack (Data)**: Process data segment and user stack.
7. **Dirty Pages (Dirty)**: Modified physical memory pages.

### How Leakless Computes RSS
1. **Kernel Page Resolution**: Dynamically resolves the Linux page size via POSIX `posix_sysconf(POSIX_PC_SC_PAGESIZE)` (typically `4096` bytes).
2. **Zero-Allocation Parsing**: `ProcStatmParser` reads `/proc/self/statm` at request boundaries.
3. **Megabyte Conversion**: Converts page counts directly to megabytes:
   $$\text{RSS (MB)} = \frac{\text{resident\_pages} \times \text{page\_size\_bytes}}{1024 \times 1024}$$

---

## 2. File Descriptor Guard (`/proc/self/fd`)

In persistent PHP workers, unclosed file handles (`fopen()`), temporary streams, or open network sockets (`fsockopen()`, cURL handles) accumulate in the process table.

Over time, this breaches the operating system limit (`ulimit -n`), causing fatal errors:
`Too many open files`

### How FileDescriptorGuard Operates
When `checkFileDescriptors: true` (or `LEAKLESS_CHECK_FILE_DESCRIPTORS=true`):
1. **Initial Snapshot**: Reads `/proc/self/fd` symlinks at `startRequest()`.
2. **Post-Request Audit**: Re-inspects `/proc/self/fd` at `endRequest()`.
3. **Leak Identification**: Detects descriptors opened during request processing that were left unclosed.
4. **Diagnostic Logging**: Emits detailed warnings with file paths and sets `$report->fileDescriptorsLeaked = true`.
