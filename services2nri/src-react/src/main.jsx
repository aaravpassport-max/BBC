import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import App from './App'

const root = document.getElementById('s2nri-builder-root')
if (root) {
  root.classList.add('s2-mobile-app-root')
  createRoot(root).render(<StrictMode><App /></StrictMode>)
}
