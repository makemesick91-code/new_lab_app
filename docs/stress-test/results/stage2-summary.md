# Stage 2 — Medium Local Stress Results

**Local only. No VPS deploy.**

## Environment

- Branch: `test/sprint-67-2-local-stress-stage-2`
- Base: `8d298c4` (Sprint 67.1 merge)
- `APP_ENV=stress`, `DB_DATABASE=daengtisia_stress`
- `APP_URL=http://127.0.0.1:8008`
- Date: 2026-06-30

## Dataset (2× Stage 1)

| Table | Count |
|-------|------:|
| mst_patients | 100,000 |
| trx_clinic_visits | 300,000 |
| trx_medical_records | 300,000 |
| trx_rme_invoices | 300,000 |
| trx_rme_invoice_items | 900,000 |
| trx_rme_payments | 270,000 |
| trx_lab_case_candidates | 270,000 |
| trx_rme_receivable_follow_ups | 60,000 |

Seeding: incremental `stress:seed-patients --target=100000` + `stress:seed-rme-history --patients=100000 --visits=300000` (~3.8 min).

## Benchmark (5 runs, stress.admin001, TST branch)

| Page | HTTP | min | avg | max | p95 | Under 1s? |
|------|-----:|----:|----:|----:|----:|:---------:|
| rme_dashboard | 200 | 0.041 | 0.045 | 0.047 | 0.047 | ✅ |
| rme_visits | 200 | 0.040 | 0.047 | 0.052 | 0.052 | ✅ |
| rme_visit_show | 200 | 0.043 | 0.045 | 0.046 | 0.046 | ✅ |
| rme_medical_record | 200 | 0.048 | 0.060 | 0.077 | 0.077 | ✅ |
| rme_odontogram | 200 | 0.043 | 0.045 | 0.046 | 0.046 | ✅ |
| rme_cashier | 200 | 0.043 | 0.048 | 0.054 | 0.054 | ✅ |
| rme_receivables | 200 | 0.041 | 0.043 | 0.046 | 0.046 | ✅ |
| rme_reports_patients | 200 | 0.039 | 0.041 | 0.046 | 0.046 | ✅ |
| rme_reports_payments | 200 | 0.039 | 0.047 | 0.054 | 0.054 | ✅ |

Raw: `docs/stress-test/results/stage2-auth-pages-benchmark.txt`

## vs Stage 1 (50k / 150k)

| Page | Stage 1 avg | Stage 2 avg | Delta |
|------|------------:|------------:|------:|
| rme_receivables | 0.628s | 0.043s | **−93%** |
| rme_dashboard | 0.202s | 0.045s | −78% |
| rme_visits | 0.105s | 0.047s | −55% |
| rme_reports_payments | 0.177s | 0.047s | −73% |

Stage 2 is faster than Stage 1 on all measured pages (post-VACUUM ANALYZE + warm cache).

## Bottlenecks

**None above 1s at Stage 2 scale.** No code patch required for this stage.

Sprint 67.1 receivables aggregate + index migration holds at 2× data.

## Next

- **Stage 3**: scale to 250k patients / 1M visits; re-benchmark; patch only if >1s with EXPLAIN evidence.
- **Stage 4**: light concurrency smoke (5 users / 50 reqs).
- **Stage 5**: consolidate final report.

## Tooling added

- `php artisan stress:benchmark-rme-pages --env=stress` — reusable local benchmark (no cookies/passwords in output files).
