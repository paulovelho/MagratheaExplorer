import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  base: '/app/',
  server: {
    proxy: {
      '/api/v1': 'http://explorer.magrathea.localhost.com:8080',
    },
  },
})
