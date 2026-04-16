// Service Worker v{{ $version }}
const CACHE_NAME = 'vela-cache-v{{ $version }}';
const OFFLINE_URL = '/offline';

const PRECACHE_URLS = [
    OFFLINE_URL,
@if($precacheUrls)
@foreach(explode(',', $precacheUrls) as $url)
    '{{ trim($url) }}',
@endforeach
@endif
];

// Install — pre-cache offline page and configured URLs
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll(PRECACHE_URLS);
        }).then(() => self.skipWaiting())
    );
});

// Activate — clean old caches
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => {
            return Promise.all(
                keys.filter(key => key.startsWith('vela-cache-') && key !== CACHE_NAME)
                    .map(key => caches.delete(key))
            );
        }).then(() => self.clients.claim())
    );
});

// Fetch — network-first for HTML, cache-first for static assets
self.addEventListener('fetch', event => {
    const request = event.request;

    // Only handle GET requests with http(s) scheme
    if (request.method !== 'GET') return;
    if (!request.url.startsWith('http')) return;

    // Skip admin, auth, and API routes
    const url = new URL(request.url);
    if (url.pathname.startsWith('/admin') || url.pathname.startsWith('/vela') || url.pathname.startsWith('/api')) return;

    // Don't cache the manifest
    if (url.pathname.endsWith('/manifest.json')) return;

    const isHtml = request.headers.get('accept')?.includes('text/html');

    if (isHtml) {
        // Network-first for HTML (SW is fallback to VELA_CACHE static files)
        event.respondWith(
            fetch(request)
                .then(response => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then(cache => cache.put(request, clone));
                    }
                    return response;
                })
                .catch(() => {
                    return caches.match(request).then(cached => cached || caches.match(OFFLINE_URL));
                })
        );
        return;
    }

    // Cache-first for static assets (CSS, JS, fonts, images)
    if (request.url.match(/\.(css|js|woff2?|ttf|eot|png|jpe?g|gif|webp|svg|ico)(\?|$)/)) {
        event.respondWith(
            caches.match(request).then(cached => {
                if (cached) return cached;
                return fetch(request).then(response => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then(cache => cache.put(request, clone));
                    }
                    return response;
                });
            })
        );
        return;
    }
});
