# Macros de Teste HTTP do Laravel

O pacote `themattosdev/leakless-dev` injeta automaticamente macros de asserção no `Illuminate\Testing\TestResponse` do Laravel.

---

## Macros Disponíveis

### 1. `assertNoDanglingTransactions()`

Valida que a requisição HTTP não deixou nenhuma transação aberta ou não commitada em nenhuma conexão ativa de banco de dados:

```php
test('checkout de pedidos não deixa transações de banco abertas', function () {
    $response = $this->postJson('/api/orders', [
        'product_id' => 42,
        'quantity' => 1,
    ]);

    $response->assertCreated()
        ->assertNoDanglingTransactions();
});
```

---

### 2. `assertNoMemoryDrift(?float $maxAllowedMb = 0.25)`

Assere que a variação de memória RAM física do kernel Linux durante a requisição permaneceu dentro do teto tolerável:

```php
test('endpoint da api não provoca drift excessivo de memória', function () {
    $response = $this->get('/api/users');

    $response->assertOk()
        ->assertNoMemoryDrift(maxAllowedMb: 0.25);
});
```

---

### 3. `assertCleanWorkerState()`

Combina as validações: assegura que não houve transações órfãs de banco nem poluição de estado no worker:

```php
test('endpoint de processamento mantém o worker em estado 100% limpo', function () {
    $response = $this->postJson('/api/process-batch');

    $response->assertOk()
        ->assertCleanWorkerState();
});
```
