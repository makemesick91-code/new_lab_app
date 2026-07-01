# Sprint 68.8 — Controlled RME History Stress Data Growth

## Scope
- Local/stress DB only (`daengtisia_stress` via `--env=stress`).
- Controlled staged synthetic RME history growth using Sprint 68.7 `stress:seed-rme-history`.
- Insert duration, DB footprint, idempotency, and RME read-path query evidence.
- Tiny stress-seeder fix for `queue_number` smallint overflow above visit seq 32767.
- No VPS deploy. No production/pilot data.

## Environment
| Item | Value |
|---|---|
| Branch | `feature/sprint-68-8-controlled-rme-history-stress-data-growth` |
| Commit before work | `a496deb` (Sprint 68.7 merge) |
| APP_ENV | `stress` (artisan `--env=stress` → `.env.stress`) |
| DB_CONNECTION | `pgsql` |
| DB_DATABASE | `daengtisia_stress` |
| PHP | 8.5.4 |
| Laravel | 12.61.0 |
| Disk/RAM baseline | 60 GB free on `/home`; ~4.7 Gi RAM available |

## Safety Rules
- Allowed envs: `local`, `stress`, `testing`
- Blocked envs: `pilot`, `production`
- Branch code: `TST`
- Chunk sizes: 100 (1k stage), 500 (10k), 1000 (50k/100k); oversized requests clamped to PostgreSQL-safe max (2000 visit rows)
- Stop conditions: disk < 20 GB, PG errors, seq overflow (fixed this sprint), no destructive DB ops
- PII handling: synthetic `TST-*` / `DG-TST-*` keys only; no names/KTP/NIK in report

## Baseline
| Metric | Value |
|---|---|
| Initial patients | 200 |
| Initial visits | 730 |
| Initial invoices | 730 |
| Initial payments | 660 |
| Initial DB size | 25 MB |
| Disk free | 60 GB |
| Memory | ~4.7 Gi available |

## Patient Base Preparation
- Patient count before: **200** (stress DB reset after Sprint 68.6 250k run)
- Action needed: **Yes** — re-seed to 250,000
- Patient seeder command:
  ```bash
  php artisan stress:seed-foundation --env=stress
  /usr/bin/time -v php artisan stress:seed-patients --env=stress --target=250000 --chunk-size=1000
  ```
- Patient count after: **250,000** (inserted 249,800 in 28.14 s; Max RSS 80,936 KB)

## Dry Run Verification
| Command | Result |
|---|---|
| `target=1000 --chunk-size=100 --dry-run` | PASS — branch 6, 730 existing (max seq 905), 95 remaining, 0 rows written |
| `target=50000 --chunk-size=999999 --dry-run` | PASS — clamped to chunk 2000, 49,095 remaining, 0 rows written |

## Staged RME Growth Results
| Stage | Target Visits (max seq) | Before Visits | After Visits | Inserted/Skipped | Chunk Size | Duration | Max RSS | DB Size After | Disk Free After | Result |
|---|---:|---:|---:|---:|---:|---|---|---|---|---|
| 1 | 1,000 | 730 | 824 | 94 / 1 | 100 | 0.40 s | 73,800 KB | ~30 MB | 60 GB | OK (max seq 999) |
| 2 | 10,000 | 824 | 9,823 | 8,999 / 2 | 500 | 13.28 s | 92,976 KB | ~80 MB | 60 GB | OK |
| 3a | 50,000 | 9,823 | 31,823 | ~22,000 then **FAIL** | 1000 | ~40 s (partial) | — | — | — | FAIL at seq 32768 (`queue_number` smallint overflow) |
| 3b | 50,000 (resume) | 31,823 | 49,823 | 18,000 / 0 | 1000 | 35.99 s | 126,160 KB | ~250 MB | 60 GB | OK after `queueNumberForSeq` fix |
| 4 (optional) | 100,000 | 49,823 | 99,823 | 50,000 / 0 | 1000 | 103.56 s | 147,312 KB | 495 MB | 60 GB | OK |

**Note:** Visit targets use **max visit sequence** (`TST-VIS-2026-NNNNNNNNN`), not raw row count. Row count is lower due to historical gaps/skips (99,823 rows at max seq 100,000).

## Counts After Final Stage
| Table | Count |
|---|---:|
| mst_patients | 250,000 |
| trx_clinic_visits | 99,823 |
| trx_medical_records | 99,823 |
| trx_medical_record_handwriting_pages | 99,823 |
| trx_odontograms | 99,823 |
| trx_rme_invoices | 99,823 |
| trx_rme_invoice_items | 299,469 |
| trx_rme_payments | 89,844 |
| trx_rme_receivable_follow_ups | 5,988 |
| trx_lab_case_candidates | 89,840 |

