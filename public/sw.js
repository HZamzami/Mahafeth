const CACHE = 'mahafeth-v2';
const OFFLINE_URL = '/offline.html';

// Hashed build assets, fonts, and icons are immutable: serve them from
// the cache without touching the network on repeat loads.
const STATIC_PATH = /^\/(build\/|fonts\/|icons\/)/;

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll([OFFLINE_URL, '/icons/icon-192.png'])));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        Promise.all([
            caches.keys().then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key)))),
            // Let the browser start navigation requests in parallel with
            // the service-worker boot instead of serializing behind it.
            self.registration.navigationPreload?.enable(),
        ]),
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.mode === 'navigate') {
        event.respondWith(
            (async () => {
                try {
                    return (await event.preloadResponse) || (await fetch(request));
                } catch {
                    return caches.match(OFFLINE_URL);
                }
            })(),
        );

        return;
    }

    const url = new URL(request.url);

    if (request.method === 'GET' && url.origin === self.location.origin && STATIC_PATH.test(url.pathname)) {
        event.respondWith(
            caches.match(request).then(
                (hit) =>
                    hit ||
                    fetch(request).then((response) => {
                        if (response.ok) {
                            const copy = response.clone();
                            caches.open(CACHE).then((cache) => cache.put(request, copy));
                        }

                        return response;
                    }),
            ),
        );
    }
});

self.addEventListener('push', (event) => {
    if (!event.data) {
        return;
    }

    const payload = event.data.json();

    event.waitUntil(
        self.registration.showNotification(payload.title, {
            body: payload.body,
            icon: payload.icon || '/icons/icon-192.png',
            badge: payload.badge || '/icons/icon-192.png',
            tag: payload.tag,
            dir: 'auto',
            data: payload.data || {},
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = event.notification.data.url || '/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
            const open = windowClients.find((client) => 'focus' in client);

            return open ? open.navigate(url).then((client) => client.focus()) : clients.openWindow(url);
        }),
    );
});
