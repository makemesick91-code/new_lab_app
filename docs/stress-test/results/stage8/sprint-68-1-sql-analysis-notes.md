# Sprint 68.1 — SQL / EXPLAIN Analysis Notes

Database: `daengtisia_stress` (LOCAL ONLY). Collation `en_US.UTF-8` (NOT `C`).
`trx_clinic_visits`: 1,000,220 rows / 423 MB (279 MB heap + 143 MB indexes).
`mst_patients`: 250,000 rows. No table bloat (`n_dead_tup = 0`, freshly vacuumed/analyzed).

All EXPLAINs are read-only `SELECT`s (no `FOR UPDATE` in the EXPLAIN itself, to avoid
locking). INSERTs were NOT EXPLAIN-ANALYZEd (would create rows). Real visit-number
sample: `VIS-TST-20260630-220` (branch 1 code = `TST`).

---

## Query A — `nextQueueNumber()` (repo L130) — HEALTHY

```sql
SELECT queue_number FROM trx_clinic_visits
WHERE branch_id=1 AND visit_date='2026-06-30'
ORDER BY queue_number DESC LIMIT 1;     -- (+ FOR UPDATE in app)
```

```
Limit (actual time=0.054..0.055 rows=1)
  -> Index Only Scan Backward using trx_clinic_visits_branch_date_queue_unique
     Index Cond: (branch_id = 1 AND visit_date = '2026-06-30')
Execution Time: 0.071 ms
```

Verdict: efficient. Uses the `(branch_id,visit_date,queue_number)` unique index as a
backward index-only scan. NOT a bottleneck. (`FOR UPDATE` here still serializes
same-branch/date creates, but the read cost is negligible.)

---

## Query B — `generateUniqueVisitNumber()` (service L278) — ROOT CAUSE

```sql
SELECT visit_number FROM trx_clinic_visits
WHERE visit_number LIKE 'VIS-TST-20260630-%';   -- (+ FOR UPDATE in app)
```

### BEFORE patch

```
Gather (actual time=247.829..250.518 rows=220)
  Workers Planned: 2  Workers Launched: 2
  -> Parallel Index Only Scan using trx_clinic_visits_visit_number_unique
       Filter: ((visit_number)::text ~~ 'VIS-TST-20260630-%')
       Rows Removed by Filter: 333333         <-- ~1,000,000 rows scanned total
       Heap Fetches: 6380
  Buffers: shared hit=460 read=4930
Execution Time: 250.572 ms
```

Fresh-day prefix (`20260701`, 0 matching rows — the actual k6 case):

```
BEFORE: Parallel Index Only Scan, Rows Removed by Filter 333407/worker,
        Buffers hit=5378, Execution Time: 36.382 ms
```

**Why:** the unique index `trx_clinic_visits_visit_number_unique` is a *default* btree.
Under `en_US.UTF-8` collation a default btree CANNOT perform a `LIKE 'prefix%'` range
seek, so PostgreSQL reads the ENTIRE index and filters — O(table-size) on EVERY visit
create. With 2 parallel workers this also spends 3× backend CPU per insert.

### AFTER patch (added `(visit_number varchar_pattern_ops)` index)

Historical prefix (310 rows):

```
Index Only Scan using trx_clinic_visits_visit_number_pattern_idx
  Index Cond: (visit_number ~>=~ 'VIS-TST-20260630-' AND visit_number ~<~ 'VIS-TST-20260630.')
  Buffers: shared hit=98 read=217
Execution Time: 1.534 ms            (was 250.572 ms  ->  ~163x faster)
```

Fresh-day prefix (0 rows — the k6 case):

```
Index Only Scan using trx_clinic_visits_visit_number_pattern_idx
  Buffers: shared hit=3
Execution Time: 0.057 ms            (was 36.382 ms  ->  ~638x faster; buffers 5378 -> 3)
```

Verdict: the `varchar_pattern_ops` index turns the non-sargable `LIKE` into a narrow
range seek. The per-insert full-table scan is eliminated.

---

## Query C — visit-number uniqueness re-check (`do/while`, L294) — HEALTHY

```sql
SELECT exists(SELECT 1 FROM trx_clinic_visits WHERE visit_number='VIS-TST-20260701-001');
-> Index Only Scan using trx_clinic_visits_visit_number_unique, Execution Time: 0.085 ms
```

Equality uses the unique index fine. Not a bottleneck.

---

## Per-endpoint server latency (single session, no concurrency, AFTER patch)

Measured with `curl -w %{time_total}` over one authenticated session:

| Endpoint | Latency | Note |
|---|---|---|
| login (GET+POST /login) | 0.34 s | |
| GET /rme/visits (index) | 0.29 s | ~70 KB, paginates over 1M visits |
| GET /rme/visits/{id} (show) | 0.18 s | |
| **GET /rme/patient-queue** | **0.81 s** | **slowest read; 123 KB** |
| **POST /rme/visits (write)** | **0.06 s** | after patch — now the cheapest step |

Key inference: at 1 VU the whole iteration is ~2 s; at 10 VU it is ~18 s and at 20 VU
~36 s (everything inflates ~9–18×). That uniform inflation = a **shared resource (DB CPU)
saturates** under concurrency. The WRITE (`POST /rme/visits`) is now the smallest
contributor; the iteration is dominated by READ endpoints, chiefly
`GET /rme/patient-queue` (~0.81 s) and `GET /rme/visits` (~0.29 s).

---

## Index usage health (pg_stat_user_indexes highlights)

* `trx_clinic_visits_branch_date_status_index` — idx_tup_read ≈ 205,000,000 (very heavy;
  used by branch+date filtered reads such as the queue/index pages). Candidate for the
  next (read-path) investigation.
* `trx_clinic_visits_pkey` — 10.5M scans (show pages, FK checks). Healthy.
* No unused-but-required index gaps on the *write* path; all create-path FKs target
  PK-indexed columns.
