import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';
import './styles/global.css';

/*
 * ROOT-CAUSE FIX: this dev-only window.IA_CONFIG stand-in used to live as
 * an inline, non-module <script> block in index.html and referenced
 * `import.meta.env` there — `import.meta` is only valid syntax inside an
 * ES module, so that script threw a SyntaxError the instant the page
 * loaded and never actually ran, breaking `npm run dev` entirely (the real
 * production app is unaffected: WordPress sets window.IA_CONFIG itself via
 * wp_localize_script in templates/app-page.php, before this file ever
 * loads). Moved here, where import.meta.env works correctly, and it only
 * fills in the value when WordPress hasn't already provided one.
 */
if (!window.IA_CONFIG) {
  window.IA_CONFIG = {
    apiBase: import.meta.env.VITE_API_BASE || 'http://localhost:8888/wp-json/ia/v1',
    siteUrl: 'http://localhost:8888',
    elevenLabsVoice: 'EXAVITQu4vr4xnSDxMaL',
    razorpayKeyId: '',
    googleClientId: '',
    version: 'dev',
    assetsUrl: '/assets/',
    priyaAvatarUrl: '/assets/priya-photo.jpg',
    plans: {},
  };
}

// Remove the boot spinner the PHP template renders inline (templates/app-page.php
// #ia-boot) the moment React actually mounts, instead of it lingering behind
// or the app rendering on top of it.
const boot = document.getElementById('ia-boot');
boot?.remove();

const container = document.getElementById('ia-root');
if (!container) {
  throw new Error('#ia-root mount point not found — check templates/app-page.php');
}

createRoot(container).render(
  <StrictMode>
    <App />
  </StrictMode>
);
