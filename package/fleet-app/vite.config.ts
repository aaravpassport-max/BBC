import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Fixed at 5183, distinct from Customer (5173), Driver (5175), Admin (5177), Ops (5179).
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5183,
  },
})
