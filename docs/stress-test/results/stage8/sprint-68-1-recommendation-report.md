# Sprint 68.1 — Write Bottleneck Investigation & Recommendation

**Date:** 2026-07-01
**Branch:** `test/sprint-68-1-write-bottleneck-investigation` (base
`feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`, HEAD `386ff37`)
**Decision:** **INVESTIGATION PASS + PATCH PASS** (targeted write-path fix) · composite
flow ceiling is **WATCH** (read-bound, deferred to Sprint 68.2)

---

## 1. Scope

Investigate why visit-create writes flatten at ~5.5/s under local k6 concurrency
(Sprint 67.6 observation), produce evidence, and recommend a targeted fix or next action.
Evidence-first; the write path is `POST /rme/visits` → `ClinicVisitController@store` →
`ClinicVisitService::create`.

## 2. Environment

* APP_ENV=stress · DB_DATABASE=daengtisia_stress · APP_URL=http://127.0.0.1:8008
* PostgreSQL, collation `en_US.UTF-8`. `trx_clinic_visits`: 1,000,220 rows / 423 MB.
  `mst_patients`: 250,000 rows. No bloat (`n_dead_tup=0`, freshly vacuumed/analyzed).
* Local only. No VPS, no SSH, no destructive SQL.

## 3. Baseline from Sprint 67.6

Throughput flattened ~5.5/s; duration scaled linearly with VU; 0 HTTP failures; 0
CSRF/419; 0 duplicate conflicts; 100% visit-create success; PostgreSQL CPU peaked high
while PHP was near-idle → suspected DB-bound / serialized write path.

## 4. Dependency graph summary

See `sprint-68-1-write-path-graph.md`. The create transaction issues two
`lockForUpdate` reads — `nextQueueNumber` (queue) and `generateUniqueVisitNumber`
(visit number) — then one INSERT. All FKs target PK-indexed columns; no audit-log
insert on the create path; `id` is identity (no sequence contention).

## 5. DB table/index inventory summary

See `sprint-68-1-db-baseline-snapshot.txt` + `sprint-68-1-index-inventory.txt`.
Relevant indexes on `trx_clinic_visits`: PK; `(branch_id,visit_date,queue_number)` unique;
`(branch_id,visit_date,status)`; `visit_number` unique (default btree). The
`visit_number` unique index is a **default** btree → under non-`C` collation it cannot
serve `LIKE 'prefix%'`.

## 6. k6 10 VU observed

| | http_reqs/s | write_flow med | http p95 | failures |
|---|---|---|---|---|
| BEFORE | 5.30/s | 18.08 s | 5.30 s | 0 |
| AFTER  | 5.27/s | 18.39 s | 5.62 s | 0 |

## 7. k6 20 VU observed

| | http_reqs/s | write_flow avg | http p95 | failures |
|---|---|---|---|---|
| BEFORE | 5.01/s | 35.65 s | 12.29 s | 0 |
| AFTER  | 5.44/s | 32.44 s | 12.00 s | 0 |

Throughput is flat at ~5/s across 10→20 VU while duration scales linearly → a shared
resource saturates. 100% visit-create success and 0 failures throughout (correctness is
not at risk — this is a *performance* ceiling).

## 8. Lock / activity observations

See `sprint-68-1-lock-and-activity-snapshots.txt`. Under load, backends were
predominantly in state `active` (CPU-running) on the visit-number / queue-number
queries, **not** parked on `Lock` waits. This points to **CPU saturation from query
execution** (the full-index scan) rather than pure lock blocking — consistent with the
67.6 "PG CPU high / PHP idle" signature.

## 9. SQL / EXPLAIN findings

See `sprint-68-1-sql-analysis-notes.md`.

* `nextQueueNumber`: Index Only Scan Backward, **0.07 ms** — healthy.
* `generateUniqueVisitNumber` `LIKE 'VIS-{code}-{date}-%'`: **Parallel Index Only Scan,
  ~1,000,000 rows removed by filter, ~250 ms per call** (36 ms even for a 0-row
  fresh-day prefix) — a full-table scan on **every** insert. ROOT CAUSE.
* Equality re-check: 0.085 ms — healthy.
* **Per-endpoint single-session latency:** patient-queue 0.81 s, visits index 0.29 s,
  show 0.18 s, **write 0.06 s (after fix)**. The k6 iteration is **read-dominated**.

## 10. Root cause hypothesis (confidence)

* **Write-path inefficiency — HIGH confidence.** `generateUniqueVisitNumber` runs a
  non-sargable `LIKE` that PostgreSQL satisfies with a full ~1M-row parallel
  index-only scan on every create, burning DB CPU (×3 with parallel workers). This is a
  real, proven defect that worsens as (a) daily visit volume grows and (b) write
  concurrency rises.
* **Composite k6 plateau — HIGH confidence that it is DB-CPU saturation, read-dominated.**
  Eliminating the write scan (proven 163–638× faster at the query level) did **not** move
  the aggregate k6 throughput, because each iteration spends far more DB time on the read
  pages (`patient-queue` ~0.81 s, `visits` index ~0.29 s) than on the write. The ~5/s
  ceiling is set by total DB CPU demand per iteration, not by the write alone.

## 11. Recommendation

* **PATCH NOW (done, included):** add a `varchar_pattern_ops` index on
  `trx_clinic_visits.visit_number` (migration `2026_07_01_100001`). It converts the
  per-insert full scan into a narrow range seek (EXPLAIN: 250.6 ms → 1.5 ms; fresh-day
  36.4 ms → 0.06 ms), removes a real DB-CPU consumer from every write, is additive /
  non-destructive, changes no business rule, and is test-neutral. Strongly advisable
  before the pilot accumulates more per-day visits.
* **DEFER deeper work to Sprint 68.2 (read path):** apply the same EXPLAIN methodology to
  `GET /rme/patient-queue` and `GET /rme/visits` (index). These dominate the iteration and
  set the ~5/s ceiling. Candidate items: review the heavily-read
  `(branch_id,visit_date,status)` index (idx_tup_read ≈ 205M), pagination/`count`
  strategy over 1M visits, and any patient joins on the queue page.
* **Optional follow-up (write path, low priority):** the two `lockForUpdate` reads still
  serialize same-branch/date creates; with the scan removed this is cheap, but a future
  per-branch/date sequence could remove serialization entirely if write concurrency
  becomes the binding constraint. Not needed now.

## 12. PASS / WATCH decision

* **INVESTIGATION PASS** — root-cause class identified with EXPLAIN + k6 + activity
  evidence.
* **PATCH PASS** — safe, targeted write-path fix implemented and verified
  (query-level 163–638×, zero regression, 0 failures).
* **WATCH** — composite k6 write-flow ceiling (~5/s) remains, now attributed to the
  read path and documented for Sprint 68.2.
* **No NO-GO** — no HTTP failures, no data corruption, no unsafe behavior.

## 13. Safety statement

* Local only — `daengtisia_stress` on `127.0.0.1:8008`. **No VPS deploy. No SSH.**
* No destructive SQL (no `migrate:fresh` / `db:wipe` / `dropdb` / `truncate` / delete /
  mass-update). Migration is additive `CREATE INDEX CONCURRENTLY`.
* Stress data remains local-only; never copied to VPS.
* No password / cookie / CSRF token / session file / login HTML committed.
