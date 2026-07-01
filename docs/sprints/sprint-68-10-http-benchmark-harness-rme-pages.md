# Sprint 68.10 — HTTP Benchmark Harness for RME Pages

## Scope
- Local/stress benchmark harness extension.
- RME pages plus Owner Dashboard KPI paths on `/dashboard`.
- No VPS deploy.
- No production/pilot data.
- No runtime business-logic changes.

## Deploy Decision
- Deploy needed: **No**.
- Reason: tooling and documentation only; benchmark command is for local/stress regression harness.

## Environment
| Item | Value |
|---|---|
| Branch | `feature/sprint-68-10-http-benchmark-harness-rme-pages` |
| Commit before work | `c6bef6d` |
| APP_ENV | `stress` (via `--env=stress` → `.env.stress`) |
| DB_CONNECTION | `pgsql` |
| DB_DATABASE | `daengtisia_stress` |
| PHP | 8.5.4 |
| Laravel | 12.61.0 |
| Dataset source | Sprint 68.8 / 68.9 rebuild (retained locally) |

## Dataset Baseline
| Table | Count |
|---|---:|
| mst_patients | 250,000 |
| trx_clinic_visits | 100,000 |
| trx_rme_invoices | 100,000 |
| trx_rme_payments | 90,000 |
| trx_rme_receivable_follow_ups | 6,000 |

DB size: **494 MB**. Disk free on `/home`: **60 GB**. Memory available: **~5.7 GiB**.

## Harness Changes
| Area | Change |
|---|---|
| Command | Extended `stress:benchmark-rme-pages` with multi-session auth, warmup, ms metrics, error counts |
| Options | `--branch-code`, `--include-owner`, `--json`, `--warmup`, `--timeout-ms`, `--dry-run`; `--runs` clamped 1–100 |
| Owner dashboard coverage | `/dashboard`, `?range=month`, `?branch_id={TST}&range=month` via `stress.owner001@daengtisia.test` |
| Auth/context strategy | RME clinical → `stress.admin001` + admin-clinic online context; cashier → `stress.cashier001` + context; owner → `stress.owner001` (no RME context) |
| Output format | Table: status, ok/err counts, avg/p50/p95/max ms, route; optional CSV via `--output` |
| JSON support | `--json` emits structured summary without response bodies |
| PII safety | No response body dump; synthetic IDs only; no KTP/NIK/names in output |

## Benchmark Targets
| Label | Method | Route/Path | Included | Notes |
|---|---|---|---|---|
| rme_patient_queue | GET | `rme.patient-queue.index` | Yes | Antrian pasien |
| rme_visits | GET | `rme.visits.index` | Yes | Visit list |
| rme_visit_show | GET | `rme.visits.show` | Yes | Visit detail / patient history context |
| rme_reports_patients | GET | `rme.reports.patients` | Yes | Patient report with `branch_id` |
| rme_receivables | GET | `rme.cashier.receivables` | Yes | Cashier session |
| rme_reports_payments | GET | `rme.reports.payments` | Yes | Payment report with `branch_id` |
| rme_dashboard | GET | `rme.dashboard` | Yes | Regression from Sprint 68.9 |
| rme_medical_record | GET | `rme.visits.medical-record.show` | Yes | Regression |
| rme_odontogram | GET | `rme.visits.odontogram.show` | Yes | Regression |
| rme_cashier | GET | `rme.cashier.index` | Yes | Regression |
| owner_dashboard | GET | `dashboard` | `--include-owner` | Owner KPI landing |
| owner_dashboard_kpi_month | GET | `dashboard?range=month` | `--include-owner` | Period aggregate path |
| owner_dashboard_branch | GET | `dashboard?branch_id={TST}&range=month` | `--include-owner` | Branch drilldown filter |

## Benchmark Results
Command: `php artisan stress:benchmark-rme-pages --env=stress --runs=3 --branch-code=TST --include-owner --warmup=1`