## Query Evidence
| ID | Query | Plan Summary | Runtime | Buffers | Decision |
|---|---|---|---:|---|---|
| Q1 | Visit list (branch, date desc) | Index Scan Backward `trx_clinic_visits_branch_date_status_index` | 1.37 ms | hit=80 read=207 | OK |
| Q2 | Patient RME history | Index Scan `trx_clinic_visits_patient_id_index` | 0.13 ms | hit=12 read=3 | OK |
| Q3 | Receivables (UNPAID/PARTIAL + balance subquery) | Index scan + per-row payment subplan | 0.20 ms | hit=138 | OK at 100k scale |
| Q4 | Payment report (paid_at desc) | Index Scan `trx_rme_payments_branch_paid_at_idx` | 0.09 ms | hit=7 | OK |
| Q5 | Owner payment aggregate (year sum) | **Seq Scan** on `trx_rme_payments` (~90k rows) | 27.23 ms | hit=746 read=1823 | WATCH — candidate for Sprint 68.9 |
| Q6 | Payment daily trend (group by day) | **Seq Scan** + HashAggregate | 32.67 ms | hit=2569 | WATCH — candidate for Sprint 68.9 |

Post-growth `ANALYZE` run on patients, visits, medical_records, invoices, items, payments, receivable_follow_ups.

## Idempotency Check
- Final target: **100,000** (max seq)
- Rerun command: `php artisan stress:seed-rme-history --env=stress --target=100000 --chunk-size=1000 --branch-code=TST`
- Result: **PASS** — `Target already reached. No new RME history inserted.` Counts unchanged at 99,823 visits.

## Laravel Log Review
- Fresh errors: one `SQLSTATE[22003] smallint` from Stage 3a (`queue_number` = visit seq); resolved by stress-seeder fix, subsequent stages clean
- Notes: no application/business-logic errors; stress tooling only

## Data Retention
- Stress data kept: **Yes** — `daengtisia_stress` retains 250k patients + ~100k RME visit chain for Sprint 68.9 read-path work
- Reason: local evidence baseline; no production impact

## Changes Made
- `app/Console/Commands/StressSeedRmeHistoryCommand.php` — `queueNumberForSeq()` keeps `queue_number` ≤ 32767 (PostgreSQL signed smallint) while unique per branch+visit_date cycle
- `tests/Feature/Console/StressSeedRmeHistoryCommandTest.php` — regression test for high-seq queue numbers
- `docs/sprints/sprint-68-8-controlled-rme-history-stress-data-growth.md` — this report

## Commands Run
```bash
git fetch origin
git switch feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
git pull --ff-only origin feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
git switch -c feature/sprint-68-8-controlled-rme-history-stress-data-growth

graphify update .

sed -i 's/\r$//' .env.stress   # local CRLF fix only; not committed

php artisan stress:seed-foundation --env=stress
/usr/bin/time -v php artisan stress:seed-patients --env=stress --target=250000 --chunk-size=1000

php artisan stress:seed-rme-history --env=stress --target=1000 --chunk-size=100 --branch-code=TST --dry-run
php artisan stress:seed-rme-history --env=stress --target=50000 --chunk-size=999999 --branch-code=TST --dry-run

/usr/bin/time -v php artisan stress:seed-rme-history --env=stress --target=1000 --chunk-size=100 --branch-code=TST
/usr/bin/time -v php artisan stress:seed-rme-history --env=stress --target=10000 --chunk-size=500 --branch-code=TST
/usr/bin/time -v php artisan stress:seed-rme-history --env=stress --target=50000 --chunk-size=1000 --branch-code=TST   # partial fail
/usr/bin/time -v php artisan stress:seed-rme-history --env=stress --target=50000 --chunk-size=1000 --branch-code=TST   # resume after fix
/usr/bin/time -v php artisan stress:seed-rme-history --env=stress --target=100000 --chunk-size=1000 --branch-code=TST

php artisan stress:seed-rme-history --env=stress --target=100000 --chunk-size=1000 --branch-code=TST   # idempotency

# ANALYZE + EXPLAIN (ANALYZE, BUFFERS) Q1–Q6 on daengtisia_stress

./vendor/bin/pint --dirty
php artisan test --filter=StressSeedRmeHistoryCommandTest
git diff --check
graphify update .
```

## Safety Confirmation
- No deploy.
- No VPS SSH.
- No migration.
- No destructive DB command (`migrate:fresh` / `db:wipe` not run).
- No business logic changed (stress seeder only).
- No `.env`/backup/SSH key/DB dump committed.
- No real PII/KTP/NIK exposed.

## Deferred
- HTTP page benchmark (`stress:benchmark-rme-pages`) — requires local stress server on `:8008`; skipped
- Gap between visit row count (99,823) and max seq (100,000) from historical skips — acceptable for stress keys
- Owner dashboard aggregate/trend seq scans at ~30 ms — tune in Sprint 68.9 if HTTP latency warrants

## Recommendation for Sprint 68.9
- **Sprint 68.9 — RME Read Path Bottleneck Review on 100k Visits**
  - Focus Q5/Q6 payment aggregate/trend shapes (seq scan → index-friendly range aggregate).
  - Optional `stress:benchmark-rme-pages` with stress server against 100k dataset.
  - Receivables listing at scale (Q3 subplan per row) if HTTP receivable page regresses.
