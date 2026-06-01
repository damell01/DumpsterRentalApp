/**
 * Trash Panda Roll-Offs – Admin Panel Service Worker
 *
 * Strategy:
 *   - Static assets (CSS, JS, fonts, images): cache-first with background refresh.
 *   - Admin page requests: network-first so live data is always fresh.
 *   - No offline fallback for admin pages (auth state depends on server session).
 */

const CACHE_VERSION = 'tp-admin-v3';

const PRECACHE_URLS = [
  '/admin/assets/css/app.css',
  '/admin/assets/js/app.js',
  '/admin/assets/img/icon-192.png',
  '/admin/assets/img/icon-512.png',
];

// ── Install: pre-cache static shell ───────────────────────────────────────
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_VERSION).then((cache) =>
      // addAll may fail if assets are not yet deployed; ignore errors gracefully.
      cache.addAll(PRECACHE_URLS).catch(() => {})
    )
  );
  self.skipWaiting();
});

// ── Activate: clean up stale caches ───────────────────────────────────────
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => key !== CACHE_VERSION)
          .map((key) => caches.delete(key))
      )
    )
  );
  self.clients.claim();
});

// ── Fetch: route-based caching strategy ───────────────────────────────────
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Skip non-GET and cross-origin requests
  if (request.method !== 'GET' || url.origin !== self.location.origin) {
    return;
  }

  // Never intercept dynamic admin/API requests. Let the browser hit PHP directly
  // so auth/session-sensitive screens don't get synthetic SW 503 responses.
  if (url.pathname.startsWith('/admin/api/') || /\.php$/i.test(url.pathname)) {
    return;
  }

  // Static assets: cache-first
  if (isStaticAsset(url.pathname)) {
    event.respondWith(cacheFirst(request));
    return;
  }

  // Leave everything else alone.
});

// ── Helpers ───────────────────────────────────────────────────────────────

function isStaticAsset(pathname) {
  return /\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|eot|webp)$/i.test(pathname);
}

async function cacheFirst(request) {
  const cached = await caches.match(request);
  if (cached) {
    // Refresh cache in background
    fetch(request)
      .then((response) => {
        if (response && response.ok) {
          caches.open(CACHE_VERSION).then((cache) => cache.put(request, response));
        }
      })
      .catch(() => {});
    return cached;
  }
  try {
    const response = await fetch(request);
    if (response && response.ok) {
      const cache = await caches.open(CACHE_VERSION);
      cache.put(request, response.clone());
    }
    return response;
  } catch {
    return new Response('', { status: 503, statusText: 'Service Unavailable' });
  }
}

// ── Push Notifications ─────────────────────────────────────────────────────
self.addEventListener('push', (event) => {
  let data = { title: 'Trash Panda Admin', body: 'You have a new notification.' };
  if (event.data) {
    try { data = event.data.json(); } catch { data.body = event.data.text(); }
  }
  const title   = data.title  || 'Trash Panda Admin';
  const options = {
    body:    data.body  || '',
    icon:    data.icon  || '/admin/assets/img/icon-192.png',
    badge:   data.badge || '/admin/assets/img/icon-192.png',
    data:    { url: data.url || '/admin/dashboard.php' },
    vibrate: [200, 100, 200],
    requireInteraction: true,
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = event.notification.data?.url || '/admin/dashboard.php';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if (client.url === url && 'focus' in client) return client.focus();
      }
      if (clients.openWindow) return clients.openWindow(url);
    })
  );
});
