import { defineConfig } from 'vitepress';
import pkg from '../../package.json';

const SITE_URL = 'https://leakless.themattos.dev';
const OG_IMAGE = 'https://raw.githubusercontent.com/themattosdev/leakless/master/art/banner.png';

export default defineConfig({
  title: 'Leakless',
  titleTemplate: ':title - Leakless',
  description: 'Zero-State & Memory Leak Prevention for Persistent PHP Workers (FrankenPHP, RoadRunner, Swoole, Symfony, Laravel & Vanilla)',
  lastUpdated: false,
  cleanUrls: true,

  sitemap: {
    hostname: SITE_URL,
  },

  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/logo.svg' }],
    ['meta', { name: 'theme-color', content: '#ff2d20' }],
    ['meta', { name: 'author', content: 'Jonathan Gonçalves' }],
    ['meta', { name: 'robots', content: 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1' }],
    [
      'meta',
      {
        name: 'keywords',
        content: 'php, frankenphp, laravel, octane, roadrunner, swoole, symfony, slim, memory leak, persistent worker, garbage collection, proc statm, pdo transactions, phpstan, pest, zero state, worker recycling, state pollution, memory drift, php memory leak fix, queue worker leak',
      },
    ],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:site_name', content: 'Leakless' }],
    ['meta', { property: 'og:image', content: OG_IMAGE }],
    ['meta', { property: 'og:image:alt', content: 'Leakless - PHP Persistent Worker Guardian' }],
    ['meta', { property: 'og:image:width', content: '1200' }],
    ['meta', { property: 'og:image:height', content: '630' }],
    ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
    ['meta', { name: 'twitter:image', content: OG_IMAGE }],
    ['meta', { name: 'twitter:image:alt', content: 'Leakless - PHP Persistent Worker Guardian' }],
    [
      'script',
      { type: 'application/ld+json' },
      JSON.stringify({
        '@context': 'https://schema.org',
        '@graph': [
          {
            '@type': 'WebSite',
            '@id': `${SITE_URL}/#website`,
            url: SITE_URL,
            name: 'Leakless',
            description: 'Zero-State & Memory Leak Prevention for Persistent PHP Workers (FrankenPHP, RoadRunner, Swoole, Symfony, Laravel & Vanilla)',
            inLanguage: ['en-US', 'pt-BR'],
          },
          {
            '@type': 'SoftwareApplication',
            '@id': `${SITE_URL}/#software`,
            name: 'Leakless',
            applicationCategory: 'DeveloperApplication',
            operatingSystem: 'Linux, macOS',
            programmingLanguage: 'PHP',
            url: SITE_URL,
            author: {
              '@type': 'Person',
              name: 'Jonathan Gonçalves',
              url: 'https://github.com/themattosdev',
            },
            license: 'https://opensource.org/licenses/MIT',
            softwareVersion: pkg.version,
          },
        ],
      }),
    ],
  ],

  transformPageData(pageData) {
    const cleanPath = pageData.relativePath
      .replace(/index\.md$/, '')
      .replace(/\.md$/, '');
    const canonicalUrl = `${SITE_URL}/${cleanPath}`.replace(/\/$/, '') || SITE_URL;

    pageData.frontmatter.head ??= [];

    // Canonical link
    pageData.frontmatter.head.push(['link', { rel: 'canonical', href: canonicalUrl }]);

    // OpenGraph dynamic URL
    pageData.frontmatter.head.push(['meta', { property: 'og:url', content: canonicalUrl }]);

    // i18n alternate links (hreflang)
    const isPt = pageData.relativePath.startsWith('pt/');
    const basePath = isPt ? cleanPath.replace(/^pt\/?/, '') : cleanPath;
    const enUrl = `${SITE_URL}/${basePath}`.replace(/\/$/, '') || SITE_URL;
    const ptUrl = `${SITE_URL}/pt/${basePath}`.replace(/\/$/, '');

    pageData.frontmatter.head.push(['link', { rel: 'alternate', hreflang: 'en', href: enUrl }]);
    pageData.frontmatter.head.push(['link', { rel: 'alternate', hreflang: 'pt-BR', href: ptUrl }]);
    pageData.frontmatter.head.push(['link', { rel: 'alternate', hreflang: 'x-default', href: enUrl }]);
  },

  locales: {
    root: {
      label: 'English',
      lang: 'en-US',
      description: 'Zero-State & Memory Leak Prevention for Persistent PHP Workers (FrankenPHP, RoadRunner, Swoole, Symfony, Laravel & Vanilla)',
      themeConfig: {
        nav: [
          { text: 'Guide', link: '/guide/getting-started' },
          { text: 'Troubleshooting', link: '/guide/troubleshooting/memory-leaks' },
          { text: 'Architecture', link: '/guide/kernel-memory' },
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
            ],
          },
          {
            text: 'Servers & Runtimes',
            items: [
              { text: 'Vanilla FrankenPHP', link: '/guide/frankenphp' },
              { text: 'RoadRunner (PSR-7)', link: '/guide/roadrunner' },
              { text: 'Swoole & OpenSwoole', link: '/guide/swoole' },
              { text: 'Queue Workers & CLI Daemons', link: '/guide/cli-daemons' },
            ],
          },
          {
            text: 'Frameworks & Stacks',
            items: [
              { text: 'Laravel Octane', link: '/guide/laravel-octane' },
              { text: 'Symfony', link: '/guide/symfony' },
              { text: 'PSR-15 & Microframeworks (Slim)', link: '/guide/psr-15' },
            ],
          },
          {
            text: 'Common Leaks & Troubleshooting',
            items: [
              { text: 'Fixing Memory Leaks & OOM', link: '/guide/troubleshooting/memory-leaks' },
              { text: 'Cross-User State Pollution', link: '/guide/troubleshooting/state-pollution' },
              { text: 'Orphaned Transactions & Locks', link: '/guide/troubleshooting/database-transactions' },
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
            text: 'Developer Tooling & Testing',
            items: [
              { text: 'Overview', link: '/tooling/cli-and-testing' },
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
              { text: 'Attributes & Reports', link: '/api/dtos' },
            ],
          },
        ],
      },
    },
    pt: {
      label: 'Português',
      lang: 'pt-BR',
      link: '/pt/',
      description: 'Prevenção de Estado e Vazamento de Memória para Workers Persistentes em PHP (FrankenPHP, RoadRunner, Swoole, Symfony, Laravel e Vanilla)',
      themeConfig: {
        nav: [
          { text: 'Guia', link: '/pt/guide/getting-started' },
          { text: 'Diagnóstico', link: '/pt/guide/troubleshooting/memory-leaks' },
          { text: 'Arquitetura', link: '/pt/guide/kernel-memory' },
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
            ],
          },
          {
            text: 'Servidores & Runtimes',
            items: [
              { text: 'PHP Vanilla & FrankenPHP', link: '/pt/guide/frankenphp' },
              { text: 'RoadRunner (PSR-7)', link: '/pt/guide/roadrunner' },
              { text: 'Swoole & OpenSwoole', link: '/pt/guide/swoole' },
              { text: 'Workers de Fila & Daemons CLI', link: '/pt/guide/cli-daemons' },
            ],
          },
          {
            text: 'Frameworks & Stacks',
            items: [
              { text: 'Laravel Octane', link: '/pt/guide/laravel-octane' },
              { text: 'Symfony', link: '/pt/guide/symfony' },
              { text: 'PSR-15 & Microframeworks (Slim)', link: '/pt/guide/psr-15' },
            ],
          },
          {
            text: 'Diagnóstico & Solução de Vazamentos',
            items: [
              { text: 'Vazamentos de Memória & OOM', link: '/pt/guide/troubleshooting/memory-leaks' },
              { text: 'Poluição de Estado Entre Usuários', link: '/pt/guide/troubleshooting/state-pollution' },
              { text: 'Transações Órfãs & Deadlocks', link: '/pt/guide/troubleshooting/database-transactions' },
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
            text: 'Ferramentas de Desenvolvimento & Testes',
            items: [
              { text: 'Visão Geral', link: '/pt/tooling/cli-and-testing' },
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
              { text: 'Atributos & Relatórios', link: '/pt/api/dtos' },
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
