/* Luxe Salon OS — POS service worker (scope /pos/). App shell + catalog cache for offline selling. */
const VERSION = 'luxe-pos-{{ $version }}';
const SHELL = ['/pos', '/pos/catalog.json', '/pos/manifest.webmanifest', '/pos/icon.svg'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(VERSION)
            .then((cache) => Promise.all(SHELL.map((url) => cache.add(new Request(url, { credentials: 'same-origin' })).catch(() => null))))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k.startsWith('luxe-pos-') && k !== VERSION).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

const networkFirst = async (request, fallbackUrl) => {
    const cache = await caches.open(VERSION);
    try {
        const response = await fetch(request);
        if (response.ok && response.type === 'basic' && !response.redirected) {
            cache.put(fallbackUrl || request, response.clone());
        }
        return response;
    } catch (e) {
        return (await cache.match(fallbackUrl || request)) || (await cache.match('/pos')) || Response.error();
    }
};

const staleWhileRevalidate = async (request) => {
    const cache = await caches.open(VERSION);
    const cached = await cache.match(request);
    const fresh = fetch(request).then((response) => {
        if (response.ok) cache.put(request, response.clone());
        return response;
    }).catch(() => cached);
    return cached || fresh;
};

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return; // sales & Livewire updates always hit the network (queued by pos-offline.js)
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    if (request.mode === 'navigate' && url.pathname.startsWith('/pos')) {
        event.respondWith(networkFirst(request, url.pathname === '/pos' || url.pathname === '/pos/' ? '/pos' : undefined));
        return;
    }
    if (url.pathname === '/pos/catalog.json') {
        event.respondWith(networkFirst(request, '/pos/catalog.json'));
        return;
    }
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/css/filament') || url.pathname.startsWith('/js/filament')
        || url.pathname.startsWith('/fonts/') || url.pathname.startsWith('/livewire')) {
        event.respondWith(staleWhileRevalidate(request));
    }
});