| Target | Status | Runs | Avg ms | P50 ms | P95 ms | Max ms | Errors | Decision |
|---|---:|---:|---:|---:|---:|---:|---:|---|
| rme_patient_queue | 200 | 3 | 43.83 | 44.40 | 45.48 | 45.48 | 0 | OK |
| rme_visits | 200 | 3 | 42.25 | 41.52 | 44.48 | 44.48 | 0 | OK |
| rme_visit_show | 200 | 3 | 42.09 | 41.43 | 44.64 | 44.64 | 0 | OK |
| rme_reports_patients | 200 | 3 | 40.48 | 40.23 | 41.35 | 41.35 | 0 | OK |
| rme_receivables | 200 | 3 | 40.30 | 40.31 | 40.79 | 40.79 | 0 | OK |
| rme_reports_payments | 200 | 3 | 43.15 | 42.32 | 45.20 | 45.20 | 0 | OK |
| rme_dashboard | 200 | 3 | 42.55 | 42.08 | 45.50 | 45.50 | 0 | OK |
| rme_medical_record | 200 | 3 | 41.50 | 40.75 | 44.28 | 44.28 | 0 | OK |
| rme_odontogram | 200 | 3 | 41.65 | 41.96 | 42.55 | 42.55 | 0 | OK |
| rme_cashier | 200 | 3 | 41.20 | 40.45 | 43.05 | 43.05 | 0 | OK |
| owner_dashboard | 200 | 3 | 40.51 | 40.77 | 41.47 | 41.47 | 0 | OK |
| owner_dashboard_kpi_month | 200 | 3 | 42.51 | 41.75 | 45.33 | 45.33 | 0 | OK |
| owner_dashboard_branch | 200 | 3 | 43.36 | 43.40 | 45.84 | 45.84 | 0 | OK |

Server: `http://127.0.0.1:8008`. Visit ID used: `100022`. Branch TST id: `9`.

## Skipped Targets
| Target | Reason |
|---|---|
| `settings.patients.index` | No dedicated patient show route; patient search covered via `rme.reports.patients` |

## Threshold Decision
| Target | Decision | Reason |
|---|---|---|
| All measured targets | **OK** | Avg & p95 &lt; 100 ms; HTTP 200; no errors |
| Owner dashboard KPI block | **OK** | ~40–43 ms avg at 100k visits — no HTTP bottleneck vs SQL Q5/Q6 WATCH (~32–36 ms) |

## Tests
```bash
./vendor/bin/pint --dirty
php artisan test --filter=StressBenchmarkRmePages
php artisan list | grep -E "stress:benchmark|benchmark-rme"
git diff --check
graphify update .
```

## Checks
```bash
php artisan stress:benchmark-rme-pages --env=stress --runs=1 --branch-code=TST --include-owner
php artisan stress:benchmark-rme-pages --env=stress --runs=3 --branch-code=TST --include-owner
php artisan stress:benchmark-rme-pages --env=stress --runs=2 --branch-code=TST --include-owner --json
php artisan stress:benchmark-rme-pages --env=stress --dry-run --include-owner
```

## Safety Confirmation
- No deploy.
- No VPS SSH.
- No migration.
- No destructive DB command.
- No business logic changed.
- No auth/permission bypass in runtime code.
- No `.env`/backup/SSH key/DB dump committed.
- No real PII/KTP/NIK exposed.

## Deferred
- HTTP benchmark on VPS/pilot (not recommended).
- `settings.patients.index` unless a dedicated RME patient list route is added.
- SQLite `RefreshDatabase` compatibility for PostgreSQL-only concurrent index migration (pre-existing; unit tests used for harness).

## Recommendation for Sprint 68.11
- **Owner Dashboard HTTP Bottleneck Review** only if payment rows exceed ~500k or owner KPI HTTP avg exceeds 500 ms.
- At current 100k visits / 90k payments, evidence shows **GO** — monitor Q5/Q6 SQL WATCH from Sprint 68.9; HTTP owner dashboard is healthy (~41 ms avg).
