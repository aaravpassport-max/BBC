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
import './pages/admin/width-layout-studio.css'

const root = document.getElementById('s2nri-root')
if (root) {
  root.classList.add('s2-ds')
  try {
    createRoot(root).render(
      <React.StrictMode>
        <App />
      </React.StrictMode>
    )
    window.dispatchEvent(new Event('s2nri-app-mounted'))
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err)
    root.innerHTML =
      '<div style="padding:48px 24px;text-align:center;font-family:system-ui,sans-serif;max-width:560px;margin:0 auto">' +
      '<p style="font-weight:700;color:#1e293b">App failed to start</p>' +
      '<p style="color:#64748b;font-size:14px">' +
      msg.replace(/</g, '&lt;') +
      '</p></div>'
    window.dispatchEvent(new CustomEvent('s2nri-app-mounted', { detail: { error: msg } }))
  }
}
