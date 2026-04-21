// Service Worker v{{ $version }}
const CACHE_NAME = 'vela-cache-v{{ $version }}';
// Scope base derived from registration.scope at runtime so the SW
// survives subdirectory installs. e.g. http://host/site/public/
const SCOPE = new URL(self.registration.scope).pathname.replace(/\/$/, '');
const OFFLINE_URL = SCOPE + '/offline';

const PRECACHE_URLS = [
    OFFLINE_URL,
@if($precacheUrls)
@foreach(explode(',', $precacheUrls) as $url)
    SCOPE + '{{ '/' . ltrim(trim($url), '/') }}',
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

    // Skip admin, auth, and API routes. Match against the scope-relative
    // path so subdirectory installs work.
    const url = new URL(request.url);
    const path = url.pathname.startsWith(SCOPE) ? url.pathname.slice(SCOPE.length) : url.pathname;
    if (path.startsWith('/admin') || path.startsWith('/vela') || path.startsWith('/api')) return;

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
