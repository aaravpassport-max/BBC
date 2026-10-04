// S2NRI Service Worker — single source of truth.
//
// Both PWA::serveServiceWorker() (src/PWA.php, dynamic /sw.js PHP route) and
// the install-time static file writer (src/Installer.php::writePwaFiles())
// now read THIS file instead of each carrying their own separate inline
// copy. Previously they had two different, drifting versions of this logic,
// and the static file written to the WordPress root at install time could
// never be overwritten by the "live" PHP version, since the web server
// serves an on-disk static file before WordPress's routing ever runs. That
// meant a service worker written at install time could go stale forever if
// this logic ever changed later, silently caching old JS/CSS in visitors'
// browsers with no way for a code fix on the server to reach them.
//
// CACHE_VERSION MUST be bumped on any release where this file's caching
// strategy changes — not on every app.js/app.css change, since those are
// already cache-busted individually via the ?v= query string in SEO.php.
// Bumping it forces old CacheStorage entries to be deleted on 'activate'.
const CACHE_VERSION = 'v2';
const CACHE = 's2nri-' + CACHE_VERSION;

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  if (req.url.includes('/api/v1/')) return; // never cache API responses

  // Network-first: every request tries the network first, so a new deploy
  // is visible on the very next load. Cache is only a fallback for when the
  // network request fails (offline), not a shortcut that can go stale.
  event.respondWith(
    fetch(req)
      .then((response) => {
        if (response.ok) {
          const clone = response.clone();
          caches.open(CACHE).then((c) => c.put(req, clone));
        }
        return response;
      })
      .catch(() =>
        caches.match(req).then((cached) => cached || new Response('Offline', { status: 503 }))
      )
  );
});
