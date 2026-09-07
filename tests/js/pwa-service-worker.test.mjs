/**
 * PWA-FOUNDATION-1 — service worker cache-policy tests.
 *
 * These evaluate the REAL public/sw.js in a sandboxed context with the service
 * worker globals stubbed, then exercise the actual policy functions through the
 * documented `self.__PWA_CACHE_POLICY__` seam. Nothing here re-implements the
 * policy, so the assertions cannot drift away from what ships.
 *
 * The security claim under test: Cache Storage is deny-by-default, and no
 * authenticated, clinical, billing, reporting, export or session URL can ever
 * be classified as cacheable.
 *
 *     npm run test:js
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import vm from 'node:vm';
import { describe, it } from 'node:test';

import { canRegisterServiceWorker, registerServiceWorker, SERVICE_WORKER_URL } from '../../resources/js/pwa.js';

const REPO_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const SW_PATH = resolve(REPO_ROOT, 'public/sw.js');
const SW_SOURCE = readFileSync(SW_PATH, 'utf8');

/**
 * The source invariants below are claims about CODE, not about prose. The
 * worker's own header documents the very APIs it must not call ("there is
 * deliberately no skipWaiting()", "never persist to ... localStorage"), so a
 * raw substring scan would fail on its own documentation. Strip comments first.
 * public/sw.js contains no string literal holding `//` or `/*`, so this simple
 * stripper is exact for this file.
 */
const SW_CODE = SW_SOURCE
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/^[ \t]*\/\/.*$/gm, '');

/** Evaluate the shipped worker with the service worker globals stubbed out. */
function loadServiceWorker() {
    const listeners = {};

    const self = {
        location: { origin: 'https://daengtisia.online' },
        clients: { claim: async () => {} },
        addEventListener(type, handler) {
            listeners[type] = handler;
        },
    };

    const context = vm.createContext({
        self,
        caches: {
            open: async () => ({ match: async () => undefined, put: async () => {} }),
            keys: async () => [],
            delete: async () => true,
        },
        fetch: async () => {
            throw new Error('network disabled in unit test');
        },
        Request,
        Response,
        URL,
        console,
    });

    vm.runInContext(SW_SOURCE, context, { filename: SW_PATH });

    return { policy: self.__PWA_CACHE_POLICY__, listeners };
}

const { policy, listeners } = loadServiceWorker();

/**
 * Real, currently-registered application surfaces. Every one of them must be
 * refused by the cache policy. (tests/Feature/Pwa asserts the same property
 * against the complete route table from Laravel's router.)
 */
const SENSITIVE_PATHS = [
    '/',
    '/login',
    '/logout',
    '/register',
    '/dashboard',
    '/profile',
    '/notifications',
    '/rme/visits',
    '/rme/visits/1',
    '/rme/visits/1/medical-record',
    '/rme/visits/1/odontogram',
    '/rme/visits/1/prescription',
    '/rme/visits/1/print',
    '/rme/visits/1/pdf',
    '/rme/patient-queue',
    '/rme/cashier',
    '/rme/cashier/1',
    '/rme/cashier/receivables',
    '/rme/reports/patients',
    '/rme/reports/payments',
    '/rme/reports/payments/print',
    '/rme/reports/doctor-performance',
    '/rme/patients/audit',
    '/rme/patients/audit/export',
    '/rme/satusehat/submissions',
    '/settings/patients',
    '/settings/patients/import',
    '/inventory/dashboard',
    '/inventory/products',
    '/inventory/reports',
    '/lab/v2-orders',
    '/lab/operational-dashboard',
    '/lab/analytics/operational-kpi',
    '/lab/analytics/operational-kpi/export',
    '/dev-console',
    '/foundation/monitoring',
    '/health/live',
    '/health/ready',
    '/health/lb',
    '/up',
    '/api/patients/search',
    '/sanctum/csrf-cookie',
];

