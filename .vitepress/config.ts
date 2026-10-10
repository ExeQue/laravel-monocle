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
    'config/**',
  ],

  // README.md is the front page here, and stays the front page on GitHub.
  rewrites: {
    'README.md': 'index.md',
    'docs/README.md': 'docs/index.md',
  },

  head: [
    ['meta', { property: 'og:image', content: 'https://exeque.github.io/laravel-monocle/art/cover.jpg' }],
  ],

  themeConfig: {
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
          { text: 'What gets recorded', link: '/docs/lenses' },
        ],
      },
      {
        text: 'Recording more',
        items: [
          { text: 'Spans and metadata', link: '/docs/spans' },
          { text: 'Writing a lens', link: '/docs/writing-a-lens' },
        ],
      },
      {
        text: 'Around it',
        items: [
          { text: 'Configuration', link: '/docs/configuration' },
          { text: 'Storage', link: '/docs/storage' },
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
