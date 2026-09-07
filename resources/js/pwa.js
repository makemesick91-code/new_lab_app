/**
 * PWA-FOUNDATION-1 — service worker registration.
 *
 * Progressive enhancement only. Registration failure must never block or alter
 * the application: DaengtisiaMS stays a fully functional server-rendered
 * Laravel/Blade app in any browser that has no service worker support, or where
 * registration is refused (private mode, disabled site data, insecure origin).
 *
 * This file installs no UI, prompts for nothing, and takes no part in
 * authentication or device authorization.
 */

export const SERVICE_WORKER_URL = '/sw.js';

/**
 * A service worker only registers on a secure context. localhost counts as one,
 * so a plain-HTTP local dev server still exercises the same path as production
 * HTTPS, and a plain-HTTP non-local origin is skipped instead of throwing.
 */
export function canRegisterServiceWorker(scope = globalThis) {
    return Boolean(
        scope
        && scope.navigator
        && 'serviceWorker' in scope.navigator
        && scope.isSecureContext
    );
}

export function registerServiceWorker(scope = globalThis) {
    if (!canRegisterServiceWorker(scope)) {
        return Promise.resolve(null);
    }

    return scope.navigator.serviceWorker
        .register(SERVICE_WORKER_URL, { scope: '/' })
        .catch((error) => {
            /*
             * Log the failure reason only — never the page URL, query string or
             * any response body, so nothing user- or patient-specific reaches
             * the console.
             */
            if (scope.console && typeof scope.console.warn === 'function') {
                scope.console.warn(
                    'DaengtisiaMS: service worker registration failed.',
                    error && error.name ? error.name : 'UnknownError'
                );
            }

            return null;
        });
}

export function bootPwa(scope = globalThis) {
    if (!canRegisterServiceWorker(scope)) {
        return;
    }

    /* Registration competes with nothing on the critical rendering path. */
    scope.addEventListener('load', () => {
        registerServiceWorker(scope);
    });
}
