import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const backendOrigin = process.env.VITE_BACKEND_ORIGIN ?? 'http://localhost:8000'

  return {
    plugins: [vue()],
    server: {
      host: '0.0.0.0',
      port: 5173,
      strictPort: true,
      proxy: {
        '/api': {
          target: backendOrigin,
          changeOrigin: true,
        },
      },
    },
    preview: {
      host: '0.0.0.0',
      port: 4173,
      strictPort: true,
    },
    test: {
      environment: 'node',
      include: ['src/**/*.test.ts'],
    },
    define: {
      __APP_MODE__: JSON.stringify(mode),
    },
  }
})
