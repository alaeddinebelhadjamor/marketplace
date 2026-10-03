import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vuetify from 'vite-plugin-vuetify'

// Espace vendeur Mytek Marketplace v2.
// L'adresse de l'API vient de VITE_API_URL (fichier .env), jamais du code.
export default defineConfig({
  plugins: [vue(), vuetify({ autoImport: true })],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  // Analyse toutes les pages au démarrage : évite le rechargement « Outdated Optimize Dep »
  // à la première visite d'une page en développement.
  optimizeDeps: {
    entries: ['index.html', 'src/**/*.vue'],
  },
  server: {
    host: '127.0.0.1',
    port: 5180,
    strictPort: true,
  },
  build: {
    chunkSizeWarningLimit: 1200,
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (id.includes('node_modules/echarts') || id.includes('node_modules/zrender')) return 'charts'
          if (id.includes('node_modules/vuetify')) return 'vuetify'
        },
      },
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./tests/setup.js'],
    server: { deps: { inline: ['vuetify'] } },
    css: false,
  },
})
