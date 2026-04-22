/**
 * نيوزفلو Service Worker
 *
 * Strategy:
 *   - HTML / dynamic pages: network-first with a 3s timeout, then
 *     cached fallback. Lets returning visitors see the latest news
 *     when online but still get something readable when offline.
 *   - Static assets (CSS, JS, fonts, images): cache-first with
 *     background revalidation. Almost everything in /assets is
 *     fingerprinted via ?v= query strings so this is safe.
 *   - Skips API endpoints, the admin panel, telegram_summary.php
 *     and anything cross-origin from being cached.
 *
 * Bump CACHE_VERSION whenever the asset bundle changes substantially
 * to force clients to discard the previous generation of caches.
 */
const CACHE_VERSION = 'newsflow-v5';
const STATIC_CACHE  = `${CACHE_VERSION}-static`;
const PAGES_CACHE   = `${CACHE_VERSION}-pages`;

// Bundle a tiny offline shell so navigations still resolve when
// the network is unreachable on first load.
const OFFLINE_URL = '/offline.html';
const PRECACHE = [
  '/',
  '/timelines',
  OFFLINE_URL,
  '/manifest.json',
];

// Anything matching these patterns bypasses the SW entirely —
// admin pages, JSON APIs, and any auth-sensitive surface should
// always hit the network with no SW caching at all.
const BYPASS_PATTERNS = [
  /^\/panel\//,
  /^\/api\//,
  /\/cron_/,
  /\/login\.php/,
  /\/logout\.php/,
  /telegram_summary\.php/,
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then((cache) => cache.addAll(PRECACHE).catch(() => {}))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((k) => !k.startsWith(CACHE_VERSION))
          .map((k) => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

function shouldBypass(url) {
  if (url.origin !== self.location.origin) return true;
  return BYPASS_PATTERNS.some((re) => re.test(url.pathname));
}

function isHTMLRequest(request) {
  if (request.mode === 'navigate') return true;
  const accept = request.headers.get('accept') || '';
  return accept.includes('text/html');
}

// Stale-while-revalidate for HTML navigations.
//
// Why not network-first? The old strategy waited on the network
// before returning *any* response, which meant "back" from an
// article triggered a fresh PHP request + server render before
// the homepage appeared. The user perceived this as a white
// flash and a horizontal-scroll lurch (reels snap-scroller
// resetting during the reflow).
//
// With SWR:
//   1. Cached homepage is returned instantly from disk — back-nav
//      feels native, no flash.
//   2. A background fetch refreshes the cache so the *next* visit
//      gets the newest breaking news.
//   3. First-time visitors (no cache) fall through to a fresh
//      network request, with the offline shell as last resort.
//
// The live sections (Telegram/Twitter/YouTube/tickers) keep
// updating over SSE + polling regardless, so the brief "stale"
// moment before the background fetch resolves isn't visible.
async function staleWhileRevalidate(request) {
  const cache = await caches.open(PAGES_CACHE);
  const cached = await cache.match(request);

  const networkPromise = fetch(request).then((fresh) => {
    if (fresh && fresh.ok) {
      cache.put(request, fresh.clone()).catch(() => {});
    }
    return fresh;
  }).catch(() => null);

  if (cached) {
    // Let the revalidation run in the background; we return
    // the cached copy immediately so navigation feels instant.
    return cached;
  }

  // First visit — wait on the network with a 3s safety timeout.
  const fresh = await Promise.race([
    networkPromise,
    new Promise((resolve) => setTimeout(() => resolve(null), 3000)),
  ]);
  if (fresh) return fresh;

  const offline = await caches.match(OFFLINE_URL);
  if (offline) return offline;
  return new Response('غير متصل بالإنترنت', {
    status: 503,
    headers: { 'Content-Type': 'text/plain; charset=utf-8' },
  });
}

// Cache-first for static assets. We still kick off a background
// fetch to keep the cached copy fresh for next time.
async function cacheFirst(request) {
  const cache = await caches.open(STATIC_CACHE);
  const cached = await cache.match(request);
  if (cached) {
    fetch(request).then((res) => {
      if (res && res.ok) cache.put(request, res.clone()).catch(() => {});
    }).catch(() => {});
    return cached;
  }
  try {
    const fresh = await fetch(request);
    if (fresh && fresh.ok) cache.put(request, fresh.clone()).catch(() => {});
    return fresh;
  } catch (err) {
    return new Response('', { status: 504 });
  }
}

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (shouldBypass(url)) return;

  if (isHTMLRequest(request)) {
    event.respondWith(staleWhileRevalidate(request));
    return;
  }

  // Same-origin static assets get the cache-first treatment.
  if (/\.(?:css|js|woff2?|ttf|otf|svg|png|jpg|jpeg|webp|gif|ico)$/i.test(url.pathname)) {
    event.respondWith(cacheFirst(request));
  }
});
