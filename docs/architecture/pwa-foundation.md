# PWA Foundation — DaengtisiaMS

**Sprint:** PWA-FOUNDATION-1
**Status:** durable architecture lock. Read this before changing anything under
`public/sw.js`, `public/manifest.webmanifest`, `public/offline.html`,
`public/pwa/`, `resources/js/pwa.js` or `resources/views/layouts/partials/pwa-head.blade.php`.

> **PWA-FOUNDATION-1 DOES NOT ENABLE DOCTOR DEVICE ENFORCEMENT.**
> It is security-neutral with respect to authentication. It changes no login
> behaviour, no browser-denial rule, no Android API, no device signature
> verification, no `DoctorDeviceAuthorization` semantics, no device approval or
> enforcement flag, no branch resolution, no room authorization and no RBAC.
> WebAuthn, passkeys and device-bound web sessions are a separate, later phase.
> The Android APK is not retired and is not replaced by this work.

---

## 1. What this is

DaengtisiaMS is, and remains, a server-rendered **Laravel + Blade + Tailwind +
Alpine** application built by Vite. The PWA layer is **additive**: it makes the
existing web application installable on Android and gives it a safe offline
shell. No business logic moved to JavaScript. No page became a client-side app.
Removing the PWA files would leave a fully functional application behind.

```
DaengtisiaMS Web (unchanged)
      │
      ├── normal browser  ──────────────────────► works exactly as before
      │
      └── installed PWA
              ├── standalone display
              ├── static shell cached (build output, icons, brand logo)
              └── offline fallback with no clinical data
```

## 2. Where things live

| Artifact | Path | Served by |
| --- | --- | --- |
| Web app manifest | `public/manifest.webmanifest` | nginx (static) |
| Service worker | `public/sw.js` | nginx (static, root scope) |
| Offline shell | `public/offline.html` | nginx (static) |
| Icons | `public/pwa/icon-{192,512}.png`, `icon-maskable-{192,512}.png`, `apple-touch-icon-180.png` | nginx (static) |
| Registration | `resources/js/pwa.js`, imported by `resources/js/app.js` | Vite build |
| Head metadata | `resources/views/layouts/partials/pwa-head.blade.php` | Blade (`layouts/app`, `layouts/guest`) |
| nginx MIME record | `deploy/nginx/pwa-manifest-mime.conf` | applied once per server |

These are **static files on purpose**. A Laravel route would sit inside the
`web` middleware group — which globally appends `EnsureRmeOnlineContext` — so a
manifest or worker request could be answered with a redirect, and would carry a
session cookie. A service worker must also be served from `/` to obtain root
scope. Static files satisfy both constraints without exception handling.

## 3. Icons

Derived from the official `public/assets/brand/daengtisia-logo.png` (3000×2000
RGBA, dark artwork on transparency), cropped to its alpha bounding box and
centred on a solid `#FFFFFF` ground:

- `purpose="any"` — logo at 88 % of the canvas width.
- `purpose="maskable"` — logo at 70 % of the canvas width, which keeps its
  diagonal (378 px at 512) inside the 80 % safe-zone circle (410 px at 512), so
  a circular or squircle launcher mask never clips the wordmark.

Branding is not altered. Regenerating icons after a brand change must reuse
these ratios and re-verify the maskable safe zone.

## 4. Colours

From the UIX-1 design system tokens (`tailwind.config.js`) — never invented:

- `theme_color` = `#2563EB` (`brand.DEFAULT`, the primary/active blue)
- `background_color` = `#F7F9FC` (`canvas`, the off-white ground)

Gold (`#C8A45C`) is an accent token and must never become the application theme.

## 5. The cache rule (the security contract)

**Cache Storage is deny-by-default.** `public/sw.js` carries a single
machine-readable allowlist between the `PWA-CACHE-ALLOWLIST-BEGIN` /
`PWA-CACHE-ALLOWLIST-END` markers. A response may be written **only** when its
same-origin pathname matches it:

| Kind | Entries |
| --- | --- |
| prefixes | `/build/`, `/pwa/`, `/assets/brand/` |
| exact | `/offline.html`, `/manifest.webmanifest`, `/favicon.ico` |

Everything else is passed to the network untouched: authenticated HTML, RME,
medical records, odontogram, consent, prescriptions, invoices, payments,
receivables, reports, exports, dashboards, APIs, login, logout, session and
CSRF endpoints, and the health endpoints.

Enforcement, in order:

1. `isCacheable(pathname)` — the allowlist test.
2. `putIfAllowed(request, response)` — **the only** call site of `cache.put()`
   in the file. It re-checks origin, method, the allowlist and the response
   before writing, so widening a calling branch cannot widen what is stored.
