# Ciclo de Reciclagem de Workers

Workers persistentes acumulam memória fragmentada ao longo do tempo. Em vez de aguardar o sistema operacional matar bruscamente o processo com `SIGKILL` (cancelando requisições de clientes em andamento), o Leakless implementa a **Reciclagem Graciosa de Workers**.

---

## Condições de Disparo

Um worker é sinalizado para reciclagem quando qualquer um dos seguintes limites é ultrapassado:

1. **Teto de Memória RSS Real (`maxRssMb`)**:
   - O Resident Set Size medido no Linux ao final da requisição ultrapassa o valor configurado (ex: `256.0 MB`).
2. **Limite de Requisições (`maxRequests`)**:
   - O número total de requisições atendidas pelo worker atinge o limite configurado (ex: `1000 requisições`).

---

## A Sequência de Reciclagem

1. **Conclusão da Requisição Ativa**: A requisição em andamento é processada normalmente, gera a resposta HTTP e a envia com sucesso para o cliente.
2. **Auditoria de Pós-Requisição**: No `$leakless->endRequest()`, o Leakless finaliza auditorias de transações PDO, restaura fuso horário/buffers e avalia o RSS.
3. **Sinalização de Reinício Gracioso**:
   - No **Laravel Octane**: Notifica o runner do Octane para reciclar o worker. O Octane inicializa um novo worker limpo em background.
   - No **FrankenPHP Vanilla**: O `FrankenPhp::run()` encerra o loop de forma graciosa (`exit(0)`), permitindo que o gerenciador do FrankenPHP suba um novo worker.
   - Em **Loops Manuais**: O booleano `Report::$shouldRecycle` informa ao seu loop que é hora de finalizar o processo.

---

## Exemplo de Configuração

```php
use TheMattos\Leakless\Config;

$config = new Config(
    maxRssMb: 512.0,    // Recicla se a memória física ultrapassar 512MB
    maxRequests: 5000,  // Recicla após 5.000 requisições processadas
);
```
