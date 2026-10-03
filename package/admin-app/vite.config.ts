import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Fixed at 5177, distinct from the Customer app (5173) and Driver app
// (5175), so all three can run side by side against the same backend.
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5177,
  },
})
