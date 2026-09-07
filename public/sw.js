/**
 * DaengtisiaMS — PWA-FOUNDATION-1 service worker.
 *
 * SCOPE OF THIS FILE
 * ------------------
 * This worker exists to make DaengtisiaMS installable on Android and to give it
 * a safe offline shell. It is an ADDITIVE layer over the existing Laravel/Blade
 * application. It carries NO business logic, performs NO clinical write, and is
 * NOT part of any authentication or device-authorization decision.
 *
 * THE CACHE RULE (non-negotiable)
 * -------------------------------
 * Cache Storage is DENY-BY-DEFAULT. A response may be written ONLY when its
 * same-origin pathname matches CACHE_ALLOWLIST below — a closed set of public,
 * non-user-specific static assets. Everything else (authenticated HTML, RME,
 * odontogram, consent, invoices, payments, reports, exports, APIs, login,
 * logout, session and CSRF endpoints) is passed straight through to the network
 * and never enters any cache.
 *
 * Clinical data is ONLINE-ONLY. This worker must never make patient data
 * available offline, never queue a clinical write for later sync, and never
 * persist anything to Cache Storage, IndexedDB or localStorage.
 *
 * UPDATE LIFECYCLE
 * ----------------
 * There is deliberately no skipWaiting(). A new worker waits until every page
 * controlled by the old one is gone, so a release can never swap assets under
 * an operator who is mid-form. This is safe because navigations are always
 * network-first: fresh HTML references the new content-hashed /build/ URLs,
 * which the old worker simply fetches and caches under the same allowlist.
 * Stale caches from previous versions are removed on activate.
 *
 * TEST SURFACE
 * ------------
 * tests/js/pwa-service-worker.test.mjs evaluates THIS file (not a copy) and
 * exercises the real policy functions through self.__PWA_CACHE_POLICY__.
 * tests/Feature/Pwa/* reads the allowlist block below and asserts that no
 * registered application route can ever be classified as cacheable.
 */

const CACHE_PREFIX = 'daengtisiams-';
const CACHE_VERSION = 'v1';
const STATIC_CACHE = CACHE_PREFIX + 'static-' + CACHE_VERSION;

const OFFLINE_URL = '/offline.html';

/*
 * The single source of truth for what may be cached. Both the runtime policy
 * below and the PHP security tests read this exact block. Adding an entry here
 * is a security decision: it must stay public, non-user-specific and free of
 * patient, clinical, billing or session data.
 */
/* PWA-CACHE-ALLOWLIST-BEGIN */
const CACHE_ALLOWLIST = {
    "prefixes": [
        "/build/",
        "/pwa/",
        "/assets/brand/"
    ],
    "exact": [
        "/offline.html",
        "/manifest.webmanifest",
        "/favicon.ico"
    ]
};
/* PWA-CACHE-ALLOWLIST-END */

/* Content-hashed build output: the URL changes whenever the bytes change. */
const IMMUTABLE_PREFIXES = ['/build/'];

const PRECACHE_URLS = [
    OFFLINE_URL,
    '/manifest.webmanifest',
    '/pwa/icon-192.png',
    '/pwa/icon-512.png',
    '/pwa/icon-maskable-192.png',
    '/pwa/icon-maskable-512.png',
    '/pwa/apple-touch-icon-180.png'
];

function isCacheable(pathname) {
    if (typeof pathname !== 'string' || pathname.length === 0) {
        return false;
    }

    if (CACHE_ALLOWLIST.exact.indexOf(pathname) !== -1) {
        return true;
    }

    for (let i = 0; i < CACHE_ALLOWLIST.prefixes.length; i += 1) {
        if (pathname.indexOf(CACHE_ALLOWLIST.prefixes[i]) === 0) {
            return true;
        }
    }

    return false;
}

function isImmutable(pathname) {
    for (let i = 0; i < IMMUTABLE_PREFIXES.length; i += 1) {
        if (typeof pathname === 'string' && pathname.indexOf(IMMUTABLE_PREFIXES[i]) === 0) {
            return true;
        }
    }

    return false;
}

