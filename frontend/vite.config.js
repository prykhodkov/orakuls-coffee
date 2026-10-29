import { resolve } from 'node:path';
import { defineConfig, loadEnv } from 'vite';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');

  return {
    build: {
      rollupOptions: {
        input: {
          main: resolve(import.meta.dirname, 'index.html'),
          admin: resolve(import.meta.dirname, 'admin/index.html'),
        },
      },
    },
    server: {
      // Коли бекенд буде піднятий — вказати його адресу в API_PROXY_TARGET (.env.local)
      proxy: env.API_PROXY_TARGET
        ? { '/api': { target: env.API_PROXY_TARGET, changeOrigin: true } }
        : undefined,
    },
  };
});
