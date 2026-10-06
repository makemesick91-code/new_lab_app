# DaengtisiaMS Observability Console — architecture & rules

Status: **authoritative** (FEATURE-DEV-CONSOLE-OBSERVABILITY-1). Extends the ENT-7
Developer Assistance Console (`developer-assistance-console-governance.md`) and
the OBS-1 request-correlation foundation; neither is replaced.

The Observability Console is an **internal technical** interface, not a business
dashboard. It answers: who is using the system, what did they just open, what
failed and for whom, which pages and queries are slow, and how effective the cache
is — **without ever exposing medical data or PII**.

## 1. Routes and access

All routes live under `/dev-console`, are named `developer-console.*`, are
**GET/HEAD only** (ENT7-DC001), and share one gate: `auth` +
`permission:view_developer_console`. The permission is granted to **no role**;
Super Admin reaches it through the single global `Gate::before`. Owner, Supervisor
RME, Doctor, Admin Klinik, Kasir, Perawat and Admin Lab are refused **server-side**
(403). `ObservabilityFilterRequest::authorize()` re-checks the permission as a
second layer. The sidebar group is UX only, never the boundary.

| Route name | Path | Page |
|---|---|---|
| `developer-console.index` | `/dev-console` | Overview (system health, KPIs, 24h trends) |
| `developer-console.live-users` | `/dev-console/live-users` | Presence + recent activity (`?user=`) |
| `developer-console.errors` | `/dev-console/errors` | Error list |
| `developer-console.errors.show` | `/dev-console/errors/{uuid}` | Error detail (error events only) |
| `developer-console.slow-requests` | `/dev-console/slow-requests` | Slow requests + timeout visibility |
| `developer-console.slow-queries` | `/dev-console/slow-queries` | Slow queries + per-fingerprint aggregation |
| `developer-console.cache` | `/dev-console/cache` | App cache hit rate + Redis server |
| `developer-console.diagnostics` | `/dev-console/diagnostics` | The original ENT-7 diagnostic panels |

`developer-console.index` keeps its name because ENT-7 governance looks it up.
Every page view is audited through the existing ENT-7 path
(`VIEW_DEVELOPER_CONSOLE` in `sys_audit_logs`).

## 2. Instrumentation

Module `app/Modules/Observability` (Controller → FormRequest → Service →
RepositoryInterface → Repository → Model).

- **`RecordRequestTelemetry`** — a **global** middleware (prepended), so a 404 for a
  path that matches no route is still observed. `handle()` only arms an in-memory
  collector and measures duration (from `LARAVEL_START`). **All persistence happens
  in `terminate()`**, which PHP-FPM runs after the response has been flushed.
- **`TelemetryCollector`** (singleton) — counts queries, DB time and cache hits/misses
  and buffers slow queries **only while an HTTP request is active**. In a queue
  worker or Artisan command it is inert, so no long-running process can grow it.
- **`QueryExecuted` / `CacheHit` / `CacheMissed` listeners** — registered by
  `ObservabilityServiceProvider`; cheap no-ops unless collecting. **Bindings are never
  read.**
- **Exceptions** — `bootstrap/app.php` `$exceptions->respond(...)` notes every
  rendered exception (including unreported 404/403/validation) for the current
  request; it returns the response untouched. A PHP fatal (e.g. *Maximum execution
  time*) is recorded from `$exceptions->report(FatalError)` because `terminate()`
  never runs after a fatal.
- **Request correlation** — the OBS-1 `request_id` (also the `X-Request-ID` response
  header) is stored on every event and every slow query, so Activity, Error, Slow
  Request and Slow Query rows for one request join on it. A request that never
  entered the web group (unmatched 404) gets its own generated id.

## 3. What is recorded

- every **authenticated** request (presence + recent activity),
- every **error** — status in `observability_console.error_statuses` or any 5xx,
- every **slow** request (WATCH and above), and every captured **timeout**,
- **guest** requests **only** when they are errors or slow, capped at
  `guest_events_per_minute` so a scanner cannot flood the table.

Never recorded: `/up`, `/health/*`, static assets, the PWA files, and the 4-second
legacy-import status poll (`exclude` in config).

**Status normalization.** A web `ValidationException` (delivered as a 302 redirect
back) is recorded as **422** and an `AuthenticationException` (302 to login) as
**401**; the real response status is stored beside it (`response_status`).

**Working branch** shown beside an event is an **observation**, read by a plain
SELECT on the user's online-context row (fallback `users.branch_id`), memoized
briefly. `BranchContext::forUser()` is deliberately **not** used: it can lazily mark
an expired context inactive, and telemetry must never write to a clinical-workflow
table. The value is never an authorization input.

## 4. Thresholds (single source: `config/observability_console.php`)

| Signal | Default | Notes |
|---|---|---|
| Presence ONLINE | ≤ 120 s since last request | derived from requests; no browser heartbeat |
| Presence IDLE | ≤ 300 s | logout route ⇒ OFFLINE immediately |
| Request WATCH / SLOW / VERY_SLOW | ≥ 500 / 1000 / 3000 ms | "slow" in the console = WATCH and above |
| TIMEOUT | PHP fatal "Maximum execution time" | the only timeout the app can see |
| Slow query / CRITICAL | ≥ 500 / 2000 ms | max 20 per request |
| N+1 hint | ≥ 100 queries in one request | a **hint**, never a diagnosis |

