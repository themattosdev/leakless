# Real Kernel RSS Memory Tracking

Standard PHP functions like `memory_get_usage()` and `memory_get_peak_usage()` only measure memory allocated inside the **Zend Engine heap** (the internal memory manager for PHP objects, arrays, and variables).

They are **completely blind** to memory allocated by:
- C-extensions (`curl`, `imagick`, `gd`, `openssl`, `ext-sodium`)
- Native glibc `malloc()` / `jemalloc` / `mimalloc` allocations
- Embedded C/Go worker memory inside FrankenPHP

---

## The Linux `/proc/self/statm` Interface

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

---

## How Leakless Reads and Computes RSS

1. **Kernel Page Resolution**: Leakless dynamically resolves the Linux kernel page size using POSIX `posix_sysconf(POSIX_PC_SC_PAGESIZE)` (typically `4096` bytes on x86_64, or `65536` bytes on certain ARM architectures).
2. **Low-Overhead Parsing**: During `startRequest()` and `endRequest()`, Leakless reads `/proc/self/statm` with zero-allocation string parsing (`ProcStatmParser`).
3. **Conversion**: Converts page counts directly to megabytes:
   $$\text{RSS (MB)} = \frac{\text{resident\_pages} \times \text{page\_size\_bytes}}{1024 \times 1024}$$
4. **Memory Drift Calculation**: Computes the exact physical memory delta ($\Delta \text{RSS}$) consumed during the active request cycle:
   $$\Delta \text{RSS} = \text{RSS}_{\text{after}} - \text{RSS}_{\text{before}}$$

If the Linux `/proc` filesystem is unavailable (such as during local development on macOS/Windows without Docker), Leakless automatically falls back to `memory_get_usage(true)` to ensure 100% portability.
