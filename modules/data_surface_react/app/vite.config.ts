/// <reference types="vitest" />
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Builds one script and one stylesheet with fixed names into the
// module's dist/, which the Drupal library data_surface_react/app points
// at. An IIFE, so Drupal can load it as a plain script.
export default defineConfig({
  plugins: [react()],
  build: {
    outDir: '../dist',
    emptyOutDir: true,
    cssCodeSplit: false,
    rollupOptions: {
      input: 'src/main.tsx',
      output: {
        format: 'iife',
        entryFileNames: 'app.js',
        assetFileNames: (asset) => (asset.name?.endsWith('.css') ? 'app.css' : '[name][extname]'),
      },
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['src/test/setup.ts'],
  },
});
