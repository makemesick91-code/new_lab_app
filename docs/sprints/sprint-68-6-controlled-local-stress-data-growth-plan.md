# Sprint 68.6 — Controlled Local Stress Data Growth Plan

## Scope
- Local/stress DB only (`daengtisia_stress`).
- Controlled staged synthetic patient growth via stabilized `stress:seed-patients` (Sprint 68.5).
- No VPS deploy, no SSH, no migration, no business-logic change.
- No real patient data; no KTP/NIK/full PII in this report.

## Environment
| Item | Value |
|---|---|
| Branch | `feature/sprint-68-6-controlled-local-stress-data-growth-plan` |
| Commit before work | `e092c85` (Sprint 68.5 merge) |
| APP_ENV | `stress` |
| DB_CONNECTION | `pgsql` |
| DB_DATABASE | `daengtisia_stress` |
| PHP | 8.5.4 |
| Laravel | 12.61.0 |
| Machine disk/RAM snapshot | Disk 81G total, 61G free on `/home`; RAM 15Gi total, ~4.7Gi available; load ~1.3–1.7 |

## Safety Rules
- Allowed envs: `local`, `stress`, `testing`.
- Blocked envs: `production`, `pilot` (command fail-fast).
- Chunk size: `--chunk-size=1000` (effective; safe max 3157 for 19 columns).
- Stop conditions: disk &lt; 20G free, PG errors, stuck seed, critical log errors, non-local DB — none triggered.
- PII handling: synthetic `DG-TST-2026-*` MR numbers only; no names/KTP exported in evidence.

## Prerequisite
`stress:seed-foundation` was run once because branch `TST` was missing on a fresh stress DB (1 branch, 10 rooms, 100 users, 25 doctors, etc.). No patient rows created by foundation.

## Baseline
| Metric | Value |
|---|---|
| Initial patient count | 0 |
| Initial DB size | 19 MB |
| Disk free | 61 GB |
| Memory available | ~4.8 GiB |

## Dry Run Verification
| Command | Result |
|---|---|
| `stress:seed-patients --target=10000 --chunk-size=1000 --dry-run` | PASS — 19 cols, effective chunk 1000, 19000 params/chunk, 0 rows written |
| `stress:seed-patients --target=10000 --chunk-size=999999 --dry-run` | PASS — clamped to 3157 (59983 params), warning shown, 0 rows written |

## Staged Growth Results
| Stage | Target Patients | Before Count | After Count | Inserted/Skipped | Chunk Size | Duration | Max RSS | DB Size After | Disk Free After | Result |
|---:|---:|---:|---:|---:|---:|---|---:|---|---|---|
| 0 | baseline | 0 | 0 | — | — | — | — | 19 MB | 61 GB | OK |
| 1 | 10,000 | 0 | 10,000 | 10,000 / 0 | 1000 | 1.30 s | 80,848 KB (~79 MB) | *(not sampled)* | 61 GB | OK |
| 2 | 50,000 | 10,000 | 50,000 | 40,000 / 0 | 1000 | 4.68 s | 80,896 KB | *(not sampled)* | 61 GB | OK |
| 3 | 100,000 | 50,000 | 100,000 | 50,000 / 0 | 1000 | 5.68 s | 80,912 KB | *(not sampled)* | 61 GB | OK |
| 4 (optional) | 250,000 | 100,000 | 250,000 | 150,000 / 0 | 1000 | 16.38 s | 81,004 KB | 129 MB (`mst_patients` 109 MB) | 61 GB | OK |

**Insert throughput (approx.):** ~6,100–7,700 rows/s across stages; PHP memory flat ~80 MB RSS regardless of volume.

## Query Evidence
Post-`ANALYZE mst_patients` at 250,000 rows (`SET statement_timeout = '10s'`).

| Query | Plan Summary | Runtime | Buffers | Decision |
|---|---|---:|---|---|
| `ORDER BY id DESC LIMIT 50` (non-deleted) | Index Scan Backward on `mst_patients_pkey` + `deleted_at IS NULL` filter | 0.066 ms | shared hit=6 | OK at 250k |
| `medical_record_number LIKE 'DG-TST-2026-%' ORDER BY id DESC LIMIT 50` | Index Scan Backward on `mst_patients_pkey` + filter on MRN prefix | 0.045 ms | shared hit=6 | OK at 250k; unique index on MRN exists but planner prefers PK for this shape |

**Indexes on `mst_patients`:** `pkey(id)`, unique `medical_record_number`, unique `ktp_number`, btree on `name`, `clinic_id`, `doctor_id`, `is_active`.

No patient-list HTTP smoke (auth/session required); SQL read-path evidence only.

## Idempotency Check
- Final target: 250,000
- Rerun result: `Target already reached. No new patients inserted.` (~0.45 s)
- Duplicate risk: none observed; count stable at 250,000

## Laravel Log Review
- Before: empty / no prior stress-seed errors
- After: one `stress.ERROR` from a failed `artisan tinker --execute` parse attempt during measurement (agent tooling, not seeder); **no errors from `stress:seed-patients` runs**
- Fresh errors: none attributable to seeding

## Data Retention
- Stress data kept: **yes**
- Reason: preserve 250k synthetic patients on `daengtisia_stress` for future RME-history stress and read-path benchmarks

## Changes Made
- Documentation only (`docs/sprints/sprint-68-6-controlled-local-stress-data-growth-plan.md`).
- No application code, migration, or business-logic changes.

## Commands Run
```bash
graphify update .

# Prerequisite (TST branch missing on fresh stress DB)
php artisan stress:seed-foundation

# Dry runs
php artisan stress:seed-patients --target=10000 --chunk-size=1000 --dry-run
php artisan stress:seed-patients --target=10000 --chunk-size=999999 --dry-run

# Staged growth
/usr/bin/time -v php artisan stress:seed-patients --target=10000 --chunk-size=1000
/usr/bin/time -v php artisan stress:seed-patients --target=50000 --chunk-size=1000
/usr/bin/time -v php artisan stress:seed-patients --target=100000 --chunk-size=1000
/usr/bin/time -v php artisan stress:seed-patients --target=250000 --chunk-size=1000

# Idempotency
php artisan stress:seed-patients --target=250000 --chunk-size=1000

# Post-insert stats (via Laravel DB, no password echo)
php /tmp/sprint68-6-measure.php
ANALYZE mst_patients;  # via Laravel DB::statement
php /tmp/sprint68-6-explain.php

git diff --check
```

## Risks / Deferred
- Per-stage DB size not sampled between stages 1–3 (only final 129 MB captured).
- Prefix search at 250k still fast but uses PK scan + filter; at 500k+ may warrant `medical_record_number` prefix index or repository query review.
- Stage 4 (250k) completed safely; **500k+ not attempted** without explicit approval.
- No RME visit/history stress data yet — patient master only.

## Recommendation for Sprint 68.7
**Sprint 68.7 — RME History Stress Seeder Foundation**

Rationale: patient insert path is proven fast and memory-flat to 250k (~28 s total seed time, ~129 MB DB). Read-path SQL at 250k is sub-millisecond. Next bottleneck is likely visit/RME/receivable aggregates, not patient seeding. Proceed with controlled `stress:seed-rme-history` staging on the retained 250k patient base before any patient-list UI optimization.

## Final Status
- DONE / COMMITTED / PUSHED / PR CREATED / MERGED / GO-TAGGED / NO DEPLOY