3. Navigation responses are **never** passed to `putIfAllowed`.

## 6. Network strategy

| Request | Strategy |
| --- | --- |
| Non-GET | not handled — browser default |
| Cross-origin | not handled — browser default |
| Navigation (`mode === 'navigate'`) | **network-first**; on network failure only, serve the static `/offline.html`. The response is never cached. |
| `/build/*` (content-hashed) | cache-first, no revalidation — the URL changes when the bytes change |
| Other allowlisted static | cache-first with background revalidation |
| Everything else | not handled — no cache read, no cache write |

There is deliberately **no** blanket `caches.match(event.request)` and no
`cache.addAll()`.

## 7. Offline behaviour

`public/offline.html` is static, self-contained (inline CSS, no build
dependency, so it renders when nothing else is cached) and contains **no**
patient data, no last-opened RME, no cached figures and no pending-form storage.

**Clinical operations are online-only.** The PWA layer must never:

- make patient data available offline;
- queue a clinical write for later synchronisation;
- persist clinical data to Cache Storage, IndexedDB or `localStorage`.

Offline clinical capability would be a separate, explicitly approved programme
with its own threat model — it is not a side effect of an installability sprint.

## 8. Update lifecycle

Caches are versioned: `daengtisiams-static-v1`, from `CACHE_PREFIX` +
`CACHE_VERSION`. On `activate` the worker deletes every cache whose key starts
with `daengtisiams-` and is not the current one, and touches nothing else.

There is deliberately **no `skipWaiting()`**. A new worker waits until every
page the old one controls is gone, so a release can never swap assets under an
operator who is mid-form, and there is no reload loop. This is safe because
navigations are always network-first: fresh HTML references the new
content-hashed `/build/` URLs, which even the old worker simply fetches and
caches under the same allowlist.

Changing the cache policy requires bumping `CACHE_VERSION` so installed devices
discard the previous shell.

## 9. Registration

`resources/js/pwa.js` registers `/sw.js` at scope `/` on the `load` event, only
on a secure context. A failure is caught, logged as a bare error *name* (never a
URL, query string or response body) and never propagated — registration cannot
block or alter the application. There is no `beforeinstallprompt` interception
and no custom install prompt: Android's native install affordance is sufficient,
and an aggressive prompt would be a regression for desktop operators.

## 10. Server prerequisite

nginx 1.24 has no `.webmanifest` MIME entry, so the manifest would be served as
`application/octet-stream`. `deploy/nginx/pwa-manifest-mime.conf` is the
version-controlled record of the one-time server block that supplies
`application/manifest+json`, and marks `/sw.js` `no-cache` so a policy change
reaches installed devices immediately. Production nginx config is not deployed
from this repository; apply the include and reload nginx once per server.

## 11. Testing

| Suite | Command | What it proves |
| --- | --- | --- |
| `tests/js/pwa-service-worker.test.mjs` | `npm run test:js` | Evaluates the **real** `public/sw.js` in a sandbox and exercises its actual policy functions through the documented `self.__PWA_CACHE_POLICY__` seam. |
| `tests/Feature/Pwa/PwaServiceWorkerCachePolicyTest.php` | `php artisan test tests/Feature/Pwa` | Sweeps the **complete** route table from Laravel's router and asserts no registered URI can be classified as cacheable. |
| `tests/Feature/Pwa/PwaManifestTest.php` | as above | Manifest contract, icon existence and real PNG dimensions. |
| `tests/Feature/Pwa/PwaInstallMetadataTest.php` | as above | Metadata reaches both HTML shells; the offline page stays data-free. |

`tests/Feature/Pwa/PwaServiceWorkerCachePolicyTest.php` is declared in
`config/ci_runner.php` under `critical_gate_mandatory_suites`: a filter-token
match is an accident of naming, and this control must not be able to stop being
selected silently.

## 12. Production validation

```bash
curl -sSI https://daengtisia.online/manifest.webmanifest   # 200, application/manifest+json
curl -sSI https://daengtisia.online/sw.js                  # 200, javascript, no-cache
curl -sSI https://daengtisia.online/offline.html           # 200
curl -sSI https://daengtisia.online/pwa/icon-512.png       # 200, image/png
curl -sS  https://daengtisia.online/login | grep -o 'rel="manifest"'
```

Installability itself is only observable in a browser: Chrome DevTools →
Application → Manifest / Service Workers, or an Android install from the browser
menu. A present manifest is not proof of installability, and must not be
reported as such.

## 13. Future boundary — WebAuthn

A later phase may bind a doctor's web session to a device using WebAuthn. That
work is **out of scope here** and inherits every rule above. The PWA layer is
not, and must never be described as, a doctor device authentication mechanism:
an installed PWA proves nothing about which device is running it.
