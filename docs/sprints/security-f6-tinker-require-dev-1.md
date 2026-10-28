# SECURITY-F6-TINKER-REQUIRE-DEV-1

**Status:** IMPLEMENTED AND VERIFIED LOCALLY. Not pushed, not merged, not deployed.

**Type:** `SECURITY_FIX`. **Base:** `19a2ed1c`
(`feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`). Audit item F-6
(`docs/audit/full-system-audit-2026-10-27/POST-AUDIT-EXECUTION-ROADMAP.md`, PR-06).

No migration, no route, no permission, no configuration change.

## 1. Finding

`laravel/tinker` was in `require`. Production installs with `composer install --no-dev`, so the
host carried `tinker` and PsySH although CG-14 forbids using them: PsySH writes ERROR lines into
the application log (holding monitoring at WATCH for a day), and a REPL is an unaudited write
path to clinical data. No production code imports either package; no script invokes tinker.

## 2. Change

- `composer.json`: `laravel/tinker` moved from `require` to `require-dev` (same constraint).
- `composer.lock`: re-resolved with `composer update laravel/tinker --minimal-changes`. Exactly
  three packages move from `packages` to `packages-dev` — `laravel/tinker`, `psy/psysh`,
  `nikic/php-parser` — and no package changes version.
- `ForbiddenConsoleCommandGuardTest`: a test that fails if tinker or PsySH returns to the
  production set in either file. It fails on the old manifest and passes on the new one. The
  runtime guard stays as the second layer (an older checkout rolled back still installs tinker).

## 3. Verification

- `composer validate`: valid.
- A clean `composer install --no-dev` of this tree: the application boots, `artisan list` shows
  290 commands and none is `tinker`; `vendor/psy` and `vendor/laravel/tinker` are absent.
- The deploy and rollback scripts run `composer install --no-dev` with scripts enabled, and
  `post-autoload-dump` regenerates the package manifest, so no stale reference to the tinker
  service provider survives a deploy.
- Console and shell guard suites, AI-instruction suite: 54 passed.

## 4. Production check after deploy

`php artisan list --raw` (as the runtime user) contains no `tinker`; `vendor/psy` is absent.

## 5. Rollback

`git revert` + redeploy: `composer install --no-dev` restores the previous package set.

## 6. Separate finding — not addressed here

`composer audit --locked --no-dev` on this tree reports advisories in six production packages,
all pre-existing and unchanged by this PR. Triage against the code:

| Package | Locked | Highest | Exposure here |
|---|---|---|---|
| `laravel/framework` | 12.61.0 | critical — TokenGuard authentication bypass (fixed in 12.68.0) | low: only the session guard is configured; no token guard |
| `league/commonmark` | 2.8.2 | high — DoS and an XSS filter bypass | low: the application renders no Markdown |
| `guzzlehttp/guzzle` | 7.13.1 | high — non-canonical host bypasses host checks | low: requests go to fixed hosts; SATUSEHAT is disabled |
| `dompdf/dompdf` | 3.1.5 | medium | to assess: PDFs render clinical data |
| `league/flysystem` | 3.35.1 | low | to assess |
| `symfony/http-foundation` | 7.4.13 | unrated | to assess |

A dependency update is outside this fix and needs its own PR and decision.
