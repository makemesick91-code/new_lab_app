# FIX-LOGIN-REDIRECT-RUNTIME-PERMISSIONS

**Branch:** `feature/fix-login-redirect-runtime-permissions`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target `main`)
**Baseline:** `lab-prod-3-technician-capacity-planning-go` @ `7ad7c49`
**GO tag:** `fix-login-redirect-runtime-permissions-go`

## Incident

On the VPS pilot, Admin Lab (`userId 3`) hit **HTTP 500** immediately after login and, once the 500 was worked around manually, a **403** on `/dashboard`.

## Root causes (verified against code)

1. **500 — root-owned Laravel runtime cache.** `scripts/deploy-vps.sh` ran every `php artisan`
   command (governance gates, cache rebuild, `about`, `release:automated-smoke`) as the deploy
   user (root), and the single `chown www-data` was sandwiched *before* the final root-run
   artisan commands. PHP-FPM (`www-data`) was therefore left unable to write
   `storage/framework/cache/data` (the Spatie `PermissionRegistrar` FileStore) and
   `storage/logs` → `file_put_contents(...): Permission denied` on the first permission lookup
   after login.
2. **403 — forbidden default landing.** `/dashboard` (`routes/web.php`) requires
   `permission:view dashboard|view_owner_dashboard`. Admin Lab is Lab-only
   (FIX-ADMIN-LAB-LAB-ONLY-ACCESS) and holds neither.
3. **Intended override.** `AuthenticatedSessionController::store()` used
   `redirect()->intended($default)`, so a stored `url.intended = /dashboard` beat the role-aware
   default and sent Admin Lab straight into the 403.
4. **Guest-only smoke.** `release:automated-smoke` only exercises guest routes
   (`/login`, `/health/*`), which never touch the authorization + permission-cache path — so the
   defect was invisible to the deploy gate.

## Fix

### A. Centralized role-aware landing resolver
`App\Services\Auth\PostAuthenticationRedirectService` is the single source of truth for every auth
completion path (login, registration, email verification, password confirmation). It:
- computes a **role/permission-aware default** (online-context select → Admin Warehouse exec
  dashboard → Admin Lab Lab-V2 workspace → generic dashboard), and
- honors a stored `url.intended` **only** when it is internal, well-formed, resolvable to a route,
  and authorized for the user (decided by inspecting the target route's own `permission:`/`role:`
  middleware against the user's effective abilities, which honor `Gate::before`).

### B. Safe intended-URL sanitization
External hosts, protocol-relative (`//host`), `javascript:`/`data:`/`vbscript:`, malformed URLs,
unknown routes, and unauthorized routes are dropped → role-aware default. Authorized internal
intended URLs (e.g. `/profile`, or `/lab/v2-orders` for Admin Lab) are preserved.

### C. Auth completion path consistency
`AuthenticatedSessionController`, `RegisteredUserController`, `VerifyEmailController`,
`EmailVerificationPromptController`, `EmailVerificationNotificationController`, and
`ConfirmablePasswordController` all route through the resolver — none hardcodes
`redirect()->intended(route('dashboard'))` anymore.

### D. Deploy runtime-user hardening (`scripts/deploy-vps.sh`)
- Detect the PHP-FPM runtime user (explicit `RUNTIME_USER` → FPM pool `user =` → running worker →
  fallback `www-data`); **fail closed** if it resolves to root.
- Cache commands (`optimize:clear`, `config:cache`, `route:cache`, `view:cache`, `event:cache`)
  run as the runtime user via `runuser`.
- Ownership normalized **before** the runtime-user rebuild and **again after** all artisan/smoke
  commands; `chmod 2775` dirs (setgid) + `0664` files; **no world-writable (0777) mode**.
- Mandatory writable gates (`CACHE/SESSION/VIEW CACHE/LOG/BOOTSTRAP CACHE WRITE: GO`) — one FAIL
  aborts the deploy (NOT GO).

### E. Authenticated authorization + runtime-cache smoke
`php artisan deploy:auth-landing-smoke --strict` (run as the runtime user in the deploy) proves,
credential-free and PII-free: Illuminate cache put/get, Spatie permission cache reset/load, and the
role authorization matrix (Admin Lab default landing ≠ dashboard, Admin Lab may NOT access
`/dashboard` but MAY access `/lab/v2-orders`, Super Admin MAY access `/dashboard`). Missing role
accounts degrade to WATCH, never a fake GO; a contradiction or cache-write failure is NO_GO.

## Guarantees preserved
Admin Lab stays Lab-only (no dashboard permission added, no Super Admin granted, userId 3 unchanged);
`/dashboard` remains 403 for Admin Lab; Super Admin dashboard stays 200; no open redirect; no session
fixation (regeneration retained); backup-first + `migrate --force`-only deploy; no `migrate:fresh` /
`db:wipe`; no secret/PII in logs.

## Tests
- `tests/Feature/Auth/PostAuthenticationRedirectTest.php` — redirect matrix + intended-URL security.
- `tests/Feature/Deploy/DeployRuntimePermissionsScriptTest.php` — deploy-script hardening contract.
- `tests/Feature/Deploy/DeployAuthLandingSmokeCommandTest.php` — smoke command GO/WATCH/NO_GO.

## Deploy
Run **on the VPS** (never locally):
`ssh <vps> 'cd /var/www/asia-dental-lab-v2 && bash scripts/deploy-vps-runner.sh run'`.
No migration/seed. Post-deploy authenticated smoke: Admin Lab lands on `/lab/v2-orders` (200), not
`/dashboard` (403); Super Admin dashboard 200; zero new Laravel errors.
