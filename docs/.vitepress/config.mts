import { defineConfig } from 'vitepress';
import pkg from '../../package.json';

export default defineConfig({
  title: 'Leakless',
  description: 'Zero-State & Memory Leak Prevention for PHP Persistent Workers (FrankenPHP & Laravel Octane)',
  lastUpdated: false,
  cleanUrls: true,

  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/logo.svg' }],
    ['meta', { name: 'theme-color', content: '#ff2d20' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:locale', content: 'en' }],
    ['meta', { property: 'og:title', content: 'Leakless | Zero-State & Memory Leak Prevention for PHP Persistent Workers' }],
    ['meta', { property: 'og:site_name', content: 'Leakless' }],
    ['meta', { property: 'og:url', content: 'https://leakless.themattos.dev' }],
  ],

  locales: {
    root: {
      label: 'English',
      lang: 'en',
      themeConfig: {
        nav: [
          { text: 'Guide', link: '/guide/getting-started' },
          { text: 'Architecture', link: '/guide/kernel-memory' },
          { text: 'Anti-Patterns', link: '/anti-patterns/' },
          { text: 'Dev Tooling', link: '/tooling/cli-and-testing' },
          { text: 'API Reference', link: '/api/config' },
          {
            text: `v${pkg.version}`,
            items: [
              { text: `v${pkg.version} (Latest)`, link: 'https://github.com/themattosdev/leakless/releases' },
              { text: 'Changelog', link: 'https://github.com/themattosdev/leakless/releases' },
              { text: 'All Releases', link: 'https://github.com/themattosdev/leakless/releases' },
            ],
          },
        ],
        sidebar: [
          {
            text: 'Introduction',
            items: [
              { text: 'Why Leakless?', link: '/guide/why-leakless' },
              { text: 'Getting Started', link: '/guide/getting-started' },
              { text: 'Migrating from PHP-FPM', link: '/guide/migrating-from-fpm' },
              { text: 'Laravel Octane', link: '/guide/laravel-octane' },
              { text: 'Vanilla FrankenPHP', link: '/guide/frankenphp' },
            ],
          },
          {
            text: 'Architecture & Engine',
            items: [
              { text: 'Real Kernel RSS (/proc)', link: '/guide/kernel-memory' },
              { text: 'Transaction Guard (PDO)', link: '/guide/transaction-guard' },
              { text: 'Defensive State Rollback', link: '/guide/state-rollback' },
              { text: 'Worker Recycling Lifecycle', link: '/guide/worker-recycling' },
            ],
          },
          {
            text: 'Worker Anti-Patterns',
            items: [
              { text: 'Catalogue Overview', link: '/anti-patterns/' },
              { text: 'Mutable Static Properties', link: '/anti-patterns/mutable-statics' },
              { text: 'Singleton Injections', link: '/anti-patterns/singleton-injections' },
              { text: 'Native Sessions & Headers', link: '/anti-patterns/native-sessions' },
              { text: 'Alpine musl & GLOB_BRACE', link: '/anti-patterns/glob-brace' },
            ],
          },
          {
            text: 'Developer Tooling',
            items: [
              { text: 'Static Linter CLI', link: '/tooling/cli' },
              { text: 'Pest Custom Expectations', link: '/tooling/pest' },
              { text: 'Laravel HTTP Test Macros', link: '/tooling/laravel-macros' },
              { text: 'PHPStan AST Rules', link: '/tooling/phpstan' },
            ],
          },
          {
            text: 'Reference',
            items: [
              { text: 'Configuration Options', link: '/api/config' },
              { text: 'Attributes & DTOs', link: '/api/dtos' },
            ],
          },
        ],
      },
    },
    pt: {
      label: 'Português',
      lang: 'pt-BR',
      link: '/pt/',
      themeConfig: {
        nav: [
          { text: 'Guia', link: '/pt/guide/getting-started' },
          { text: 'Arquitetura', link: '/pt/guide/kernel-memory' },
          { text: 'Anti-Patterns', link: '/pt/anti-patterns/' },
          { text: 'Ferramentas Dev', link: '/pt/tooling/cli-and-testing' },
          { text: 'Referência API', link: '/pt/api/config' },
          {
            text: `v${pkg.version}`,
            items: [
              { text: `v${pkg.version} (Mais recente)`, link: 'https://github.com/themattosdev/leakless/releases' },
              { text: 'Changelog / Histórico', link: 'https://github.com/themattosdev/leakless/releases' },
              { text: 'Todas as Versões', link: 'https://github.com/themattosdev/leakless/releases' },
            ],
          },
        ],
        sidebar: [
          {
            text: 'Introdução',
            items: [
              { text: 'Por que o Leakless?', link: '/pt/guide/why-leakless' },
              { text: 'Primeiros Passos', link: '/pt/guide/getting-started' },
              { text: 'Migrando do PHP-FPM', link: '/pt/guide/migrating-from-fpm' },
              { text: 'Laravel Octane', link: '/pt/guide/laravel-octane' },
              { text: 'PHP Vanilla & FrankenPHP', link: '/pt/guide/frankenphp' },
            ],
          },
          {
            text: 'Arquitetura & Motor',
            items: [
              { text: 'Memória Real do Kernel (/proc)', link: '/pt/guide/kernel-memory' },
              { text: 'Transaction Guard (PDO)', link: '/pt/guide/transaction-guard' },
              { text: 'Rollback Defensivo de Estado', link: '/pt/guide/state-rollback' },
              { text: 'Ciclo de Reciclagem de Workers', link: '/pt/guide/worker-recycling' },
            ],
          },
          {
            text: 'Catálogo de Anti-Patterns',
            items: [
              { text: 'Visão Geral do Catálogo', link: '/pt/anti-patterns/' },
              { text: 'Propriedades Estáticas Mutáveis', link: '/pt/anti-patterns/mutable-statics' },
              { text: 'Injeções em Singletons', link: '/pt/anti-patterns/singleton-injections' },
              { text: 'Sessões Nativas & Headers', link: '/pt/anti-patterns/native-sessions' },
              { text: 'Alpine musl & GLOB_BRACE', link: '/pt/anti-patterns/glob-brace' },
            ],
          },
          {
            text: 'Ferramentas de Desenvolvimento',
            items: [
              { text: 'CLI de Análise Estática', link: '/pt/tooling/cli' },
              { text: 'Expectativas Customizadas do Pest', link: '/pt/tooling/pest' },
              { text: 'Macros de Teste do Laravel', link: '/pt/tooling/laravel-macros' },
              { text: 'Regras de AST do PHPStan', link: '/pt/tooling/phpstan' },
            ],
          },
          {
            text: 'Referência',
            items: [
              { text: 'Opções de Configuração', link: '/pt/api/config' },
              { text: 'Atributos & DTOs', link: '/pt/api/dtos' },
            ],
          },
        ],
      },
    },
  },

  themeConfig: {
    logo: '/logo.svg',
    siteTitle: 'Leakless',

    socialLinks: [
      { icon: 'github', link: 'https://github.com/themattosdev/leakless' },
    ],

    search: {
      provider: 'local',
    },

    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright © 2026 Jonathan Gonçalves',
    },
  },
});
