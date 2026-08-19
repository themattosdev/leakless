# Primeiros Passos

O Leakless é distribuído em dois pacotes complementares:

1. **`themattosdev/leakless` (Pacote de Runtime)**: O motor de produção leve e com zero dependências externas para monitoramento de workers, proteção de transações PDO e restauração de estado.
2. **`themattosdev/leakless-dev` (Pacote de Desenvolvimento)**: Ferramentas de desenvolvimento contendo o CLI de análise estática (`vendor/bin/leakless analyze`), asserções customizadas para o Pest, macros de teste do Laravel e regras de AST do PHPStan.

---

## Instalação

Instale o pacote de runtime nas suas dependências de produção:

```bash
composer require themattosdev/leakless
```

Instale as ferramentas de desenvolvimento nas dependências de desenvolvimento:

```bash
composer require --dev themattosdev/leakless-dev
```

---

## Requisitos do Sistema

- **Versão do PHP**: `^8.2` ou superior
- **Extensões**: `ext-posix` e `ext-pcntl` (padrão em imagens Linux e Docker do FrankenPHP)
- **Runtimes Suportados**:
  - FrankenPHP Worker Mode (`frankenphp_handle_request`)
  - Laravel Octane (via driver FrankenPHP; drivers para RoadRunner e Swoole planejados no roadmap)
  - Loops de eventos persistentes customizados

---

## Configuração Rápida por Ambiente

### Laravel Octane

Se você utiliza o Laravel Octane, o Leakless não requer **nenhuma alteração de código**:

1. Instale o pacote via Composer.
2. O Leakless registra automaticamente o `LeaklessServiceProvider` e intercepta os eventos `RequestReceived` e `RequestTerminated` do Octane.
3. Configure os limites no seu arquivo `.env`:

```ini
LEAKLESS_ENABLED=true
LEAKLESS_MAX_RSS_MB=96
LEAKLESS_MAX_REQUESTS=1000
LEAKLESS_CHECK_TRANSACTIONS=true
LEAKLESS_LOG_VIOLATIONS=true
```

Consulte o [Guia do Laravel Octane](./laravel-octane.md) para configurações avançadas.

---

## 2. Instalação em PHP Vanilla (FrankenPHP)

Em projetos sem framework ou sob scripts customizados do FrankenPHP, utilize a fachada fluída `FrankenPhp::run()`:

```php
<?php

declare(strict_types=1);

use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Integrations\FrankenPhp\FrankenPhp;

require_once __DIR__ . '/vendor/autoload.php';

$config = new Config(
    maxRssMb: 96,
    maxRequests: 1000,
    checkTransactions: true,
    logViolations: true,
);

FrankenPhp::run(function () {
    // Processamento da requisição HTTP
    echo json_encode(['status' => 'sucesso', 'timestamp' => microtime(true)]);
}, $config);
```

Consulte o [Guia de FrankenPHP Vanilla](./frankenphp.md) para detalhes de integração em loops manuais.

---

## Próximos Passos

- Aprenda como [Migrar do PHP-FPM](./migrating-from-fpm.md) sem risco de downtime.
- Entenda como funciona a [Memória Real do Kernel (RSS)](./kernel-memory.md) no Linux.
- Conheça as [Regras de AST do PHPStan](../tooling/phpstan.md) para análise estática.
- Execute análises estáticas no seu CI/CD com o [CLI do Leakless](../tooling/cli.md).