describe('PWA service worker — cache allowlist', () => {
    it('exposes the documented policy seam', () => {
        assert.ok(policy, 'self.__PWA_CACHE_POLICY__ must be exposed');
        assert.equal(typeof policy.isCacheable, 'function');
        assert.equal(policy.STATIC_CACHE, 'daengtisiams-static-v1');
        assert.equal(policy.OFFLINE_URL, '/offline.html');
    });

    it('registers exactly the install, activate and fetch handlers', () => {
        assert.deepEqual(Object.keys(listeners).sort(), ['activate', 'fetch', 'install']);
    });

    it('allows only the public static shell', () => {
        const allowed = [
            '/offline.html',
            '/manifest.webmanifest',
            '/favicon.ico',
            '/build/assets/app-abc123.css',
            '/build/assets/app-abc123.js',
            '/pwa/icon-192.png',
            '/pwa/icon-maskable-512.png',
            '/assets/brand/daengtisia-logo.png',
        ];

        for (const path of allowed) {
            assert.equal(policy.isCacheable(path), true, `${path} should be cacheable`);
        }
    });

    it('refuses every authenticated, clinical, billing and export surface', () => {
        for (const path of SENSITIVE_PATHS) {
            assert.equal(policy.isCacheable(path), false, `${path} must never be cached`);
        }
    });

    it('refuses paths that only look like allowlisted ones', () => {
        const lookalikes = [
            '/rme/build/report',
            '/lab/pwa/summary',
            '/inventory/assets/brand/list',
            '/manifest.webmanifest.php',
            '/offline.html/../rme/visits',
            '/buildings',
            '/pwaadmin',
            '',
        ];

        for (const path of lookalikes) {
            assert.equal(policy.isCacheable(path), false, `${path} must never be cached`);
        }
    });

    it('treats only content-hashed build output as immutable', () => {
        assert.equal(policy.isImmutable('/build/assets/app-abc123.js'), true);
        assert.equal(policy.isImmutable('/pwa/icon-192.png'), false);
        assert.equal(policy.isImmutable('/rme/visits'), false);
    });

    it('stores only same-origin 200 responses', () => {
        assert.equal(policy.isStorableResponse({ status: 200, type: 'basic' }), true);
        assert.equal(policy.isStorableResponse({ status: 200, type: 'opaque' }), false);
        assert.equal(policy.isStorableResponse({ status: 302, type: 'basic' }), false);
        assert.equal(policy.isStorableResponse({ status: 401, type: 'basic' }), false);
        assert.equal(policy.isStorableResponse({ status: 500, type: 'basic' }), false);
        assert.equal(policy.isStorableResponse(null), false);
    });

    it('precaches only allowlisted URLs', () => {
        for (const url of policy.PRECACHE_URLS) {
            assert.equal(policy.isCacheable(url), true, `${url} is precached but not allowlisted`);
        }
    });

    it('never precaches an application page', () => {
        for (const path of SENSITIVE_PATHS) {
            assert.equal(policy.PRECACHE_URLS.includes(path), false);
        }
    });
});

describe('PWA service worker — source invariants', () => {
    it('has exactly one write path into Cache Storage', () => {
        const writes = SW_CODE.split('cache.put(').length - 1;
        assert.equal(writes, 1, 'cache.put() must appear only inside putIfAllowed()');

        const helperAt = SW_CODE.indexOf('async function putIfAllowed');
        const writeAt = SW_CODE.indexOf('cache.put(');
        assert.ok(helperAt !== -1 && writeAt > helperAt, 'the write must live inside putIfAllowed()');
    });

    it('has no blanket cache lookup for arbitrary requests', () => {
        assert.equal(SW_CODE.includes('caches.match(event.request)'), false);
        assert.equal(SW_CODE.includes('cache.addAll('), false);
        assert.equal(SW_CODE.includes('caches.addAll('), false);
    });

    it('never activates over a live page', () => {
        assert.equal(SW_CODE.includes('skipWaiting'), false);
    });

    it('purges only caches this application owns', () => {
        assert.ok(SW_CODE.includes("key.indexOf(CACHE_PREFIX) === 0 && key !== STATIC_CACHE"));
    });

    it('uses no client-side persistence beyond the static cache', () => {
        for (const api of ['indexedDB', 'localStorage', 'sessionStorage']) {
            assert.equal(SW_CODE.includes(api), false, `${api} must not be used by the worker`);
        }
    });
});

describe('PWA registration', () => {
    const navigatorWithSw = { serviceWorker: { register: async () => ({}) } };

    it('registers the root-scoped worker on a secure context', async () => {
        const calls = [];
        const scope = {
            isSecureContext: true,
            navigator: {
                serviceWorker: {
                    register: async (url, options) => {
                        calls.push([url, options]);

                        return {};
                    },
                },
            },
        };

        await registerServiceWorker(scope);

        assert.deepEqual(calls, [[SERVICE_WORKER_URL, { scope: '/' }]]);
    });

    it('skips registration on an insecure context', async () => {
        const scope = { isSecureContext: false, navigator: navigatorWithSw };

        assert.equal(canRegisterServiceWorker(scope), false);
        assert.equal(await registerServiceWorker(scope), null);
    });

    it('skips registration when the browser has no service worker support', () => {
        assert.equal(canRegisterServiceWorker({ isSecureContext: true, navigator: {} }), false);
    });

    it('never rejects when registration fails', async () => {
        const warnings = [];
        const scope = {
            isSecureContext: true,
            console: { warn: (...args) => warnings.push(args) },
            navigator: {
                serviceWorker: {
                    register: async () => {
                        throw new Error('SecurityError');
                    },
                },
            },
        };

        assert.equal(await registerServiceWorker(scope), null);
        assert.equal(warnings.length, 1);
    });
});
