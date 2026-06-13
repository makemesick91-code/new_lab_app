# Sprint 24 Phase 24.10 — Owner Dashboard Receivable Follow-up KPI Integration

## Goal

Integrate RME receivable follow-up / reminder KPIs into the Owner Dashboard so the
owner can see actionable follow-up indicators for active RME receivables, reusing
the Sprint 24.8 follow-up data and the Sprint 24.3–24.6 Piutang RME foundation.

## Context from Sprint 24.8–24.9

- Sprint 24.8 added the `trx_rme_receivable_follow_ups` table, the
  `RmeReceivableFollowUp` model, the follow-up create/store routes, and the
  latest-follow-up / next-follow-up indicators on the Piutang RME page.
- Sprint 24.9 validated 24.8 on the VPS pilot (migration, form, store, indicators,
  clean log).
- Sprint 24.4 already added the Owner Dashboard RME receivable KPIs
  (`rme_receivable_total_remaining`, `rme_receivable_partial_count`,
  `rme_receivable_unpaid_count`) and a shortcut to `rme.cashier.receivables`.

This phase builds on that foundation. No migration was needed.

## Added KPI keys

Emitted by `OwnerDashboardRmeLabKpiService::metrics()`:

- `rme_receivable_follow_up_overdue_count`
- `rme_receivable_follow_up_today_count`
- `rme_receivable_never_followed_up_count`
- `rme_receivable_follow_up_scheduled_count`
- `rme_receivable_follow_up_escalated_count` (optional, included)

All counts are over **active receivables only** — invoices with status `UNPAID`
or `PARTIAL`. `PAID`, `VOID`, and `DRAFT` are excluded. Due calculations use the
latest follow-up per invoice (`RmeInvoice::latestFollowUp()`, `latestOfMany`).
Counts are of invoices, not follow-up rows.

| Key | Definition |
| --- | --- |
| overdue | latest follow-up `next_follow_up_date < today` |
| today | latest follow-up `next_follow_up_date = today` |
| scheduled | latest follow-up `next_follow_up_date > today` |
| never | no follow-up history (`whereDoesntHave('followUps')`) |
| escalated | latest follow-up `status = ESCALATED` |

## UI labels

Cards added to the existing "Monitoring Pilot RME & Lab" section
(`resources/views/dashboard.blade.php`):

- **Follow-up Jatuh Tempo**
- **Follow-up Hari Ini**
- **Belum Pernah Follow-up**
- **Follow-up Terjadwal**

Each card shows the count and (when the user has billing access) a "Lihat detail"
shortcut to the Piutang RME page with the matching `follow_up_filter`.

## Branch-aware behavior

Counts reuse the existing `$branchIds` resolution in
`OwnerDashboardRmeLabKpiService`. With no branch selected they aggregate across all
active branches; with the Owner branch filter they scope to that branch. Verified
by the branch-filter test case.

## Permission behavior

- The KPI cards render inside the existing Owner Dashboard section
  (`view_owner_dashboard`).
- The follow-up drilldown shortcut links are gated by `manage_rme_billing` in
  `OwnerDashboardRmeLabDrilldownService::linksFor()`, mirroring the existing
  `rme_receivables` shortcut. An Owner without billing access sees the cards but no
  detail link (no behavior change vs. the existing receivable shortcut).

## Optional `follow_up_filter` behavior (implemented)

`RmeInvoiceController@receivables` (and the shared `receivableQuery`) accept a
`follow_up_filter` query parameter with allowed values `overdue`, `today`, `never`,
`scheduled`, `escalated`. Invalid values are ignored. The filter is applied via
`applyFollowUpFilter()` and composes with the existing search / branch / status /
aging-bucket filters. Because `exportReceivables` reuses the same `receivableQuery`,
CSV export automatically respects the filter when present. No new CSV columns were
added.

## Test results

New file `tests/Feature/Dashboard/OwnerDashboardReceivableFollowUpKpiTest.php` — 8 passed:

- shows the follow-up KPI labels on the Owner Dashboard
- overdue / today / scheduled / never counts (active receivables only)
- PAID invoices excluded from follow-up KPI counts
- branch filter scopes follow-up KPI counts
- follow-up shortcuts point to the Piutang RME route with filters

Regression checks (targeted): `OwnerDashboardRmeLabKpiTest` (11), 
`OwnerDashboardBranchFilterDrilldownTest` (13), `RmeReceivableFollowUpTest` (9),
`CashierBillingTest` (28) — all passed.

Note: the `RmeReceivableFollowUpFactory` default was made lazy (it no longer creates
a stray unpaid invoice when `rme_invoice_id` is overridden) so KPI counts are not
polluted by orphan invoices. This is test infrastructure only.

## Out of scope

- No WhatsApp sending.
- No scheduler / cron.
- No external reminder / notification service.
- No payment posting or invoice status transition changes.
- No follow-up store logic changes.
- No VPS deploy.
- No full test suite run.
- No migration (none required).
