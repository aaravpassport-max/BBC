import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { resolve } from 'path'

/**
 * Vite config for s2nri React source
 * Source:  plugin/src/
 * Output:  plugin/assets/   (NOT dist/assets — Portal.php reads dist/assets)
 *
 * The live admin app is plugin/dist/assets/s2nri-portal.DhSw6QQs.js
 * Portal.php loads from dist/assets/. Do NOT output there — it would replace the live app.
 * This build output is the source/reference only, not what the live site runs.
 */
export default defineConfig({
  plugins: [react()],
  // Relative URLs so lazy-chunk preloads resolve next to app.js (WordPress plugin path).
  base: './',
  resolve: {
    alias: { '@': resolve(__dirname, 'src') }
  },
  build: {
    outDir: 'assets',
    emptyOutDir: false,
    // Single app.css — avoids app2.css on lazy admin chunks (breaks WP preload at /app2.css).
    cssCodeSplit: false,
    rollupOptions: {
      input: { app: resolve(__dirname, 'src/main.tsx') },
      output: {
        entryFileNames: 'app.js',
        chunkFileNames: 'chunks/[name].js',
        assetFileNames: (info) => {
          if (info.name?.endsWith('.css')) return 'app.css'
          return '[name][extname]'
        },
        manualChunks: {
          'react':     ['react', 'react-dom'],
          'router':    ['react-router-dom'],
          'booking':   ['./src/lib/api.ts', './src/lib/store.ts', './src/components/layout/Layout.tsx'],
          'dashboard': ['./src/pages/customer/index.tsx'],
          'admin':     ['./src/pages/admin/index.tsx'],
        }
      }
    }
  }
})
