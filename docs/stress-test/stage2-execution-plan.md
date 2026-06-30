# Stage 2 — Medium Local Stress Scale

**Local only. No VPS deploy.**

## Preconditions (verified)

- Branch: `test/sprint-67-2-local-stress-stage-2`
- `APP_ENV=stress`, `DB_DATABASE=daengtisia_stress`, `APP_URL=http://127.0.0.1:8008`
- Stage 1 baseline: 50k patients, 150k visits/invoices/MR, 450k items, 135k payments

## Targets

| Entity | Stage 1 | Stage 2 target | Delta |
|--------|---------|----------------|-------|
| Patients | 50,000 | 100,000 | +50,000 |
| Visits / MR / Invoices | 150,000 | 300,000 | +150,000 |
| Invoice items | 450,000 | 900,000 | +450,000 |
| Payments / lab candidates | 135,000 | ~270,000 | proportional |
| Active receivables | ~45,000 | ~90,000 | proportional |

## Execution order

1. `php artisan stress:seed-patients --env=stress --target=100000`
2. `php artisan stress:seed-rme-history --env=stress --patients=100000 --visits=300000`
3. `VACUUM ANALYZE` on `daengtisia_stress`
4. Record DB size, top tables, key indexes
5. Benchmark RME pages (5 runs each, min/avg/max/p95)
6. Save evidence under `docs/stress-test/results/stage2-*`
7. Document bottlenecks; patch only if clearly safe

## Benchmark pages

- `/rme/dashboard`
- `/rme/visits`
- `/rme/visits/{latest}`
- `/rme/visits/{latest}/medical-record`
- `/rme/visits/{latest}/odontogram`
- `/rme/cashier`
- `/rme/cashier/receivables`
- `/rme/reports/patients`
- `/rme/reports/payments`

## Success criteria

All main RME pages under 1s avg, or bottlenecks documented with EXPLAIN evidence.
