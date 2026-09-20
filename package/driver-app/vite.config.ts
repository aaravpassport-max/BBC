import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Fixed at 5175 (distinct from the Customer app's 5173) so both frontends
// can run side by side against the same backend during development.
export default defineConfig({
  plugins: [react()],
  base: './',
  server: {
    port: 5175,
  },
})
