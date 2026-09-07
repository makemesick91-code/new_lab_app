<?php

/**
 * PWA-FOUNDATION-1 — the security contract of the service worker.
 *
 * The claim these tests defend: Cache Storage is deny-by-default, and no
 * application route can ever be written to it. Rather than trusting a list of
 * hand-written paths, the sweep below walks the COMPLETE route table from
 * Laravel's router and asserts every registered URI is refused.
 *
 * The allowlist is read out of the shipped public/sw.js, so the worker remains
 * the single source of truth. (tests/js/pwa-service-worker.test.mjs executes the
 * worker's own matching function against the same cases; only the trivial
 * prefix/exact comparison is expressed twice.)
 */

use Illuminate\Support\Facades\Route;

it('ships a service worker at the document root', function () {
    // Root scope requires the worker itself to be served from "/", which is why
    // it is a static public/ file rather than a routed response.
    expect(is_file(public_path('sw.js')))->toBeTrue();
});

it('exposes a machine-readable cache allowlist', function () {
    $allowlist = pwaCacheAllowlist();

    expect($allowlist)->toHaveKeys(['prefixes', 'exact'])
        ->and($allowlist['prefixes'])->toBe(['/build/', '/pwa/', '/assets/brand/'])
        ->and($allowlist['exact'])->toBe(['/offline.html', '/manifest.webmanifest', '/favicon.ico']);
});

it('never classifies any registered application route as cacheable', function () {
    $offenders = [];

    foreach (Route::getRoutes() as $route) {
        $uri = '/'.ltrim($route->uri(), '/');

        // Both the declared URI and a realistically-bound instance of it.
        $candidates = array_unique([
            $uri,
            (string) preg_replace('/\{[^}]+\}/', '1', $uri),
        ]);

        foreach ($candidates as $candidate) {
            if (pwaIsCacheable($candidate)) {
                $offenders[] = implode('|', $route->methods()).' '.$candidate;
            }
        }
    }

    expect($offenders)->toBe([], 'these routes would be written to Cache Storage: '.implode(', ', $offenders));
})->group('security');

it('refuses the authentication, clinical, billing and export surfaces by name', function () {
    $sensitive = [
        '/login', '/logout', '/register', '/dashboard', '/profile',
        '/rme/visits', '/rme/visits/1/medical-record', '/rme/visits/1/odontogram',
        '/rme/visits/1/print', '/rme/visits/1/pdf', '/rme/patient-queue',
        '/rme/cashier', '/rme/cashier/receivables',
        '/rme/reports/patients', '/rme/reports/payments', '/rme/patients/audit/export',
        '/rme/satusehat/submissions',
        '/settings/patients', '/inventory/products', '/inventory/reports',
        '/lab/v2-orders', '/lab/analytics/operational-kpi/export',
        '/dev-console', '/foundation/monitoring',
        '/health/live', '/health/ready', '/up',
    ];

    foreach ($sensitive as $path) {
        expect(pwaIsCacheable($path))->toBeFalse("{$path} must never be cached");
    }
})->group('security');

it('allows only the public static shell', function () {
    foreach (['/offline.html', '/manifest.webmanifest', '/favicon.ico',
        '/build/assets/app-abc123.js', '/pwa/icon-512.png',
        '/assets/brand/daengtisia-logo.png'] as $path) {
        expect(pwaIsCacheable($path))->toBeTrue("{$path} should be cacheable");
    }
});

it('keeps exactly one write path into Cache Storage', function () {
    $code = pwaServiceWorkerCode();

    expect(substr_count($code, 'cache.put('))->toBe(1);
    expect(strpos($code, 'async function putIfAllowed'))->toBeLessThan(strpos($code, 'cache.put('));
});

it('never caches a navigation response', function () {
    $code = pwaServiceWorkerCode();

    // The navigation branch answers from the network, or from the static offline
    // shell — it must not reach the cache-writing helper at all.
    expect($code)->toContain('async function networkFirstNavigation');

    $start = strpos($code, 'async function networkFirstNavigation');
    $body = substr($code, $start, (int) strpos($code, 'self.addEventListener') - $start);

    expect($body)->not->toContain('putIfAllowed');
})->group('security');

it('leaves cross-origin and non-GET traffic entirely alone', function () {
    $code = pwaServiceWorkerCode();

    expect($code)->toContain("request.method !== 'GET'")
        ->and($code)->toContain('url.origin !== self.location.origin');
});

it('versions the cache and removes only its own stale entries', function () {
    $code = pwaServiceWorkerCode();

    expect($code)->toContain("const CACHE_VERSION = 'v1';")
        ->and($code)->toContain("const CACHE_PREFIX = 'daengtisiams-';")
        ->and($code)->toContain('key.indexOf(CACHE_PREFIX) === 0 && key !== STATIC_CACHE');
});

it('never swaps assets under a live page', function () {
    expect(pwaServiceWorkerCode())->not->toContain('skipWaiting');
});

it('uses no client-side persistence beyond the static cache', function () {
    $code = pwaServiceWorkerCode();

    foreach (['indexedDB', 'localStorage', 'sessionStorage'] as $api) {
        expect($code)->not->toContain($api);
    }
})->group('security');

/** The shipped worker source. */
function pwaServiceWorkerSource(): string
{
    return (string) file_get_contents(public_path('sw.js'));
}

/**
 * Source invariants are claims about code, not prose: the worker documents the
 * very APIs it must not call, so comments are stripped before scanning.
 */
function pwaServiceWorkerCode(): string
{
    $source = (string) preg_replace('#/\*[\s\S]*?\*/#', '', pwaServiceWorkerSource());

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $source);
}

/** The allowlist block the worker itself uses. */
function pwaCacheAllowlist(): array
{
    $source = pwaServiceWorkerSource();

    $matched = preg_match(
        '/PWA-CACHE-ALLOWLIST-BEGIN \*\/\s*const CACHE_ALLOWLIST = (\{.*?\});\s*\/\* PWA-CACHE-ALLOWLIST-END/s',
        $source,
        $matches
    );

    expect($matched)->toBe(1, 'public/sw.js no longer exposes a readable cache allowlist');

    return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
}

/** Mirrors the worker's isCacheable(), driven by the worker's own allowlist. */
function pwaIsCacheable(string $pathname): bool
{
    if ($pathname === '') {
        return false;
    }

    $allowlist = pwaCacheAllowlist();

    if (in_array($pathname, $allowlist['exact'], true)) {
        return true;
    }

    foreach ($allowlist['prefixes'] as $prefix) {
        if (str_starts_with($pathname, $prefix)) {
            return true;
        }
    }

    return false;
}
