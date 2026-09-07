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

## Shipped

**PR #393** squash-merged as `e4fa78b1c9b2eff715221458864943527f157b2a`, tree
`8ff6e777a58400f5513b02623f2336adce0d03bf` — byte-identical to the CI-verified
candidate tree. GO tag `pwa-foundation-1-go` @ `e4fa78b1` (annotated, tag object
`4a7206d71d2d6a4cf2efa8665ec027dd41a8e06e`), exact-matching production HEAD.

**CI run `34141791126`** — every gate success: Classifier, NSF-R012 Quality
(including the newly wired `npm run test:js` step), NSF-R011 Critical
(`1 risky, 3499 passed / 15257 assertions`; the base branch's last green run was
`1 risky, 3475 passed`, so the delta is exactly this sprint's 24 PWA tests, and
the 1 risky is pre-existing), CICD-CTRL Selective Module, Phase 3 Android Clinic
App, NSF-9 Release Safety & Smoke, NSF-10 Release Evidence. The Full Suite gate
is skipped per the standing temporary policy.

The first candidate `7530b52a` FAILED the critical gate on
`LegacyRmeProgramClosureContractTest` — replacing the rolling `.sprint/current.yml`
dropped its carried-forward inherited-state block. Fixed as the contract's own
comment prescribes (restate the inherited facts, do not relax the expectation),
not by weakening the gate.

**Local:** layout-wide regression `Pwa|Auth|Ui|Navigation|Dashboard|Profile`
— 1441 passed / 2 skipped / 8828 assertions, zero failures. All deploy-time
governance gates GO (`ui-governance --strict`, `security-compliance` 9/9,
`cicd-enterprise-gate` 10/10, `enterprise-documentation` 21/21,
`ci-runtime-control --strict` 6/6, `roadmap --strict` next `MON-1` not stale).

## Deployed

`scripts/deploy-vps-runner.sh start` run **on** `srv1730088` in
`/var/www/asia-dental-lab-v2`; `final exit=0`, `DEPLOY RUNNER OK`,
`DEPLOY OK: 20260907-170232`. Runtime isolation 70 GO / 0 FAIL. `PRODUCTION_HEAD`
and `PRODUCTION_TREE` both match the merge exactly. Automated smoke 6 passed /
1 warning: `SMOKE-HTTP-HEALTH` probes `http://127.0.0.1/login` and gets 404 from
the shared-VPS `default_server` catch-all — **pre-existing**, identical in the
previous four deploy logs, and unrelated to this change. The canonical entry
point is the domain, which returns 200.

Server prerequisite applied once: `deploy/nginx/pwa-manifest-mime.conf` included
in the DaengtisiaMS nginx `server` block (backup
`asia-dental-lab.bak-20260907-pwa-foundation-1`, `nginx -t` ok, reload). The
resulting config diff is exactly three lines, inside that block only.

## Production verification

Over `https://daengtisia.online`:

| Check | Result |
| --- | --- |
| `/manifest.webmanifest` | 200 `application/manifest+json` |
| `/sw.js` | 200 `application/javascript`, `Cache-Control: no-cache` |
| `/offline.html` | 200 `text/html` |
| `/pwa/*.png` (5 icons) | 200 `image/png` |
| `/login` | 200, carries `rel="manifest"` and `theme-color #2563EB` |
| `/health/live`, `/health/ready` | 200 |
| guest `/dashboard`, `/rme/visits`, `/rme/cashier` | 302 (no 500) |
| `/storage/` | 403 — STORAGE-1 containment intact |

**In a real browser (headless Chrome 149 against production):**

- Chrome's own manifest parser returned `errors: []` — `standalone`,
  `start_url` `/`, `scope` `/`, name and short_name correct, theme `#2563EB`,
  background `#F7F9FC`, four icons including maskable 192 and 512.
- The service worker registered and reached `activated` at scope
  `https://daengtisia.online/` from `/sw.js` — root scope confirmed.
- **The security claim, measured rather than argued:** after browsing `/login`,
  `/dashboard`, `/rme/visits` and `/rme/cashier/receivables`, Cache Storage held
  only `daengtisiams-static-v1` containing the seven-file shell plus the brand
  logo and the content-hashed build CSS/JS. No navigation response, no route, no
  API response, no patient data.
- **Offline:** with DNS failing for the origin in *every* context including the
  worker's own (`--host-resolver-rules="MAP daengtisia.online ~NOTFOUND"` —
  CDP's `Network.emulateNetworkConditions` only reaches the page target and is
  not a valid test of this), navigating to `/rme/patient-queue` rendered the
  static offline shell: title "Tidak Ada Koneksi — DaengtisiaMS", the
  "Koneksi internet tidak tersedia" copy, the "Coba Lagi" button, no clinical
  content, `navigator.serviceWorker.controller` present.

**Security neutrality, proven rather than asserted.** The Phase 4A doctor-device
pilot enforcement scope was captured before and after the deploy and is
byte-identical: `ENFORCEMENT_FLAG_ARMED=true`, `ENFORCEMENT_SCOPE_MODE=pilot`,
`GLOBAL_ENFORCEMENT_ACTIVE=false`, `COVERED_DOCTOR_USER_IDS=18`,
`BROWSER_DENIED_DOCTOR_COUNT=1`, `BROWSER_ALLOWED_DOCTOR_COUNT=14`,
`SCOPE_VERDICT=GO`. The deploy changed no enforcement state.

Post-deploy: no Laravel log for the day (zero application errors), nginx error
log shows only the STORAGE-1 containment 403s, `queue:failed` empty,
`APP_ENV=pilot`, `APP_DEBUG=false`, maintenance off, nginx and `php8.3-fpm`
active.

**Real device smoke: NOT EXECUTED** — no Android tablet was reachable from this
environment. The browser evidence above is the equivalent automated validation;
an install from a physical device has not been performed and is not claimed.

## Deploy

No migration. No seeder. No permission. Static assets and the rebuilt Vite
bundle must reach production; `scripts/deploy-vps-runner.sh` runs **on the VPS**.

One-time per server: include `deploy/nginx/pwa-manifest-mime.conf` in the
DaengtisiaMS nginx `server` block and reload nginx, so the manifest is served as
`application/manifest+json` and `/sw.js` is served `no-cache`.
