# Como Evitar Poluição de Estado e Vazamento de Dados entre Usuários no PHP

No PHP-FPM tradicional, cada requisição HTTP recebia um processo PHP totalmente isolado que morria logo após enviar a resposta.

Em runtimes persistentes (**FrankenPHP**, **Laravel Octane**, **RoadRunner**, **Swoole**), o processo PHP **sobrevive ao longo de milhares de requisições**. Qualquer variável mutável armazenada em escopo global ou memória estática permanece viva e vaza para as requisições seguintes, gerando **falhas graves de segurança e vazamento de dados entre clientes**.

---

## O Cenário Crítico: Vazamento de Dados Entre Usuários

```php
// PERIGOSO: Propriedade estática retém dados entre requisições!
class CurrentUser
{
    public static ?User $user = null;
}

// Requisição 1 (Usuário A faz login):
CurrentUser::$user = $userA;

// Requisição 2 (Um visitante anônimo acessa o mesmo worker):
// BUG GRAVE: CurrentUser::$user AINDA é o Usuário A!
// O visitante anônimo enxerga o painel privado e os dados de cartão do Usuário A!
```

---

## Os 4 Anti-Patterns Mais Perigosos em Workers Persistentes

### 1. Propriedades Estáticas Mutáveis
```php
// RUIM: O estado se acumula indefinidamente entre todos os usuários
class MetricsCollector
{
    public static array $events = [];
}
```
**Como Resolver com o Leakless:**
- Reset via `Config::$resettables`: `resettables: [fn () => MetricsCollector::$events = []]`
- Ou utilizando a anotação `#[ResetOnRequest]`:
  ```php
  use TheMattos\Leakless\Attributes\ResetOnRequest;

  class MetricsCollector
  {
      #[ResetOnRequest(default: [])]
      public static array $events = [];
  }
  ```

---

### 2. Injeção de Request ou Sessão no Construtor de Singletons
```php
// RUIM: O construtor roda apenas UMA VEZ no boot do worker!
// $this->request ficará congelado com a PRIMEIRA requisição do boot para sempre.
class InvoiceService
{
    public function __construct(
        private Request $request, // INSTÂNCIA CONGELADA / OBSOLETA!
    ) {}
}
```
**Como Resolver:** Injete o `$request` via parâmetro de método:
```php
class InvoiceService
{
    public function generate(Request $request): Invoice { ... }
}
```

---

### 3. Alteração Global de Fuso Horário e Reporte de Erros
```php
// RUIM: Altera o timezone globalmente para todas as próximas requisições deste worker
date_default_timezone_set('America/Sao_Paulo');
```
**Como Resolver com o Leakless:**
O Leakless captura o fuso horário inicial e o nível de `error_reporting()` no `$leakless->startRequest()` e os restaura automaticamente no `endRequest()`.

---

### 4. Buffers de Saída Não Fechados
```php
// RUIM: Se uma exceção for disparada antes de ob_end_clean(), o buffer vaza para o próximo cliente
ob_start();
renderFragmento();
// Exceção lançada aqui! O ob_start() ficou aberto!
```
**Como Resolver com o Leakless:**
O Leakless mede a profundidade de buffers (`ob_get_level()`) e esvazia todos os buffers órfãos no `endRequest()`.

---

## Análise Estática Pré-Produção com o Leakless

Detecte poluição de estado antes que seu código vá para homologação ou produção:

### 1. Execute o Linter de Linha de Comando
```bash
vendor/bin/leakless analyze
```

### 2. Adicione Asserções Estruturais no Pest / PHPUnit
```php
test('todos os serviços da aplicação são seguros para workers', function () {
    expect(PaymentService::class)->toBeLeakless();
    expect(InvoiceService::class)->toBeLeakless();
    expect(OrderRepository::class)->toBeLeakless();
});
```
