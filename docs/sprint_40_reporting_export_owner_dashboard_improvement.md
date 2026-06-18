# Sprint 40 — Reporting, Export & Owner Dashboard Improvement

Status: Draft / Local Validation Pending
Baseline: Sprint 39 GO at 1097d98
Scope: Controlled reporting/export/owner dashboard improvement / local implementation / targeted regression only

## Purpose

Sprint 40 follows Sprint 39 Cashier, Payment & Receivable Improvement Batch 1.
It implements controlled, small, and reversible reporting/export and owner dashboard
improvements on top of the existing reporting infrastructure.

This sprint focuses on:

- reporting overview clarity
- export consistency
- owner/admin dashboard KPI visibility
- receivable/payment reporting continuity
- privacy protection (KTP must not leak into dashboard/report/export views)
- targeted regression coverage

It does **not** deploy and does **not** touch production/VPS. All work is local and
test-backed. No financial calculation was rewritten — existing payment/receivable
logic is reused as-is.

## Baseline references

```
Sprint 38 GO: sprint-38-rme-workflow-improvement-batch-1-go at 253f025
Sprint 39 GO: sprint-39-cashier-payment-receivable-improvement-batch-1-go at 1097d98
```

Sprint 39 feature reference:

```
Sprint 39 feature commit: da34959
```

## Implemented scope summary

### Reporting overview clarity

The Owner Dashboard already separates visit/RME activity, payment/cashier activity,
and receivable/piutang activity into distinct KPI groups and per-branch summary
tables. Sprint 40 adds a clarifying caption to the "Ringkasan Piutang per Cabang"
section so owners understand that:

- active receivables exclude fully-paid (zero-remaining) invoices, and
- piutang follow-up is performed **manually** by operators (no automatic WhatsApp send).

Date range / filter context (branch filter) and export/print availability remain
intact. No financial calculation was changed.

### Export consistency

Existing export/print routes are reused and remain discoverable:

- RME patient/payment reports: `rme.reports.patients(.export/.print)`,
  `rme.reports.payments(.export/.print)`
- Cashier receivable export: `rme.cashier.receivables.export`
- Lab reporting exports under `App\Modules\Reporting\Controllers\ExportReportController`
- RME visit PDF: `rme.visits.pdf` (existing `barryvdh/laravel-dompdf`)

No new export/PDF package was installed. No new export infrastructure was introduced.
No external service is called. Any export format not supported by existing
architecture is treated as **deferred**, not added as a risky dependency.

### Owner/admin dashboard KPI visibility

The Owner Dashboard already surfaces visit counts, cashier-pending counts,
unpaid invoice counts, RME→Lab candidate counts, receivable remaining totals, and
follow-up KPI cards (overdue / today / scheduled / never-followed-up) via
`OwnerDashboardRmeLabKpiService`. Sprint 40 keeps these intact, branch-aware via the
existing Owner branch filter, and adds regression coverage for their visibility.

### Receivable/payment reporting continuity

Sprint 39 receivable/payment clarity flows into the dashboard. Active receivables are
`UNPAID + PARTIAL` invoices only (DRAFT/PAID/VOID excluded), and remaining balance is
floored at 0. This zero-remaining exclusion is preserved and now covered by a
dashboard-level regression test. The overpayment guard from earlier sprints remains
untouched.

### WA manual follow-up context

WhatsApp follow-up remains **manual only**. The dashboard now explicitly states that
follow-up is performed manually without automatic WhatsApp send. No WhatsApp message
is sent and no WhatsApp automation was added.

### KTP / privacy protection

`ktp_number` does not appear in the owner dashboard, reporting views, RME report
views, or export output. A regression test asserts the rendered Owner Dashboard does
not expose KTP. KTP remains only in approved admin-only master-data contexts.

### Zero-remaining receivable exclusion result

Preserved. Fully-paid (zero-remaining) invoices are excluded from active receivable
counts and remaining totals. Regression test added at the dashboard service level.

### Permission/authorization result

Owner Dashboard access continues to require `view_owner_dashboard` or `manage_report`
(branch-operational users are routed to the branch dashboard instead). Existing
report/export route permissions are unchanged. A regression test confirms an
unauthorized user cannot see Owner Dashboard KPI content.

### Tests added/updated

- `tests/Feature/Sprint40/Sprint40ReportingExportOwnerDashboardImprovementTest.php`
  (documentation + functional regression checklist)

### Migration added

None. No schema change was required.

### Deferred items

- New export formats beyond existing CSV/PDF infrastructure (would require new
  dependency) — deferred.
- WhatsApp reminder automation/send — deferred to Sprint 41 (manual only for now).

## Safety boundaries

- no production/VPS access
- no deployment
- no production migration
- no external WhatsApp send
- no WhatsApp automation
- no new export/PDF dependency
- no risky financial calculation rewrite
- no signature upload/capture integration
- no backup/restore/rollback execution
- no destructive operation
- no `.env` change
- no dependency/package install
- no GO tag

## Validation commands

```bash
php artisan test --filter=Sprint40ReportingExportOwnerDashboardImprovement
php artisan test --filter=Dashboard
php artisan test --filter=Reporting
php artisan test --filter=RmeReportExport
vendor/bin/pint --test
git diff --check
```

## PR readiness marker

GO CANDIDATE FOR PR REVIEW

## Next sprint recommendation

Sprint 41 — WhatsApp Manual Reminder Operationalization & Follow-up Workflow

Sprint 41 should focus on a controlled manual reminder workflow: follow-up logging,
reminder templates, and operator SOP for piutang follow-up. It must keep an explicit
no-automation / no-send boundary unless automated WhatsApp sending is separately and
explicitly approved later.
