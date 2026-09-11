/**
 * NAS Service Worker v1.0
 * Caches the PWA app shell for offline use.
 * Strategy: Cache-first for app shell; Network-first for API calls.
 */
const SW_VERSION = 'nas-pwa-v1';
const SHELL_CACHE = SW_VERSION + '-shell';
const DATA_CACHE  = SW_VERSION + '-data';

// App shell resources to pre-cache on install
const SHELL_FILES = [
  '/nas-app/',
  '/nas-sw.js',
  '/nas-app/manifest.json',
];

// ── Install: pre-cache app shell ────────────────────────────────────────────
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(SHELL_CACHE).then(cache => {
      return cache.addAll(SHELL_FILES).catch(err => {
        console.warn('[NAS SW] Shell pre-cache partial failure:', err);
      });
    }).then(() => self.skipWaiting())
  );
});

// ── Activate: clean up old caches ───────────────────────────────────────────
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys
          .filter(k => k.startsWith('nas-pwa-') && k !== SHELL_CACHE && k !== DATA_CACHE)
          .map(k => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

// ── Fetch: routing strategy ──────────────────────────────────────────────────
self.addEventListener('fetch', event => {
  const req = event.request;
  const url = new URL(req.url);

  // Skip non-GET and cross-origin requests
  if (req.method !== 'GET' || url.origin !== self.location.origin) return;

  // Skip WP admin and non-PWA AJAX
  if (url.pathname.startsWith('/wp-admin') || url.pathname.startsWith('/wp-login')) return;

  // AJAX API calls: Network-first, fall back to cache
  if (url.pathname.includes('admin-ajax.php') || url.searchParams.has('nas_pwa')) {
    event.respondWith(networkFirst(req, DATA_CACHE, 5000));
    return;
  }

  // NAS assets (CSS/JS/images): Cache-first
  if (url.pathname.includes('/wp-content/plugins/') && url.pathname.match(/\.(css|js|png|jpg|svg|woff2?)$/)) {
    event.respondWith(cacheFirst(req, SHELL_CACHE));
    return;
  }

  // PWA shell: Cache-first (serve offline)
  if (url.pathname.startsWith('/nas-app')) {
    event.respondWith(cacheFirst(req, SHELL_CACHE, '/nas-app/'));
    return;
  }

  // Everything else: network only (WP pages, theme)
});

// ── Push notifications ───────────────────────────────────────────────────────
self.addEventListener('push', event => {
  if (!event.data) return;
  let data = {};
  try { data = event.data.json(); } catch(e) { data = {title:'NAS Update', body: event.data.text()}; }
  event.waitUntil(
    self.registration.showNotification(data.title || 'NAS Update', {
      body:    data.body    || 'You have a new update.',
      icon:    data.icon    || '/wp-content/plugins/nas-v7/assets/pwa/icon-192.png',
      badge:   data.badge   || '/wp-content/plugins/nas-v7/assets/pwa/badge-72.png',
      tag:     data.tag     || 'nas-notification',
      data:    data.url     ? {url: data.url} : {},
      actions: data.actions || [],
      vibrate: [100, 50, 100],
      requireInteraction: !!data.require_interaction,
    })
  );
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const url = event.notification.data?.url || '/nas-app/';
  event.waitUntil(
    clients.matchAll({type:'window', includeUncontrolled:true}).then(cs => {
      for (const c of cs) {
        if (c.url === url && 'focus' in c) return c.focus();
      }
      if (clients.openWindow) return clients.openWindow(url);
    })
  );
});

// ── Strategy helpers ─────────────────────────────────────────────────────────
async function cacheFirst(req, cacheName, fallbackUrl) {
  const cache    = await caches.open(cacheName);
  const cached   = await cache.match(req);
  if (cached) return cached;
  try {
    const fresh = await fetch(req);
    if (fresh.ok) cache.put(req, fresh.clone());
    return fresh;
  } catch(e) {
    if (fallbackUrl) {
      const fb = await cache.match(fallbackUrl);
      if (fb) return fb;
    }
    return new Response('<h2>You are offline</h2><p>Please check your connection.</p>', {
      headers: {'Content-Type':'text/html'}
    });
  }
}

async function networkFirst(req, cacheName, timeoutMs) {
  const cache = await caches.open(cacheName);
  try {
    const ctrl    = new AbortController();
    const timeout = setTimeout(() => ctrl.abort(), timeoutMs || 5000);
    const fresh   = await fetch(req, {signal: ctrl.signal});
    clearTimeout(timeout);
    if (fresh.ok) cache.put(req, fresh.clone());
    return fresh;
  } catch(e) {
    const cached = await cache.match(req);
    if (cached) return cached;
    throw e;
  }
}
