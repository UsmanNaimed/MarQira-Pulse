import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    // Accept the platform preview host (and any host) during development so the
    // dev server does not reject proxied requests with a 403 "host not allowed".
    allowedHosts: true,
    proxy: {
      // In local dev, proxy API + auth calls to the Laravel API so cookies are
      // same-origin. Override the target with VITE_DEV_API_TARGET if needed.
      '/api': {
        target: process.env.VITE_DEV_API_TARGET || 'http://localhost:8000',
        changeOrigin: true,
      },
      '/sanctum': {
        target: process.env.VITE_DEV_API_TARGET || 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
  preview: {
    port: 4173,
    allowedHosts: true,
  },
});
