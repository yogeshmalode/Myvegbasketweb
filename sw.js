// Minimal service worker — just enough to make the site installable as an
// app on Android/iOS home screens. Static assets (CSS/JS/images) are
// cached for speed and offline use; PHP pages (product listings, cart,
// checkout, etc.) always go to the network since prices/stock/orders
// change constantly and must never be served stale.
const CACHE_NAME = 'myvegbasket-v2';
const STATIC_ASSETS = [
  '/assets/css/style.css',
  '/assets/js/script.js',
  '/assets/images/icon-192.png',
  '/assets/images/icon-512.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS)).catch(() => {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // Only handle GET requests for our own static assets — everything else
  // (PHP pages, AJAX calls, external requests) passes straight through to
  // the network untouched.
  const isStaticAsset = STATIC_ASSETS.some((path) => url.pathname === path);
  if (event.request.method !== 'GET' || !isStaticAsset) {
    return;
  }

  event.respondWith(
    caches.match(event.request).then((cached) => {
      const networkFetch = fetch(event.request)
        .then((response) => {
          caches.open(CACHE_NAME).then((cache) => cache.put(event.request, response.clone()));
          return response;
        })
        .catch(() => cached);
      return cached || networkFetch;
    })
  );
});
