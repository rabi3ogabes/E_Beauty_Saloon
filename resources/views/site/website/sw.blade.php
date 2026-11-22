/* {{ tenant()->name }} — service worker (app shell + offline page). v{{ $version }} */
const CACHE = 'luxe-shell-{{ $version }}';
const OFFLINE = '/offline';
const SHELL = [OFFLINE, '/manifest.webmanifest', '/pwa/icon-192.png'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith('luxe-shell-') && k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== location.origin) return;
    if (/^\/(dashboard|pos|admin|livewire|studio\/(jobs|media))/.test(url.pathname)) return;

    // Navigations: network first, offline page as fallback.
    if (req.mode === 'navigate') {
        event.respondWith(fetch(req).catch(() => caches.match(OFFLINE)));
        return;
    }
    // Built assets: cache first (they are content-hashed).
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(caches.match(req).then((hit) => hit || fetch(req).then((res) => {
            const copy = res.clone();
            caches.open(CACHE).then((cache) => cache.put(req, copy));
            return res;
        })));
    }
});

self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { data = { title: event.data && event.data.text() }; }
    event.waitUntil(self.registration.showNotification(data.title || @js(tenant()->name), {
        body: data.body || '', icon: data.icon || '/pwa/icon-192.png', badge: '/pwa/icon-192.png', data: { url: data.url || '/' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(self.clients.openWindow(event.notification.data.url || '/'));
});
