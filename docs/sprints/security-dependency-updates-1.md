# SECURITY-DEPENDENCY-UPDATES-1

**Status:** implemented and tested; not merged, not deployed.
**Type:** `SECURITY_FIX`. **Base:** `f9134aac` (the deployed production authority).
**Assessment:** [`docs/governance/dependency-security-assessment-2026-10-27.md`](../governance/dependency-security-assessment-2026-10-27.md).

## 1. Change

- `composer.lock`: 10 packages, all within their current major — `laravel/framework` 12.70.1,
  `guzzlehttp/guzzle` 7.16.1 (`psr7` 2.13.2, `promises` 2.6.0), `league/commonmark` 2.10.4,
  `dompdf/dompdf` 3.1.6, `league/flysystem` 3.36.0, `league/flysystem-local` 3.35.3,
  `symfony/http-foundation` 7.4.21, `symfony/yaml` 7.4.21 (dev). `composer.json` unchanged.
- `package-lock.json`: 16 packages via `npm audit fix` (no `--force`), all within their ranges;
  `axios` 1.21.0 is the only one shipped in the browser bundle. `package.json` unchanged.
- `tests/Feature/Security/DependencySecurityFloorTest.php` (19): every patched package is locked at
  or above its first fixed release and on the major the application was built for. Declared
  mandatory; `DependencySecurityFloor` token in both critical-gate variants.

No migration, no route, no permission, no configuration change.

## 2. Verification

- `composer audit`: 30 production advisories before, 0 after (also 0 including dev).
- `npm audit`: 12 before, 0 after.
- The floor test passes on the new locks (19) and fails 18 of 19 on the old locks.
- Regression and gate results: see the pull request.

## 3. Deploy

The deploy runs `composer install --no-dev` and `npm run build`, so the updated packages and the
rebuilt bundle go out with the release. After deploy: health 200, login, a cashier receipt PDF and
an RME visit PDF render, `composer audit --locked --no-dev` on the host reports 0.

## 4. Rollback

`git revert` and redeploy restores the previous lock files.