**Timeout honesty.** Proxy/upstream timeouts (nginx 499/502/504), requests that
never reach PHP, and client/network RTO are **not visible** to the application and
are never claimed. The Slow Requests page says so and points at the nginx logs.

## 5. Cache metric definitions

- **DaengtisiaMS Cache Hit Rate** = `hits / (hits + misses) × 100` over Laravel's own
  cache events on recorded requests. Zero lookups ⇒ **N/A**, never 0%.
- **Redis Server Hit Rate** = `keyspace_hits / (keyspace_hits + keyspace_misses)` from
  Redis `INFO`. It is **server-wide** (every database and application on that server)
  and is **never** labelled as this application's rate.
- Redis is probed **only** when the deployment uses it for cache, session or queue;
  otherwise it is `NOT_IN_USE` — neither healthy nor down. Only allow-listed INFO
  counters are surfaced; a connection failure reports the exception **class only**
  (its message can name a host).

## 6. Storage, redaction, retention

Tables (additive migration `2026_10_06_100001`): `sys_obs_request_events`,
`sys_obs_slow_queries`. No foreign keys (telemetry must never block deleting a user
nor fail on a clinical constraint). Indexes exactly match the console queries.

`TelemetryRedactor` is the **one** place telemetry text is made safe:

- concrete URLs → **route templates** (`/rme/visits/{clinicVisit}`); unmatched paths
  have every id-shaped segment collapsed to `{x}`; **query strings are never stored**;
- exception messages → quoted literals, phone-shaped (≥ 9 digit) runs, PostgreSQL
  `Key (col)=(value)` details and everything the ENT-7 `SensitiveValueMasker` covers
  (credentials, bearer tokens, emails, KTP-shaped runs) become `[REDACTED]`;
  a `QueryException`'s interpolated `(Connection: …, SQL: …)` tail is **dropped**;
- a `ValidationException` message is **never stored** — only its field keys and a
  count (domain validation messages quote birth dates, document dates, Nomor RM);
- an unmatched-route 404 message (which embeds the raw path) is rebuilt from the
  redacted template; every message is cut at ` (Connection:`, `DETAIL:` and
  `Failing row contains` (PostgreSQL row data) and a `QueryException` keeps only
  its first line; Nomor RM (`DG-XXXX-YYYY-N`), calendar dates and separator-formatted
  phone/NIK are redacted;
- traces → at most 8 frames, relative paths, **no arguments**, and no absolute path
  even inside a PHP ≥ 8.4 closure name; stored for 5xx only;
- SQL → normalized (every literal and `$n` placeholder → `?`, IN-lists collapsed),
  fingerprinted with sha1; bindings never read.

Retention (hours, config): access 72, errors 720, slow requests 336, slow queries 336.
A row lives as long as its **longest** applicable window. Production's scheduler is
registered but never fires, so pruning runs as a **lottery** (1 in 200 recorded
requests) after the response, deleting at most `batch_size` (1000) rows per class
per pass; `php artisan observability:prune` runs the same code on demand and is
idempotent. It deletes telemetry only.

Overview trends are aggregated **in SQL** per UTC hour (`hourlyCountsSince`); the
p95 trend uses `percentile_disc` on PostgreSQL (exact) and a capped sample elsewhere,
which the page labels as **SAMPEL** — a trend never silently undercounts.

A logout request marks the user OFFLINE: the `Logout` event notes the user because
the guard holds nobody by `terminate()`. Path exclusions ending in `/` are prefixes,
every other entry is exact (`up` never swallows `uploads/…`).

**Accepted residuals (documented, not fixed):** (a) the kernel runs route-middleware
`terminate()` before global middleware, so a throwing route-middleware `terminate()`
loses that one telemetry row — never more, and nothing leaks across requests;
(b) the guest cap uses `Cache::increment`, which is approximate on the `file` store,
and a scanner can spend the per-minute guest budget — authenticated activity is
never sampled; (c) `recordFatal` after a memory-exhaustion fatal is best effort.

## 7. Non-negotiable rules

1. **Never log raw passwords, tokens, cookies, KTP/NIK, clinical notes, SOAP, raw RME
   payload, odontogram payload, or sensitive request bodies into observability
   telemetry.** Request bodies are never read at all.
2. **New modules/routes must remain compatible with request correlation and
   observability instrumentation unless explicitly excluded** in
   `observability_console.exclude` (with a reason).
3. **Observability must remain bounded-retention and must not materially degrade
   production request performance.** No write before the response is sent; no
   unbounded read in a console page; no new daemon.
4. Telemetry never throws, never reports from inside a request, and never writes to
   a non-`sys_obs_*` table. A broken telemetry table must not break a request.
5. The console stays GET-only, `view_developer_console`-gated, audited; a new panel
   needs masking coverage plus permission and audit tests (ENT7-DC rules).
6. Thresholds live only in `config/observability_console.php`; never repeat a number.
7. A label that could be mistaken for another scope must say its scope (app vs
   Redis-server hit rate; observed vs authoritative branch; captured vs invisible
   timeouts).
8. The test suite runs with `OBSERVABILITY_TELEMETRY_ENABLED=false` (phpunit.xml) so
   query-budget suites measure the application; the observability suite turns it on
   explicitly.
