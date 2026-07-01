# Sprint 68.7 — RME History Stress Seeder Foundation

## Scope
- Local/stress RME history seeder foundation.
- Synthetic data only.
- No VPS deploy.
- No production/pilot data.

## Environment
| Item | Value |
|---|---|
| Branch | `feature/sprint-68-7-rme-history-stress-seeder-foundation` |
| Commit before work | `36e92de` |
| APP_ENV (stress smoke) | `stress` |
| DB_CONNECTION | `pgsql` |
| DB_DATABASE | `daengtisia_stress` |
| PHP | 8.5.4 |
| Laravel | 12.61.0 |

## Problem
- Patient master stress data exists (Sprint 68.5–68.6); RME visit/history data is needed to reveal transaction read-path bottlenecks.
- Existing `stress:seed-rme-history` lacked Sprint 68.5 parity: broad env guard, `--target`/`--chunk-size`, dry-run, parameter-safe chunking, ratio options, resume semantics, progress output, and tests.
- FK bug: `recorded_by`/`finalized_by`/`created_by` on medical records must reference `users.id`, not `mst_doctors.id`.
- Queue collision: `(branch_id, visit_date, queue_number)` unique index caused silent skips when queue recycled within the same date cycle.

## Command Behavior
| Feature | Behavior |
|---|---|
| Command | `php artisan stress:seed-rme-history` |
| Target semantics | `--target` = total stress visits by max seq (`TST-VIS-2026-{9-digit}`); `--visits` kept as deprecated alias |
| Chunk safety | `min(requested, floor(60000/visitCols), floor(60000/(itemCols×3)))`; default 1000, clamped with warning |
| Dry-run | Validates plan, prints effective chunk; writes zero rows |
| Environment guard | Allowed: `local`, `stress`, `testing`; blocked: `pilot`, `production` |
| Idempotency key/strategy | Deterministic `visit_number`, `invoice_number`, `payment_number`; resume via max seq not row count |
| Ratio behavior | `--paid-ratio` / `--partial-ratio` / `--unpaid-ratio`; normalized to 100 with warning if needed |
| Foundation dependency | Requires `stress:seed-foundation` + `stress:seed-patients` on target branch |

## Generated Tables
| Table | Generated? | Strategy | Notes |
|---|---|---|---|
| trx_clinic_visits | Yes | `insertOrIgnore` by `visit_number` | Status `completed` or `cashier_pending` (UNPAID) |
| trx_medical_records | Yes | per visit, `insertOrIgnore` | Synthetic SOAP/notes only; user FK for recorded/finalized |
| trx_rme_invoices | Yes | per visit | PAID / PARTIAL / UNPAID by ratio |
| trx_rme_invoice_items | Yes | 3 items per invoice | Verified branch tariffs |
| trx_rme_payments | Yes | PAID + PARTIAL only | Amount matches status semantics |
| trx_rme_receivable_follow_ups | Yes | ~20% of receivables | Synthetic internal notes only |
| trx_medical_record_handwriting_pages | Yes | placeholder path/hash | No real PNG files |
| trx_odontograms | Yes | synthetic JSON payload | No real scans |
| trx_rme_patient_doctor_assignments | Yes | auto_visit dummy | |
| trx_lab_case_candidates | Yes | lab treatments only | Staging records, not LabOrder |

## Smoke Results
| Command | Result |
|---|---|
| dry-run target 100 | PASS — effective chunk 25, 0 rows written |
| seed target 100 | PASS — 100 visits inserted in ~0.36s |
| rerun target 100 | PASS — "Target already reached" (idempotent) |
| optional target 1000 | PARTIAL — 730 visits after first run (270 skipped due to pre-fix queue collision); resume semantics + queue fix added in sprint |

## Counts After Smoke
| Table | Count |
|---|---:|
| mst_patients | 200 |
| trx_clinic_visits | 730 |
| trx_medical_records | 730 |
| trx_rme_invoices | 730 |
| trx_rme_payments | 660 |

## Query Evidence
| Query | Plan Summary | Runtime | Buffers | Decision |
|---|---|---:|---|---|
| TST visits ORDER BY visit_date DESC LIMIT 50 | Index Scan Backward on `trx_clinic_visits_branch_date_queue_unique` + Incremental Sort | 0.130 ms | shared hit=64 | Acceptable at 730 rows |
| TST receivables UNPAID/PARTIAL LIMIT 50 | Index Scan Backward on PK + filter | 0.088 ms | shared hit=7 | Acceptable at 730 rows |

## Tests
```bash
./vendor/bin/pint --dirty
php artisan test --filter=StressSeedRmeHistoryCommandTest
php artisan test --filter=StressSeedPatientsCommandTest
php artisan list | grep -E "stress:seed|stress"
git diff --check
graphify update .
```

Result: **8 passed** (StressSeedRmeHistoryCommandTest), **6 passed** (StressSeedPatientsCommandTest).

## Changes Made
- Stabilized `app/Console/Commands/StressSeedRmeHistoryCommand.php` with env guard, `--target`, `--chunk-size`, dry-run, ratios, date range, force gate, parameter-safe chunking, max-seq resume, progress output, user FK fix, unique queue_number per seq.
- Added `tests/Feature/Console/StressSeedRmeHistoryCommandTest.php` (8 tests).

## Safety Confirmation
- No deploy.
- No VPS SSH.
- No migration.
- No destructive DB command.
- No business logic changed.
- No real PII/KTP/NIK exposed.
- No `.env`/backup/SSH key/DB dump committed.

## Deferred
- Large RME history growth 10k / 50k / 100k on retained 250k patient base.
- Re-seed stress DB to 250k patients if local DB was reset (only 200 patients seeded for this sprint smoke).
- Deeper receivable/follow-up distribution tuning.

## Recommendation for Sprint 68.8
- **Sprint 68.8 — Controlled RME History Stress Data Growth**: staged growth (1k → 10k → 50k visits) on 250k patient base; benchmark RME read paths (`stress:benchmark-rme-pages`, receivables, visit lists); retain smoke data between stages.
