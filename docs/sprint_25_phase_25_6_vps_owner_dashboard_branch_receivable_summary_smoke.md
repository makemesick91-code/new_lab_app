# Sprint 25 Phase 25.6 — VPS Deploy + Owner Dashboard Branch Receivable Summary Smoke

## Goal

Deploy Sprint 25.5 to VPS and smoke test the Owner Dashboard Branch Receivable Summary Table.

## Baseline

- Previous phase commit: `f87b3d5`
- Previous phase tag: `sprint-25-phase-25-5-owner-dashboard-branch-receivable-summary`
- Deployed branch: `feature/sprint-25-phase-25-5-owner-dashboard-branch-receivable-summary`
- VPS app path: `/var/www/asia-dental-lab-v2`
- VPS IP: `145.79.13.224`

## VPS Deploy Result

| Check | Result |
|---|---|
| Before deploy branch | `feature/sprint-24-phase-24-10-owner-dashboard-receivable-follow-up-kpi` |
| After deploy branch | `feature/sprint-25-phase-25-5-owner-dashboard-branch-receivable-summary` |
| VPS HEAD | `f87b3d5` |
| `git pull --ff-only` | PASS |
| `php artisan optimize:clear` | PASS |
| `php artisan config:cache` | PASS |
| `php artisan route:cache` | PASS |
| `php artisan view:clear` | PASS |
| `php artisan view:cache` | PASS |
| Dashboard route | PASS |
| RME receivables route | PASS |
| `php8.3-fpm` | active |
| `nginx` | active |
| Laravel log cleared before smoke | PASS |

## Manual Browser Smoke

| Check | Result |
|---|---|
| Login as Owner | PASS |
| Dashboard opens | PASS |
| Monitoring Pilot RME & Lab section visible | PASS |
| Ringkasan Piutang per Cabang table visible | PASS |
| Column Cabang visible | PASS |
| Column Sisa Piutang visible | PASS |
| Column Invoice Cicilan visible | PASS |
| Column Invoice Belum Dibayar visible | PASS |
| Column Tindak Lanjut visible | PASS |
| Column Lihat Piutang/action visible or safely hidden by permission | PASS |
| Cabang Antang row visible or safe empty state | PASS |
| Cabang Landak row visible or safe empty state | PASS |
| Cabang Telkomas row visible or safe empty state | PASS |
| Owner branch filter Semua Cabang works | PASS |
| Owner branch filter Cabang Antang works | PASS |
| Owner branch filter Cabang Landak works | PASS |
| Receivable amount/counts look reasonable | PASS |
| Plain Owner without `manage_rme_billing` does not see forbidden Piutang link | N/A |
| Owner with billing permission can open Lihat Piutang filtered by branch | PASS |
| No payment logic regression observed | PASS |
| No follow-up mutation observed | PASS |

## Laravel Log After Smoke

| Check | Result |
|---|---|
| `tail -100 storage/logs/laravel.log` | CLEAN |
| Error scan | CLEAN |

## Decision

Decision: GO.

Sprint 25.5 Owner Dashboard Branch Receivable Summary Table is successfully deployed and smoke-tested on VPS.

## Constraints

- No migration run.
- No payment logic changed.
- No follow-up mutation logic changed.
- No scheduler/cron added.
- No WhatsApp/external integration added.
- No full test suite run on VPS.