function isStorableResponse(response) {
    return Boolean(response)
        && response.status === 200
        && response.type === 'basic';
}

/**
 * THE ONLY WRITE PATH INTO CACHE STORAGE.
 *
 * Every caller re-enters the allowlist check here, so a future edit to a
 * calling branch cannot accidentally widen what is stored.
 */
async function putIfAllowed(request, response) {
    if (!request || request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (!isCacheable(url.pathname)) {
        return;
    }

    if (!isStorableResponse(response)) {
        return;
    }

    const cache = await caches.open(STATIC_CACHE);
    await cache.put(request, response);
}

async function precache() {
    await Promise.all(PRECACHE_URLS.map(async (url) => {
        try {
            const request = new Request(url, { cache: 'reload', credentials: 'omit' });
            const response = await fetch(request);
            await putIfAllowed(request, response.clone());
        } catch (error) {
            /* A missing optional asset must never block installation. */
        }
    }));
}

async function purgeStaleCaches() {
    const keys = await caches.keys();

    await Promise.all(keys.map((key) => {
        /* Only ever touch caches this application owns. */
        if (key.indexOf(CACHE_PREFIX) === 0 && key !== STATIC_CACHE) {
            return caches.delete(key);
        }

        return Promise.resolve(false);
    }));
}

async function revalidate(request) {
    try {
        const response = await fetch(request);
        await putIfAllowed(request, response.clone());
    } catch (error) {
        /* Offline revalidation failure is not an error for the page. */
    }
}

async function cacheFirst(request) {
    const cache = await caches.open(STATIC_CACHE);
    const cached = await cache.match(request);

    if (cached) {
        if (!isImmutable(new URL(request.url).pathname)) {
            revalidate(request);
        }

        return cached;
    }

    const response = await fetch(request);
    await putIfAllowed(request, response.clone());

    return response;
}

/**
 * Navigations are ALWAYS network-first and the response is NEVER cached — that
 * is what keeps authenticated, branch-scoped and patient-scoped HTML out of
 * Cache Storage. Only when the network genuinely fails does the operator get
 * the static offline shell, which contains no application data.
 */
async function networkFirstNavigation(request) {
    try {
        return await fetch(request);
    } catch (error) {
        const cache = await caches.open(STATIC_CACHE);
        const offline = await cache.match(OFFLINE_URL);

        if (offline) {
            return offline;
        }

        return new Response(
            'Koneksi internet tidak tersedia.',
            {
                status: 503,
                headers: { 'Content-Type': 'text/plain; charset=utf-8' }
            }
        );
    }
}

self.addEventListener('install', (event) => {
    event.waitUntil(precache());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        await purgeStaleCaches();
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    let url;

    try {
        url = new URL(request.url);
    } catch (error) {
        return;
    }

    /* Cross-origin (fonts, anything else) is left entirely to the browser. */
    if (url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(networkFirstNavigation(request));

        return;
    }

    if (isCacheable(url.pathname)) {
        event.respondWith(cacheFirst(request));

        return;
    }

    /*
     * Everything else — authenticated pages, APIs, RME, odontogram, consent,
     * invoices, payments, reports, exports, session and CSRF endpoints — is not
     * handled here at all. No respondWith, no cache read, no cache write.
     */
});

/*
 * Documented test seam. Assigning to `self` is inert in a browser; the Node
 * unit tests evaluate this file with a stubbed `self` and exercise the real
 * policy functions rather than a re-implementation of them.
 */
self.__PWA_CACHE_POLICY__ = {
    CACHE_PREFIX: CACHE_PREFIX,
    CACHE_VERSION: CACHE_VERSION,
    STATIC_CACHE: STATIC_CACHE,
    OFFLINE_URL: OFFLINE_URL,
    CACHE_ALLOWLIST: CACHE_ALLOWLIST,
    IMMUTABLE_PREFIXES: IMMUTABLE_PREFIXES,
    PRECACHE_URLS: PRECACHE_URLS,
    isCacheable: isCacheable,
    isImmutable: isImmutable,
    isStorableResponse: isStorableResponse
};
