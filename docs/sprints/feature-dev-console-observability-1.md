# FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — DaengtisiaMS Observability Console

Branch `feature/dev-console-observability-1` (base
`feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`,
baseline `fix-legacy-odontogram-cross-branch-read-scope-1-go` docs tip `58388258`;
never `main`). Architecture authority: `docs/architecture/observability-console.md`.
Rule mirror: `.cursor/rules/175-observability-console.mdc`.

## What changed

`/dev-console` (ENT-7) becomes the **DaengtisiaMS Observability Console** with a
sub-navigation and a sidebar group:

| Page | Answers |
|---|---|
| Overview | System health (Application, Database, Queue, Storage, Cache, Redis — real ENT-8 probes), 24h KPIs (online users, errors, slow requests, slow queries, app cache hit rate), hourly trends (errors, slow requests, slow queries, p95 latency) |
| Live Users | Who is ONLINE / IDLE / OFFLINE (derived from their last request), what they last opened, and a selected user's recent activity |
| Errors | 4xx/5xx with user, role, branch, route, activity, duration; detail page with request id, sanitized exception, relative file:line, argument-free trace (5xx), and the request's slow queries |
| Slow Requests | Total / DB time / query count per slow request, latency category, N+1 *hint*, and an honest statement of which timeouts the app can and cannot see |
| Slow Queries | Normalized SQL per occurrence and per-fingerprint aggregation (count, requests, avg, p95, max, routes) |
| Cache / Redis | Application cache hit rate (Laravel events) vs Redis **server** hit rate (INFO), Redis only probed when actually used |
| Diagnostik | The original ENT-7 panels, unchanged |

## Design decisions (each one deliberate)

- **No new permission.** Same gate as ENT-7: `auth` + `permission:view_developer_console`,
  Super Admin only. Owner, Supervisor RME and every operational role get 403.
- **Writes only after the response.** A global middleware arms an in-memory
  collector; persistence happens in `terminate()`. A broken telemetry table cannot
  break a request (tested by dropping the tables).
- **Presence without a browser heartbeat.** Derived from the last recorded request:
  zero extra traffic, and "IDLE" means what it says.
- **Exceptions via the handler's `respond()` hook**, the one place every rendered
  exception passes — including unreported 404/403/validation. Web validation (302)
  is recorded as 422, unauthenticated (302) as 401; the real status is kept.
- **Telemetry never calls `BranchContext::forUser()`** — that resolver can lazily
  write to an online-context row. The branch beside an event is a read-only
  observation.
- **Retention without the scheduler.** Production's scheduler never fires, so a
  bounded lottery prune runs after recorded requests; `observability:prune` is the
  idempotent on-demand path.
- **Redis honesty.** Production's cache/session/queue drivers are file/database/
  database, so the console reports Redis `NOT_IN_USE` rather than a fabricated
  status; the application hit rate comes from Laravel's own cache events.
- **Timeout honesty.** Only PHP execution-time fatals are visible to the app; nginx
  499/502/504 and client RTO are stated as invisible, not guessed.
- **Charts** (dataviz): single-series hourly bars in one validated hue
  (`brand-600`, passes the palette validator against the canvas surface), zero
  baseline, hover values, peak/total captions, a table view per chart; no chart
  library.

## Migration

`2026_10_06_100001_create_sys_observability_telemetry_tables` — additive:
`sys_obs_request_events`, `sys_obs_slow_queries`, indexes matching the console
queries, no foreign keys. Deploy with `php artisan migrate --force`.

## Tests

`tests/Feature/Observability/ObservabilityTelemetryTest.php` (25 tests) and
`ObservabilityConsoleTest.php` (82 tests incl. datasets), both declared in
`config/ci_runner.php` `critical_gate_mandatory_suites` and selected by the
`ObservabilityConsole` / `ObservabilityTelemetry` tokens in both critical-gate
variants. The suite-wide default is `OBSERVABILITY_TELEMETRY_ENABLED=false`
(phpunit.xml) so query-budget suites keep measuring the application.

## Verification (pre-merge, local)

- `tests/Feature/Observability` — **107 passed / 381 assertions** (SQLite), and on a
  real **PostgreSQL 16.15** container with `tests/Feature/DeveloperConsole`:
  **114 passed / 412 assertions**; all 10 indexes present; migrate → rollback →
  migrate round-trip clean.
- Regression chunk (DeveloperConsole, UiFoundation, AdminLabLabOnly,
  PwaServiceWorker, SidebarPermission, HealthCheckEndpoint, FoundationMonitoring,
  Satusehat2Gateway, CriticalGateSuiteCoverage, SafeCiRuntimeControl): **121 passed /
  2150 assertions**.
