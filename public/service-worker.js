/**
 * Coffee Delivery — service worker.
 *
 * Whitelist-only static caching, on purpose: a request is only ever cached
 * if its path matches CACHEABLE_PREFIXES/CACHEABLE_EXACT below. Everything
 * else — every page navigation, /livewire/*, /checkout, /order/*, /kitchen/*,
 * /driver/*, /payments/*, /admin/*, and any non-GET request — is left
 * completely untouched and goes straight to the network. That means no
 * order, session, or account data can ever end up in this cache, and a
 * dropped connection on those routes fails exactly like it would with no
 * service worker installed, instead of resurrecting a stale order/cart.
 *
 * Bump CACHE_VERSION whenever a cached static asset (icon, favicon) changes
 * shape — Vite's own /build/ output is content-hashed already, so it never
 * needs a version bump to pick up new deploys.
 *
 * /manifest.webmanifest is deliberately NOT in the whitelist below: it is a
 * runtime response built from StoreSetting, not a static asset, and caching
 * it here would keep serving a store's old name/colors after an admin edit.
 */

const CACHE_VERSION = 'v2';
const CACHE_NAME = `coffee-delivery-static-${CACHE_VERSION}`;

const CACHEABLE_PREFIXES = ['/build/', '/icons/'];
const CACHEABLE_EXACT = ['/favicon.ico'];

function isCacheable(url) {
    if (url.origin !== self.location.origin) {
        return false;
    }

    if (CACHEABLE_EXACT.includes(url.pathname)) {
        return true;
    }

    return CACHEABLE_PREFIXES.some((prefix) => url.pathname.startsWith(prefix));
}

self.addEventListener('install', () => {
    // No precaching here on purpose: install never fetches anything, so a
    // single missing/failing URL can never block install/activation.
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((names) => Promise.all(
                names
                    .filter((name) => name.startsWith('coffee-delivery-static-') && name !== CACHE_NAME)
                    .map((name) => caches.delete(name)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (!isCacheable(url)) {
        return;
    }

    event.respondWith(
        caches.open(CACHE_NAME).then((cache) => cache.match(request).then((cached) => {
            if (cached) {
                return cached;
            }

            return fetch(request).then((response) => {
                if (response.ok) {
                    cache.put(request, response.clone());
                }

                return response;
            });
        })),
    );
});
