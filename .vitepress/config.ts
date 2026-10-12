import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitepress'

// The markdown in this repository is the source: the site is a view of it, not a copy.
// Everything links the same way on GitHub and on the site, so srcDir is the repository root.
export default defineConfig({
  title: 'Monocle',
  description: 'A request profiler for Laravel, built for AI coding agents.',
  base: '/laravel-monocle/',
  lastUpdated: true,
  cleanUrls: true,

  srcExclude: [
    'vendor/**',
    'node_modules/**',
    'tests/**',
    'workbench/**',
    'build/**',
    'config/**',
  ],

  // README.md is the front page here, and stays the front page on GitHub.
  rewrites: {
    'README.md': 'index.md',
    'docs/README.md': 'docs/index.md',
  },

  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/laravel-monocle/monocle.svg' }],
    ['meta', { property: 'og:image', content: 'https://exeque.github.io/laravel-monocle/art/cover.jpg' }],
  ],

  // The dashboard's icon doubles as the favicon, served from its own folder.
  vite: {
    publicDir: fileURLToPath(new URL('../resources/static', import.meta.url)),
  },

  themeConfig: {
    logo: '/monocle.svg',

    nav: [
      { text: 'Documentation', link: '/docs/' },
      { text: 'Packagist', link: 'https://packagist.org/packages/exeque/laravel-monocle' },
    ],

    sidebar: [
      {
        text: 'Getting started',
        items: [
          { text: 'Introduction', link: '/' },
          { text: 'Installation', link: '/docs/installation' },
          { text: 'Documentation index', link: '/docs/' },
        ],
      },
      {
        text: 'Reading an entry',
        items: [
          { text: 'For AI agents (MCP)', link: '/docs/agents' },
          { text: 'Artisan commands', link: '/docs/commands' },
          { text: 'Dashboard', link: '/docs/dashboard' },
          { text: 'Tracing', link: '/docs/tracing' },
          { text: 'Browser tracing', link: '/docs/browser-tracing' },
        ],
      },
      {
        text: 'What gets recorded',
        items: [
          { text: 'Overview', link: '/docs/lenses' },
          {
            text: 'Core lenses',
            collapsed: true,
            items: [
              { text: 'Request', link: '/docs/lenses/request' },
              { text: 'Session', link: '/docs/lenses/session' },
              { text: 'Auth', link: '/docs/lenses/auth' },
              { text: 'Validation', link: '/docs/lenses/validation' },
              { text: 'Middleware', link: '/docs/lenses/middleware' },
              { text: 'Database', link: '/docs/lenses/database' },
              { text: 'Eloquent', link: '/docs/lenses/eloquent' },
              { text: 'Cache', link: '/docs/lenses/cache' },
              { text: 'Redis', link: '/docs/lenses/redis' },
              { text: 'Views', link: '/docs/lenses/views' },
              { text: 'Events', link: '/docs/lenses/events' },
              { text: 'Queue', link: '/docs/lenses/queue' },
              { text: 'Artisan commands', link: '/docs/lenses/artisan-commands' },
              { text: 'Scheduler', link: '/docs/lenses/scheduler' },
              { text: 'HTTP client', link: '/docs/lenses/http-client' },
              { text: 'Mail and notifications', link: '/docs/lenses/mail-and-notifications' },
              { text: 'Filesystem', link: '/docs/lenses/filesystem' },
              { text: 'Processes', link: '/docs/lenses/processes' },
              { text: 'Exceptions', link: '/docs/lenses/exceptions' },
              { text: 'Log', link: '/docs/lenses/log' },
              { text: 'Authorization', link: '/docs/lenses/authorization' },
              { text: 'Dumps', link: '/docs/lenses/dumps' },
            ],
          },
          {
            text: 'Package lenses',
            collapsed: true,
            items: [
              { text: 'filament/filament', link: '/docs/lenses/filament' },
              { text: 'franbarbalopez/mirror', link: '/docs/lenses/auth#impersonation' },
              { text: 'inertiajs/inertia-laravel', link: '/docs/lenses/inertia' },
              { text: 'lab404/laravel-impersonate', link: '/docs/lenses/auth#impersonation' },
              { text: 'laravel/ai', link: '/docs/lenses/ai' },
              { text: 'laravel/mcp', link: '/docs/lenses/mcp-servers' },
              { text: 'laravel/nova', link: '/docs/lenses/auth#impersonation' },
              { text: 'laravel/pennant', link: '/docs/lenses/feature-flags' },
              { text: 'livewire/livewire', link: '/docs/lenses/livewire' },
              { text: 'nuwave/lighthouse', link: '/docs/lenses/graphql' },
              { text: 'prism-php/prism', link: '/docs/lenses/prism' },
              { text: 'rebing/graphql-laravel', link: '/docs/lenses/graphql' },
              { text: 'spatie/laravel-event-sourcing', link: '/docs/lenses/event-sourcing' },
              { text: 'spatie/laravel-medialibrary', link: '/docs/lenses/media-library' },
              { text: 'spatie/laravel-responsecache', link: '/docs/lenses/response-cache' },
              { text: 'stechstudio/filament-impersonate', link: '/docs/lenses/auth#impersonation' },
            ],
          },
          { text: 'Third-party lenses', link: '/docs/third-party-lenses' },
        ],
      },
      {
        text: 'Recording more',
        items: [
          { text: 'Spans and metadata', link: '/docs/spans' },
          { text: 'Writing a lens', link: '/docs/writing-a-lens' },
          { text: 'Writing a GraphQL hook', link: '/docs/lenses/graphql-hooks' },
        ],
      },
      {
        text: 'Around it',
        items: [
          { text: 'Configuration', link: '/docs/configuration' },
          { text: 'Storage', link: '/docs/storage' },
        ],
      },
      {
        text: 'Contributing',
        items: [
          { text: 'Contributing', link: '/CONTRIBUTING' },
          { text: 'Security', link: '/SECURITY' },
          { text: 'Code of Conduct', link: '/CODE_OF_CONDUCT' },
        ],
      },
    ],

    search: {
      provider: 'local',
    },

    socialLinks: [
      { icon: 'github', link: 'https://github.com/ExeQue/laravel-monocle' },
    ],

    editLink: {
      pattern: 'https://github.com/ExeQue/laravel-monocle/edit/main/:path',
      text: 'Edit this page on GitHub',
    },

    outline: [2, 3],
  },
})
