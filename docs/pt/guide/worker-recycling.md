# Ciclo de Reciclagem de Workers

Workers persistentes acumulam memória fragmentada ao longo do tempo. Em vez de aguardar o sistema operacional matar bruscamente o processo com `SIGKILL` (cancelando requisições de clientes em andamento), o Leakless implementa a **Reciclagem Graciosa de Workers**.

---

## Condições de Disparo

Um worker é sinalizado para reciclagem quando qualquer um dos seguintes limites é ultrapassado:

1. **Teto de Memória RSS (`maxRssMb`)**:
   - O Resident Set Size medido no Linux ao final da requisição ultrapassa o valor configurado (ex: `96.0 MB`).
2. **Limite de Requisições (`maxRequests`)**:
   - A contagem total de requisições atendidas pelo worker atinge o limite (ex: `1000 requisições`).

---

## O Fluxo de Reciclagem

1. **Conclusão da Requisição Ativa**: A requisição em andamento executa normalmente, gera a resposta HTTP e a envia ao cliente.
2. **Auditoria no `endRequest()`**: O Leakless executa os rollbacks de transação, restauração de fuso horário e avaliação de limites.
3. **Sinalização Graciosa**:
   - No **Laravel Octane**: Sinaliza o runner do Octane para reciclar o worker de forma não bloqueante.
   - No **Vanilla FrankenPHP**: O `FrankenPhp::run()` quebra o loop e sai graciosamente (`exit(0)`), permitindo que o FrankenPHP crie um novo processo.

---

## Exemplo de Configuração

```php
use TheMattos\Leakless\DTOs\Config;

$config = new Config(
    maxRssMb: 96.0,     // Recicla se o RSS ultrapassar 96MB
    maxRequests: 1000,  // Recicla após 1.000 requisições processadas
);
```
