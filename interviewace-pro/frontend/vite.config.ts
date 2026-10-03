import path from 'path';
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Builds straight into the WordPress plugin's react-app/dist so the PHP
// side (interviewace.php: wp_enqueue_script('ia-app', ...react-app/dist/app.js))
// needs no changes. Single JS + single CSS file, fixed names, no hashing —
// matches the wp_enqueue_script/style version-cache-busting the plugin
// already does via IA_VERSION instead of filename hashes.
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: { '@': path.resolve(__dirname, 'src') },
  },
  base: '', // asset URLs are relative; WP serves this from react-app/dist/
  build: {
    outDir: '../react-app/dist',
    emptyOutDir: true,
    sourcemap: true,
    rollupOptions: {
      output: {
        entryFileNames: 'app.js',
        chunkFileNames: 'app-[name].js',
        assetFileNames: (info) =>
          info.name && info.name.endsWith('.css') ? 'app.css' : 'assets/[name][extname]',
      },
    },
  },
});
