# Sprint 24 Phase 24.4 — Owner Dashboard RME Receivable KPI Integration

## Goal

Surface RME receivable (Piutang RME) KPIs on the Owner Dashboard so the owner
can monitor outstanding RME balances alongside the existing pilot RME/Lab
monitoring metrics. Read-only — no payment posting, schema, or export changes.

## Scope

- Add three receivable metrics to `OwnerDashboardRmeLabKpiService::metrics()`.
- Add a permission-aware `rme_receivables` drilldown link.
- Render three new KPI cards plus a "Piutang RME" shortcut on the dashboard.
- Extend focused dashboard tests.

## KPI keys

| Key | Meaning |
| --- | --- |
| `rme_receivable_total_remaining` | Sum of remaining balance for active receivable invoices. Remaining = `grand_total - sum(payments.amount)`, floored at 0. Includes only `UNPAID` + `PARTIAL`; excludes `PAID`, `VOID`, `DRAFT`. |
| `rme_receivable_partial_count` | Count of invoices with status `PARTIAL`. |
| `rme_receivable_unpaid_count` | Count of invoices with status `UNPAID`. |

Computation uses a single `withSum('payments', 'amount')` aggregate query over
the receivable invoices (no per-invoice N+1).

## Branch-aware behavior

The receivable KPIs reuse the existing `rmeInvoiceQuery($branchIds)` branch
filter, identical to the other Owner RME KPIs:

- Owner selects a branch → counts only that branch.
- No branch selected (or invalid) → aggregates across all active branches.

## Drilldown

`OwnerDashboardRmeLabDrilldownService::linksFor()` adds
`rme_receivables => route('rme.cashier.receivables')` inside the existing
`manage_rme_billing` permission block. Link/shortcut is only shown to users
with `manage_rme_billing`.

## Dashboard labels added

- `Sisa Piutang RME` (formatted currency for `rme_receivable_total_remaining`)
- `Invoice Cicilan` (`rme_receivable_partial_count`)
- `Invoice Belum Dibayar` (`rme_receivable_unpaid_count`)
- `Piutang RME` (shortcut button to `rme.cashier.receivables`, permission-gated)

## Files changed

- `app/Modules/Reporting/Services/OwnerDashboardRmeLabKpiService.php`
- `app/Modules/Reporting/Services/OwnerDashboardRmeLabDrilldownService.php`
- `resources/views/dashboard.blade.php`
- `tests/Feature/Dashboard/OwnerDashboardRmeLabKpiTest.php`
- `tests/Feature/Dashboard/OwnerDashboardBranchFilterDrilldownTest.php`
- `docs/sprint_24_phase_24_4_owner_dashboard_rme_receivable_kpi.md` (new)

## Validation commands

```bash
php artisan test --filter=OwnerDashboardRmeLabKpiTest
php artisan test --filter=OwnerDashboardBranchFilterDrilldownTest
php artisan route:list | grep "rme.cashier.receivables"
php artisan view:clear
php artisan view:cache
./vendor/bin/pint --dirty
```

## Out of scope

- No migration / schema change.
- No payment posting logic change.
- No export feature.
- No unrelated refactor.
