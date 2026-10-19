import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
export default defineConfig({
  plugins: [vue()],
  base: './',
  build: { target: ['es2019', 'chrome80', 'safari13'], outDir: '../app', emptyOutDir: true, assetsInlineLimit: 0, chunkSizeWarningLimit: 800 },
})
