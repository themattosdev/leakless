# Ciclo de Reciclagem de Workers

Workers persistentes acumulam memória fragmentada ao longo do tempo. Em vez de aguardar o sistema operacional matar bruscamente o processo com `SIGKILL` (cancelando requisições de clientes em andamento), o Leakless implementa a **Reciclagem Graciosa de Workers** com proteção nativa contra tempestades de reinício (*restart storms*).

---

## Condições de Disparo

Um worker é avaliado para reciclagem sob um modelo resiliente de **Dois Níveis**:

1. **Drift de Memória Relativo (`maxDriftMb` & `consecutiveViolationsThreshold`)** [Primário]:
   - Mede o Resident Set Size real do Linux (`/proc/self/statm`) em relação à memória base inicial do worker ($M_0$).
   - Quando o crescimento ultrapassa o limite (`maxDriftMb: 64MB`), o Leakless executa `gc_collect_cycles()` e reavalia a memória física.
   - Se a violação persistir por $N$ requisições consecutivas (`consecutive_violations: 5`), a reciclagem é confirmada.
   - **Proteção por Cooldown (`recycle_cooldown: 10s`)**: Limita a frequência de reinicializações por worker, impedindo que todos os workers caiam juntos sob rajadas de tráfego.
   - **Jitter (`drift_jitter: 10%`)**: Aplica uma pequena variação pseudo-aleatória no teto de drift para desincronizar reinicializações.

2. **Teto de Emergência Físico (`maxRssMb`)** [Opcional / Emergência]:
   - Teto físico absoluto (ex: `512MB`) para proteger o container contra o Linux OOM Killer `SIGKILL` em caso de vazamentos descontrolados.

3. **Limite de Requisições (`maxRequests`)**:
   - A contagem total de requisições atendidas pelo worker atinge o limite (ex: `1000 requisições`).

---

## O Fluxo de Reciclagem

1. **Conclusão da Requisição Ativa**: A requisição em andamento executa normalmente, gera a resposta HTTP e a envia ao cliente.
2. **Auditoria no `endRequest()`**: O Leakless executa os rollbacks de transação, restauração de fuso horário, reavaliação pós-GC e cálculo de drift.
3. **Sinalização Graciosa**:
   - No **Laravel Octane**: Sinaliza o runner do Octane para reciclar o worker de forma não bloqueante.
   - No **Vanilla FrankenPHP**: O `FrankenPhp::run()` quebra o loop e sai graciosamente (`exit(0)`), permitindo que o FrankenPHP crie um novo processo.

---

## Exemplo de Configuração

```php
use TheMattos\Leakless\DTOs\Config;

$config = new Config(
    maxDriftMb: 64,                     // Permite até 64MB de crescimento relativo
    consecutiveViolationsThreshold: 5,  // Exige 5 violações consecutivas pós-GC
    recycleCooldownSeconds: 10,         // Janela de cooldown entre reinicializações (segundos)
    maxRequests: 1000,                  // Recicla após 1.000 requisições processadas
);
```
