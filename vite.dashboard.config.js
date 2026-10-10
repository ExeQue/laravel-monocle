import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

// Builds the dashboard into resources/dist with fixed file names; the files are
// committed and served by the package, so the host application builds nothing.
export default defineConfig({
  plugins: [tailwindcss()],
  publicDir: 'resources/static',
  build: {
    outDir: 'resources/dist',
    emptyOutDir: true,
    assetsDir: '',
    cssCodeSplit: false,
    reportCompressedSize: false,
    rollupOptions: {
      input: 'resources/js/dashboard.js',
      output: {
        format: 'iife',
        entryFileNames: 'monocle.js',
        assetFileNames: 'monocle.[ext]',
        banner: '/*! Monocle dashboard. Bundles Alpine.js (c) Caleb Porzio and contributors, MIT license. */',
      },
    },
  },
});
