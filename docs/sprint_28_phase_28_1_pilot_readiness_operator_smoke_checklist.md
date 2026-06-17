# Sprint 28 Phase 28.1 — Pilot Readiness & Operator Smoke Checklist

## Status

**Mode:** Pilot readiness / operator smoke checklist only
**Deployment:** No deployment
**Migration:** No migration
**Production code change:** No production code change
**Destructive data operation:** No destructive data operation
**Baseline:** Sprint 28.0 GO at `c36b852`

## Purpose

Sprint 28 Phase 28.1 turns the Sprint 28.0 pilot-readiness backlog (Lane A) into an
actionable operator-facing checklist.

Goals:

- Create a repeatable operational checklist for the pilot.
- Keep the Sprint 27 RME Control Workflow stable during pilot usage.
- Provide a daily/weekly checklist for operators and support/admin staff.
- Give operators a smoke routine they can run before and during a pilot day.
- Catch regressions early without changing any application behavior.

This phase is documentation/checklist only. It does not change RME, payment, receivable,
cashier, odontogram, invoice, route, service, controller, model, view, migration, or
configuration behavior.

## How to Use This Checklist

- Run the **Operator Smoke Checklist** at the start of each pilot day.
- Run the **RME Control Workflow Smoke Checklist** whenever a control/follow-up visit is handled.
- Run the **Support / Admin Checklist** daily or per shift.
- Mark each item PASS / FAIL / N/A and record notes.
- Any FAIL on a control-workflow safety item is a blocker — stop and escalate before continuing.

## Operator Smoke Checklist

| # | Check | Expected result | Result |
|---|-------|-----------------|--------|
| 1 | Login and role/menu visibility | Operator logs in; only role-appropriate menus are visible | ☐ |
| 2 | Patient search | Existing patient is found by name / RM number | ☐ |
| 3 | New patient registration smoke | A new patient can be registered with valid data | ☐ |
| 4 | Visit creation smoke | A new visit can be created for a patient | ☐ |
| 5 | RME visit input smoke | RME/handwriting input can be entered and finalized | ☐ |
| 6 | Odontogram input/print smoke | Odontogram can be entered and printed via browser print | ☐ |
| 7 | Cashier billing smoke | Cashier can open billing for a finalized RME visit | ☐ |
| 8 | Payment receipt smoke | Full payment completes and receipt prints | ☐ |
| 9 | Previous receivable / active receivable check | Previous/active receivables show correctly for the patient | ☐ |
| 10 | Report export/print smoke | Reports can be exported/printed without error | ☐ |
| 11 | Logout/session check | Operator can log out cleanly; session ends | ☐ |

## RME Control Workflow Smoke Checklist

These items protect the Sprint 27 RME Control Workflow GO behavior. A FAIL here is a blocker.

| # | Check | Expected result | Result |
|---|-------|-----------------|--------|
| 1 | Control visit uses the same patient/RM | Control reuses the same patient and RM number | ☐ |
| 2 | Control visit still creates a new visit | A distinct new visit row is created for the control | ☐ |
| 3 | Old visit / old RME / old odontogram / old invoice not overwritten | Previous visit, RME, odontogram, and invoice remain unchanged | ☐ |
| 4 | Parent receivable can appear in cashier control | Previous receivable is visible/payable from the control cashier page | ☐ |
| 5 | Payment allocation remains FIFO previous receivable first | Payment is allocated to previous receivable first, then current control invoice | ☐ |
| 6 | Parent receivable does not block control completion | Control visit can complete even with an outstanding parent receivable | ☐ |
| 7 | Rp0 invoice does not appear in active receivables | Rp0 control invoices are excluded from active receivables (history only) | ☐ |

## Support / Admin Checklist

| # | Check | Expected result | Result |
|---|-------|-----------------|--------|
| 1 | Laravel log check | `storage/logs/laravel.log` has no new unexpected errors | ☐ |
| 2 | Backup presence check | Latest DB/file backup exists and is recent | ☐ |
| 3 | Disk usage check | Disk usage is within safe limits; no near-full warning | ☐ |
| 4 | Route/menu quick check | Key routes/menus load without error for each role | ☐ |
| 5 | User/role quick check | Active users have correct roles/permissions | ☐ |
| 6 | Operator feedback collection | Operator findings/issues are recorded for follow-up | ☐ |

Notes for operator feedback collection:

- Record what the operator was doing, expected result, and actual result.
- Capture patient/visit identifiers only as needed and per privacy rules.
- Route control-workflow anomalies to a dedicated regression note before any code change.
- Do not change workflow rules based on a single report — confirm and document first.

## GO/NO-GO

GO if all are true:

- The pilot readiness/operator smoke checklist is complete.
- This document exists at `docs/sprint_28_phase_28_1_pilot_readiness_operator_smoke_checklist.md`.
- The focused documentation checklist test passes.
- No production code change.
- No migration.
- No deployment.
- No destructive operation.

NO-GO if any are true:

- There is a production code change.
- There is a migration.
- There is a destructive data operation.
- A business rule (RME/payment/receivable/cashier) is changed.
- The checklist is incomplete.

## Recommended Next Phase

Sprint 28 Phase 28.2 may be one of:

- **Pilot daily operation runbook** — turn this smoke checklist into a full day-in-the-life SOP.
- **WhatsApp Reminder & Receivable Follow-up Workflow Planning** — planning-only design for reminders and piutang follow-up.
- **Monitoring/backup/restore rehearsal** — verify backups and rehearse restore safely.

## Validation Plan

Minimum validation for this phase:

- `php artisan test --filter=Sprint28Phase281PilotReadinessOperatorSmokeChecklist`
- `vendor/bin/pint --test tests/Feature/Sprint28/Sprint28Phase281PilotReadinessOperatorSmokeChecklistTest.php`
- `git diff --check`

## Decision

**GO CANDIDATE FOR PR REVIEW**

Sprint 28.1 is a pilot readiness / operator smoke checklist phase only. It supports pilot
operation and protects the Sprint 27 RME Control Workflow GO baseline without changing any
application runtime behavior.
