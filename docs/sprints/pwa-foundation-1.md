# PWA-FOUNDATION-1 — Installable PWA Foundation

**Branch:** `feature/pwa-foundation-1`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `1555ad5b`
(the exact commit production was running when the sprint opened)
**Durable contract:** `docs/architecture/pwa-foundation.md`
**Rule mirror:** `.cursor/rules/92-pwa-foundation.mdc`

## Objective

Make the existing DaengtisiaMS web application installable on Android as a
Progressive Web App running in standalone display, with a static shell cache and
a safe offline fallback — without changing authentication, device
authorization, RBAC, branch isolation or any clinical workflow.

## Scope boundary

**Delivered:** web app manifest, PWA icons (including maskable), standalone
display, service worker with a deny-by-default cache allowlist, root-scoped
registration, a self-contained offline fallback, a conservative update
lifecycle with cache versioning and stale-cache cleanup, automated regression
protection, documentation and rules.

**Deliberately not touched:** doctor login behaviour, doctor app-only
enforcement, browser denial, the Android device API, Keystore flow, device
challenge/signature protocol, `DoctorDeviceAuthorization`, device approval and
enforcement flags, pilot enforcement scope, WebAuthn, passkeys, mTLS,
`ClinicDevice`, doctor-device schema, branch binding, room authorization, RME
authorization, RBAC and policies. The Android APK is not retired.

**PWA-FOUNDATION-1 DOES NOT ENABLE DOCTOR DEVICE ENFORCEMENT.**

## Changes

| File | Why |
| --- | --- |
| `public/manifest.webmanifest` | Web app manifest: `id`/`start_url`/`scope` `/`, `display: standalone`, UIX-1 colours, four icon entries. |
| `public/sw.js` | Service worker. Deny-by-default cache allowlist, network-first navigations, single `cache.put()` write path, versioned cache + stale purge, no `skipWaiting()`. |
| `public/offline.html` | Static, self-contained offline shell. No patient data, no build dependency, no client-side persistence. |
| `public/pwa/icon-192.png`, `icon-512.png` | `purpose="any"` icons, generated from the official brand logo. |
| `public/pwa/icon-maskable-192.png`, `icon-maskable-512.png` | `purpose="maskable"` icons kept inside the 80 % safe-zone circle. |
| `public/pwa/apple-touch-icon-180.png` | iOS home-screen fallback (Safari ignores the manifest for this). |
| `resources/js/pwa.js` | Registration module: secure-context guard, root scope, non-fatal failure, no install-prompt interception. |
| `resources/js/app.js` | Imports and calls `bootPwa()` after `Alpine.start()`. |
| `resources/views/layouts/partials/pwa-head.blade.php` | Manifest link, `theme-color`, Android/iOS standalone metadata, icons. |
| `resources/views/layouts/app.blade.php` | Includes the PWA head partial (authenticated shell). |
| `resources/views/layouts/guest.blade.php` | Includes the PWA head partial (login shell). |
| `deploy/nginx/pwa-manifest-mime.conf` | Version-controlled record of the one-time nginx MIME block (nginx 1.24 has no `.webmanifest` type) and the `no-cache` header for `/sw.js`. |
| `.github/workflows/foundation-evidence-gates.yml` | Adds the `Pwa` critical-gate filter token, and a **JavaScript unit tests** step. |
| `config/ci_runner.php` | Declares `tests/Feature/Pwa/PwaServiceWorkerCachePolicyTest.php` as a mandatory critical-gate suite. |
| `tests/js/pwa-service-worker.test.mjs` | Evaluates the real worker and exercises its actual policy functions. |
| `tests/Feature/Pwa/*.php` | Manifest contract, install metadata, and the full-route-table cache sweep. |
| `docs/architecture/pwa-foundation.md`, `.cursor/rules/92-pwa-foundation.mdc`, `CLAUDE.md` | Durable rules. |

## Decisions worth recording

**Static files, not Laravel routes.** The `web` middleware group globally
appends `EnsureRmeOnlineContext`, so a routed manifest or worker could be
answered with a redirect for some roles, and would carry a session cookie. A
service worker also needs to be served from `/` to obtain root scope. Serving
all three from `public/` satisfies both constraints without exception handling.

**No `skipWaiting()`.** A new worker waits for the old one's clients to go away,
so a release can never swap assets under an operator who is mid-form. This is
only safe because navigations are network-first: fresh HTML references new
content-hashed `/build/` URLs, which even the old worker fetches and caches
under the same allowlist.

**One write path.** `cache.put()` appears exactly once in the file, inside
`putIfAllowed()`, which re-checks origin, method, allowlist and response status.
Widening a calling branch therefore cannot widen what is stored. Both the JS and
PHP suites assert this structurally.

**The route sweep, not a path list.** The PHP security test walks the complete
route table from Laravel's router (630 registered routes at the time of writing)
and asserts every URI — declared and parameter-bound — is refused by the cache
policy. A hand-written list of sensitive paths would go stale the first time a
route was added; this cannot.

**Testing the real worker.** `tests/js/pwa-service-worker.test.mjs` evaluates
`public/sw.js` itself in a `node:vm` sandbox with the service-worker globals
stubbed, and reads the actual policy functions from the documented
`self.__PWA_CACHE_POLICY__` seam. Only the trivial prefix/exact comparison is
expressed twice (once in JS, once in PHP), and both read the same allowlist
block out of the shipped file.

**`npm run test:js` was never wired into CI.** The script existed for the
patient-combobox state machine, but no job invoked it, so those tests had been
running nowhere. The PWA cache policy would have shipped equally ungated. The
quality gate now runs it.

**Registry declaration.** A `|Pwa` filter token alone is a naming coincidence —
exactly the failure mode `critical_gate_mandatory_suites` exists to prevent. The
cache-policy suite is declared, so a rename must move the coverage rather than
silently delete it.

**The tests caught the sprint's own documentation.** The first run of the source
invariants failed on the worker's header prose ("there is deliberately no
skipWaiting()", "never persist to … localStorage"). The fix was to scan stripped
code, not to delete the documentation.

## Verification

| Gate | Result |
| --- | --- |
| `npm run test:js` | 37 passed |
| `php artisan test tests/Feature/Pwa` | 24 passed, 1159 assertions |
| `php artisan test --filter=Cicd` | 288 passed (workflow + registry change) |
| `npm run build` | 58 modules, 0 errors |
| `./vendor/bin/pint --test` | recorded in the sprint evidence |
| `git diff --check` | clean |

Production validation commands are in `docs/architecture/pwa-foundation.md` §12.

## Deploy

No migration. No seeder. No permission. Static assets and the rebuilt Vite
bundle must reach production; `scripts/deploy-vps-runner.sh` runs **on the VPS**.

One-time per server: include `deploy/nginx/pwa-manifest-mime.conf` in the
DaengtisiaMS nginx `server` block and reload nginx, so the manifest is served as
`application/manifest+json` and `/sw.js` is served `no-cache`.
