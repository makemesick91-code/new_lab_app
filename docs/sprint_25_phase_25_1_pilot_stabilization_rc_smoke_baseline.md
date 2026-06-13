# Sprint 25 Phase 25.1 — Pilot Stabilization / RC Smoke Baseline

## Goal

Sprint 25 Phase 25.1 establishes a pilot stabilization baseline after Sprint 24 Release Candidate.

This phase does not add new product features. It verifies the Sprint 24 RC baseline locally and on VPS before continuing Sprint 25 work.

## Baseline Context

- Sprint 24 closure commit: `749c6ef`
- Sprint 24 RC tag: `sprint-24-release-candidate`
- Local Sprint 25.1 branch: `feature/sprint-25-phase-25-1-pilot-stabilization-rc-smoke-baseline`
- VPS functional code baseline: `ea17ce4`
- VPS branch: `feature/sprint-24-phase-24-10-owner-dashboard-receivable-follow-up-kpi`

## Local RC Gate

| Check | Result |
|---|---|
| `CashierBillingTest` | PASS — 28 tests / 74 assertions |
| `RmeReceivableFollowUpTest` | PASS — 9 tests / 18 assertions |
| `OwnerDashboardReceivableFollowUpKpiTest` | PASS — 8 tests / 18 assertions |
| `OwnerDashboardRmeLabKpiTest` | PASS — 11 tests / 83 assertions |
| `OwnerDashboardBranchFilterDrilldownTest` | PASS — 13 tests / 63 assertions |
| Dashboard route check | PASS |
| RME receivables route check | PASS |
| RME follow-up route check | PASS |
| `php artisan view:cache` | PASS |
| `./vendor/bin/pint --dirty` | PASS |
| `git diff --check` | PASS |

## VPS Baseline Check

| Check | Result |
|---|---|
| VPS app path `/var/www/asia-dental-lab-v2` accessible | PASS |
| VPS branch is functional Sprint 24.10 baseline | PASS |
| VPS HEAD is `ea17ce4` | PASS |
| `trx_rme_receivable_follow_ups` migration | PASS — Ran |
| `php artisan optimize:clear` | PASS |
| `php artisan config:cache` | PASS |
| `php artisan route:cache` | PASS |
| `php artisan view:clear` | PASS |
| `php artisan view:cache` | PASS |
| Dashboard route registered | PASS |
| RME receivables routes registered | PASS |
| RME follow-up routes registered | PASS |
| `php8.3-fpm` active/running | PASS |
| `nginx` active/running | PASS |
| Laravel log cleared before smoke | PASS |

## Manual Browser Smoke Baseline

| Check | Result |
|---|---|
| Login works | PASS |
| Dashboard opens | PASS |
| Owner Dashboard KPI visible | PASS |
| Owner Dashboard RME receivable KPI visible | PASS |
| Owner Dashboard follow-up KPI visible | PASS |
| Branch filter works on dashboard | PASS |
| Piutang RME page opens | PASS |
| Piutang RME aging cards visible | PASS |
| Piutang RME follow-up columns visible | PASS |
| Piutang RME search filter works | PASS |
| Piutang RME branch filter works | PASS |
| Piutang RME status filter works | PASS |
| Piutang RME aging filter works | PASS |
| Piutang RME `follow_up_filter` works | PASS |
| Piutang RME CSV export works | PASS |
| Tambah Follow-up form opens | PASS |
| Follow-up submit works on UNPAID/PARTIAL invoice | N/A — not re-submitted in this baseline smoke |
| Latest follow-up appears after submit | N/A — not re-submitted in this baseline smoke |
| RME cashier/payment page opens | PASS |
| Partial payment flow still accessible | PASS |
| No payment logic regression observed | PASS |
| Laravel log after smoke | CLEAN |

## Notes

- Sprint 25.1 is a stabilization baseline, not a feature sprint.
- No payment logic was changed.
- No follow-up logic was changed.
- No dashboard logic was changed.
- No WhatsApp sending was added.
- No scheduler or external reminder service was added.
- No full test suite was run; targeted Sprint 24 regression coverage was used.
- VPS does not need Sprint 24.12 docs-only closure commit because functional code baseline is Sprint 24.10 and already smoke-tested through Sprint 24.11.

## Decision

Decision: GO.

Sprint 24 Release Candidate is stable enough to be used as the Sprint 25 pilot stabilization baseline.
