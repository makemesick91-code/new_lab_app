# Sprint 25 Phase 25.5 — Owner Dashboard Branch Receivable Summary Table

**Branch:** `feature/sprint-25-phase-25-5-owner-dashboard-branch-receivable-summary`
**Baseline:** Sprint 25.4 commit `8574881`, tag `sprint-25-phase-25-4-owner-dashboard-pilot-review-enhancements`
**Date:** 2026-06-14
**Type:** Read-only Owner Dashboard reporting enhancement (no business logic / schema changes)

## Goal

Add a read-only **per-branch RME receivable summary table** to the Owner Dashboard so the
owner can see, at a glance, the remaining receivable balance and follow-up posture of each
active branch without opening the Kasir Piutang RME screen.

## What was added

### Service — `OwnerDashboardRmeLabKpiService::branchReceivableSummary()`

New read-only method:

```php
public function branchReceivableSummary(?int $branchId = null, ?Carbon $asOf = null): array
```

- Iterates **active branches only** (respects the selected Owner branch filter via the existing
  `resolveBranchIds()` helper — one branch when a filter is selected, all active branches otherwise).
- Active receivables are **`UNPAID` + `PARTIAL`** invoices only (DRAFT/PAID/VOID excluded), reusing
  the existing `rmeReceivableQuery()` helper.
- Remaining balance per invoice = `grand_total - sum(payments.amount)`, **floored at 0**
  (`max(0, round(...))`), aggregated with `withSum('payments', 'amount')` to avoid N+1.
- Returns per branch: `branch_id`, `branch_name`, `receivable_total_remaining`, `partial_count`,
  `unpaid_count`, and follow-up posture counts (`follow_up_overdue_count`, `follow_up_today_count`,
  `follow_up_scheduled_count`, `never_followed_up_count`) — reusing the same `latestFollowUp` /
  `whereDoesntHave('followUps')` patterns already used by `metrics()`.
- **No records are created** — pure aggregate reads.

### Controller — `HomeDashboardController::index()`

- Computes `$ownerRmeLabBranchReceivableSummary` via the new service method using the already
  resolved selected branch id, and passes it to the `dashboard` view.
- Only computed inside the existing `shouldLoadOwnerRmeLabPilot()` guard (owner-dashboard users,
  not branch operational dashboard users).

### View — `resources/views/dashboard.blade.php`

- New **"Ringkasan Piutang per Cabang"** table rendered inside the existing Owner RME/Lab pilot
  section (immediately before the "Funnel RME ke Lab" card).
- Columns (Indonesian labels): **Cabang**, **Sisa Piutang** (`format_currency_id`),
  **Invoice Cicilan** (PARTIAL count), **Invoice Belum Dibayar** (UNPAID count),
  **Tindak Lanjut** (overdue / today / scheduled / never counts), **Aksi**.
- The **"Lihat Piutang"** action links to `rme.cashier.receivables` filtered by `branch_id`, and is
  gated on the `rme_receivables` drilldown (i.e. `manage_rme_billing`). Users without that
  permission see `—`, preserving the existing invariant that the receivables URL is never exposed
  to users lacking `manage_rme_billing`.
- Safe empty state when there are no active branches.

## Tests

Extended `tests/Feature/Dashboard/OwnerDashboardRmeLabKpiTest.php`:

1. `shows owner dashboard branch receivable summary table with Indonesian labels` — table + labels render.
2. `aggregates branch receivable remaining from UNPAID and PARTIAL invoices and excludes PAID` — 400k unpaid + (1000k − 300k) partial = 1.1M; PAID excluded; partial/unpaid counts.
3. `limits branch receivable summary to the selected branch` — selected branch returns only that branch; no filter returns all active branches with correct per-branch totals.
4. `shows Lihat Piutang branch action only when user can manage RME billing` — Owner (no `manage_rme_billing`) sees the table but not the action; an owner-dashboard user with `manage_rme_billing` sees the branch-filtered `Lihat Piutang` link.
5. `does not show branch receivable summary table for non-owner dashboard users` — branch-admin dashboard user does not see the table.
6. `does not create operational records when branch receivable summary is computed` — invoice/payment counts unchanged after computing the summary and loading the dashboard.

### Results

- `OwnerDashboardRmeLabKpiTest` — **17 passed (114 assertions)**
- `OwnerDashboardBranchFilterDrilldownTest` — **13 passed (63 assertions)**
- `OwnerDashboardReceivableFollowUpKpiTest` — **8 passed (18 assertions)**
- `./vendor/bin/pint --dirty` — passed
- `git diff --check` — clean
- `php artisan view:cache` — compiled successfully

## Constraints respected

- No payment logic changed.
- No RME invoice creation / payment / follow-up store logic changed.
- No migrations added.
- No scheduler / WhatsApp / external integration added.
- No VPS deployment.
- Full test suite not run (targeted filters only, per scope).
- Read-only: dashboard load creates no operational records.
- Sprint 20 full-payment-only baseline and prior invariants untouched.

## Files changed

- `app/Modules/Reporting/Services/OwnerDashboardRmeLabKpiService.php` — new `branchReceivableSummary()` method.
- `app/Http/Controllers/HomeDashboardController.php` — compute + pass `$ownerRmeLabBranchReceivableSummary`.
- `resources/views/dashboard.blade.php` — new "Ringkasan Piutang per Cabang" table.
- `tests/Feature/Dashboard/OwnerDashboardRmeLabKpiTest.php` — 6 new test cases.
- `docs/pilot_feedback_backlog.md` — ODE-001 marked IMPLEMENTED (Sprint 25.5).
- `docs/sprint_25_phase_25_5_owner_dashboard_branch_receivable_summary.md` — this doc.

## Commit / tag recommendation

- Commit message: `Add Owner Dashboard branch receivable summary table (Sprint 25.5)`
- Tag: `sprint-25-phase-25-5-owner-dashboard-branch-receivable-summary`