- Governance (migrated scratch DB): developer-console-check 8/8 GO,
  security-compliance-check 9/9 GO, ci-runtime-control-check 6/6 GO,
  cicd-enterprise-gate-check 10/10 GO, health-check GO, roadmap-check GO,
  ui-governance-check GO; `sprint:manifest-check` GO; `sprint:scope-audit` GO
  (1 module). `pint --test` passed, `git diff --check` clean, `npm run build` passed.
- CI filter: both critical-gate variants byte-identical; `--list-tests` selects 78
  Observability tests.
- **Overhead** (300 requests each, same route, telemetry off vs on, write included
  because the test client runs `terminate()` inline): median 3.20 → 3.44 ms
  (+0.24 ms), p95 3.87 → 3.99 ms; `handle()`-only cost ≈ 10 µs. In production the
  write runs after the response is flushed, so the user-felt cost is the `handle()`
  cost.
- **Adversarial review** (independent sub-agent): 0 CRITICAL, 1 HIGH (PostgreSQL
  `Failing row contains` row data surviving redaction), 4 MEDIUM (raw path in 404
  message, validation messages quoting clinical values, trend undercount past 20k
  rows, Overview cost), 6 LOW. HIGH + all MEDIUM fixed with regression tests; LOW
  fixed except three accepted residuals recorded in the architecture doc.
- **Mutation**: 12 mutants, **12 killed, 0 survivors** (three initially survived
  because a redundant sibling layer masked them — DETAIL cut, FormRequest authorize,
  SQL string-literal normalization — each now pinned in isolation).

## Closure evidence (2026-10-07 WITA)

- **PR #458** squash-merged as `8400080aa8cc112d0d89d471b33748c55d05308f`; merge tree
  `322fb5b4` is byte-identical to the CI-validated candidate `f440b74a`.
- **CI run 37502250830** (exact candidate): CICD-CTRL Classifier, NSF-R012 Quality,
  NSF-R011 Critical (**5142 passed / 26620 assertions, exit 0**, Observability suites
  included), CICD-CTRL Selective Module, NSF-9, NSF-10, Phase 3 Android gate — all pass.
  NSF-R011 Full Suite **skipped** under the standing temporary policy (not a pass).
  An earlier run failed only on Pint (three appended tests) and was fixed in `f440b74a`.
- **Deploy** on `srv1730088` (`/var/www/asia-dental-lab-v2`, as root) via
  `bash scripts/deploy-vps-runner.sh start`: `exit=0`, `DEPLOY OK: 20261006-191629`,
  `DEPLOY_HEAD_TARGET_MATCH=YES`, snapshot cleaned. Pre-deploy backup
  `pre_auto_deploy_20261006-191629.sql` (31,603,432 B) taken **before** migrate and
  verified 9/9 GO; migration `2026_10_06_100001` Ran (batch 78). Pre-deploy HEAD
  `c74f282f`; post-deploy HEAD/tree `8400080a`/`322fb5b4`, tracked tree clean.
  The one smoke WARNING is the known co-tenant nginx shadow on bare `127.0.0.1`.
- **Production verification** over `https://daengtisia.online`: `/login`,
  `/health/live`, `/health/ready`, `/health/lb` 200; every `/dev-console/*` route 302
  for guests (no 500); `/storage/*` 403. Synthetic guest probes recorded correctly
  (read-only SELECT): a 404 for `/obs-smoke-probe-<ts>/12345` stored as `/{x}/{x}` with
  the message rebuilt from the template (raw-path leak count **0**); seven
  unauthenticated console hits stored as **401** (response 302). Health/login/nginx-403
  probes correctly produced no rows. `laravel.log` byte-identical before/after
  (1,430,279 B, 161 ERROR) — **zero new errors**; php8.3-fpm, nginx, queue worker
  active; no failed jobs; env pilot, debug OFF, maintenance OFF.
- **Not exercised in production (stated, not claimed):** an authenticated Super Admin
  page render (no credential is available to the operator of this change, and creating
  a session for a real account was deliberately not done) — covered by the console
  suite on the identical tree in CI; slow-request/slow-query capture (no safe way to
  induce one on production) — covered by tests incl. PostgreSQL 16.15; Redis — production
  uses file/database backends, so the page reports `NOT_IN_USE`.
- **GO tag** `feature-dev-console-observability-1-go` (annotated, object `9b17b861`) →
  `8400080a`; exact match at the VPS HEAD.
