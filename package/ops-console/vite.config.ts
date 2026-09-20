import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Fixed at 5179, distinct from Customer (5173), Driver (5175), and Admin
// (5177), so all four can run side by side against the same backend.
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5179,
  },
})
