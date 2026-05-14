/**
 * Trash Panda Roll-Offs – Public Site Service Worker
 *
 * Strategy:
 *   - Static assets (CSS, JS, images, fonts): cache-first with background refresh.
 *   - Navigation requests (HTML pages): network-first with offline fallback.
 *   - API requests: network-only (never cache booking/contact data).
 */

const CACHE_VERSION = 'tp-public-v5';

const PRECACHE_URLS = [
  '/',
  '/index.html',
  '/services.html',
  '/sizes.html',
  '/service-areas.html',
  '/about.html',
  '/faq.html',
  '/contact.html',
  '/book.php',
  '/my-bookings.php',
  '/home.css',
  '/shared.css',
  '/shared-components.js?v=4',
  '/assets/logo.jpeg',
  '/assets/icon-192.png',
  '/assets/icon-512.png',
  '/offline.html',
];

// ── Install: pre-cache core shell ──────────────────────────────────────────
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_VERSION).then((cache) => cache.addAll(PRECACHE_URLS))
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

  // API calls: always go to network, never cache
  if (url.pathname.startsWith('/api/')) {
    return;
  }

  // Static assets: cache-first
  if (isStaticAsset(url.pathname)) {
    event.respondWith(cacheFirst(request));
    return;
  }

  // HTML pages: network-first with offline fallback
  event.respondWith(networkFirstWithOfflineFallback(request));
});

// ── Helpers ───────────────────────────────────────────────────────────────

function isStaticAsset(pathname) {
  return /\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|eot|webp)$/i.test(pathname);
}

async function cacheFirst(request) {
  const cached = await caches.match(request);
  if (cached) {
    // Refresh in background
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

async function networkFirstWithOfflineFallback(request) {
  try {
    const response = await fetch(request);
    if (response && response.ok) {
      const cache = await caches.open(CACHE_VERSION);
      cache.put(request, response.clone());
    }
    return response;
  } catch {
    const cached = await caches.match(request);
    if (cached) return cached;
    const offlinePage = await caches.match('/offline.html');
    return offlinePage || new Response('<h1>You are offline</h1>', {
      headers: { 'Content-Type': 'text/html' },
    });
  }
}

// ── Push Notifications ─────────────────────────────────────────────────────
self.addEventListener('push', (event) => {
  let data = { title: 'Trash Panda Roll-Offs', body: 'You have a new notification.' };
  if (event.data) {
    try { data = event.data.json(); } catch { data.body = event.data.text(); }
  }
  const title   = data.title  || 'Trash Panda Roll-Offs';
  const options = {
    body:    data.body  || '',
    icon:    data.icon  || '/assets/icon-192.png',
    badge:   data.badge || '/assets/icon-192.png',
    data:    { url: data.url || '/' },
    vibrate: [200, 100, 200],
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = event.notification.data?.url || '/';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if (client.url === url && 'focus' in client) return client.focus();
      }
      if (clients.openWindow) return clients.openWindow(url);
    })
  );
});
