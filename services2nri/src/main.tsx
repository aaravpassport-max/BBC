/**
 * Entry point — matches the bottom of compiled app.js:
 *
 *   const at = document.getElementById('s2nri-root')
 *   at && _t(at).render(t.jsx(S.StrictMode, { children: t.jsx(jr, { children: t.jsx(Tr, {}) }) }))
 *
 * Mounts React into #s2nri-root injected by PHP (Portal.php / SEO.php —
 * confirmed via direct search; the file previously named here,
 * "PortalRenderer.php", does not exist anywhere in this codebase)
 */

import React from 'react'
import { createRoot } from 'react-dom/client'
import { App } from './App'

const root = document.getElementById('s2nri-root')
if (root) {
  root.classList.add('s2-ds')
  createRoot(root).render(
    <React.StrictMode>
      <App />
    </React.StrictMode>
  )
}
