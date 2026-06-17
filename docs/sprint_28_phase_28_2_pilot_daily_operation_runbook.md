# Sprint 28 Phase 28.2 — Pilot Daily Operation Runbook

## Status

**Mode:** Pilot daily operation runbook only
**Deployment:** No deployment
**Migration:** No migration
**Production code change:** No production code change
**Destructive data operation:** No destructive data operation
**Baseline:** Sprint 28.1 GO at `fa9842f`

## Purpose

Sprint 28 Phase 28.2 turns the Sprint 28.1 operator smoke checklist into a practical
daily pilot operation runbook.

Goals:

- Turn the Sprint 28.1 operator smoke checklist into a practical daily pilot operation runbook.
- Give operators a start-of-day, during-operation, and end-of-day workflow.
- Help support/admin monitor pilot health without changing application behavior.
- Keep the Sprint 27 RME Control Workflow and the Sprint 28.1 operator checklist stable.

This phase is documentation/runbook/checklist only. It does not change RME, payment,
receivable, cashier, odontogram, invoice, route, service, controller, model, view,
migration, seeder, or configuration behavior.

## How to Use This Runbook

- Run the **Pre-Opening Checklist** before the pilot day starts.
- Follow the **Daily Operator Flow** for each patient/visit during the day.
- Apply the **RME Control Visit Daily Guardrail** whenever a control/follow-up visit is handled.
- Use the **Cashier / Receivable Flow** when billing and collecting payment.
- Run the **Support / Admin Monitoring Flow** daily or per shift.
- Record any issue in the **Operator Feedback Log**.
- Run the **End-of-Day Closing Checklist** before closing the pilot day.
- Mark each item PASS / FAIL / N/A and record notes. Any FAIL on a guardrail or
  escalation item is a blocker — stop and escalate before continuing.

## Pilot Roles and Responsibilities

| Role | Responsibility |
|------|----------------|
| Operator | Runs daily patient/visit/RME/odontogram flow and pre-opening checks. |
| Cashier | Runs cashier billing, payment, receipt, and receivable verification. |
| Support/Admin | Monitors Laravel log, backup presence, disk usage, route/menu, users/roles, feedback collection. |
| Developer/Reviewer | Reviews escalated issues, validates fixes, owns code/migration review (no direct production data change). |
| Owner/Supervisor | Owns GO/NO-GO decision for pilot continuity and daily issue summary sign-off. |

## Pre-Opening Checklist

| # | Check | Expected result | Result |
|---|-------|-----------------|--------|
| 1 | App reachable | Pilot URL loads and login page is shown. | |
| 2 | Operator account ready | Operator/cashier/support accounts can log in. | |
| 3 | Menu visibility ready | Each role sees only its permitted menu items. | |
| 4 | Printer/browser print ready | Browser print/odontogram/receipt preview works. | |
| 5 | Backup presence checked | A recent database backup is present. | |
| 6 | Laravel log baseline checked | Latest `storage/logs/laravel.log` reviewed for new critical errors. | |
| 7 | Disk usage checked | Disk has enough free space for the pilot day. | |
| 8 | Feedback log ready | Operator feedback log is ready to record issues. | |

## Daily Operator Flow

| # | Step | Expected result | Result |
|---|------|-----------------|--------|
| 1 | Login | Operator logs in and lands on the permitted dashboard. | |
| 2 | Patient search | Existing patient is found by name/RM. | |
| 3 | New patient registration smoke | New patient can be registered without error. | |
| 4 | Visit creation | A new visit is created and linked to the patient. | |
| 5 | RME visit input | RME (handwriting RM) can be entered and finalized. | |
| 6 | Odontogram input | Odontogram can be entered and saved. | |
| 7 | Odontogram/RME print | Odontogram/RME prints via browser print. | |
| 8 | Logout/session check | Operator can log out and session ends cleanly. | |

## RME Control Visit Daily Guardrail

This guardrail protects Sprint 27 RME Control Workflow GO behavior during pilot use.
Any FAIL is a blocker.

| # | Guardrail | Expected result | Result |
|---|-----------|-----------------|--------|
| 1 | Same patient/RM | Control visit reuses the same patient/RM. | |
| 2 | New visit created | A new visit is created for the control visit. | |
| 3 | Old data protected | Old visit/RME/odontogram/invoice is not overwritten. | |
| 4 | Parent receivable visible | Parent receivable appears in cashier control. | |
| 5 | FIFO allocation protected | Payment allocation remains FIFO previous receivable first. | |
| 6 | Completion not blocked by parent receivable | Parent receivable does not block control completion. | |
| 7 | Rp0 invoice excluded from active receivables | Rp0 invoice does not appear in active receivables. | |

## Cashier / Receivable Flow

| # | Step | Expected result | Result |
|---|------|-----------------|--------|
| 1 | Open cashier billing | Cashier opens billing for a `cashier_pending` visit. | |
| 2 | Check invoice amount | Invoice amount matches the treatment/service. | |
| 3 | Check previous receivable | Any previous receivable for the patient is shown. | |
| 4 | Process payment | Full payment is processed without error. | |
| 5 | Verify FIFO behavior | Payment is allocated previous receivable first (FIFO). | |
| 6 | Print receipt | Receipt prints with correct patient/visit/invoice/allocation. | |
| 7 | Active receivable check | Remaining active receivable reflects the payment; Rp0 invoice excluded. | |

## Support / Admin Monitoring Flow

| # | Step | Expected result | Result |
|---|------|-----------------|--------|
| 1 | Laravel log check | No new repeated critical errors in `storage/logs/laravel.log`. | |
| 2 | Backup presence check | A recent database backup is present and readable. | |
| 3 | Disk usage check | Disk free space remains within safe limits. | |
| 4 | Route/menu quick check | Key routes/menus load for each role. | |
| 5 | User/role quick check | Users have correct roles/permissions. | |
| 6 | Feedback collection check | Operator feedback log is collected and reviewed. | |

## Operator Feedback Log Format

Record one row per issue or observation.

| Field | Description |
|-------|-------------|
| Date/time | When the issue occurred. |
| Operator/cashier/support | Who reported it. |
| Branch/device | Branch and device used. |
| Patient/RM/visit reference if needed | Identifier only when needed for follow-up. |
| Module/page | Module/page where it happened. |
| Action performed | What the user did. |
| Expected result | What should have happened. |
| Actual result | What actually happened. |
| Screenshot/print note | Reference to screenshot or print sample. |
| Severity | Critical / Major / Minor. |
| Reproducible? | Yes / No / Unknown. |
| Follow-up owner | Who owns the follow-up. |

### Privacy Note

- Record patient/visit identifiers only when needed for follow-up.
- Do not share patient data outside the approved support channel.
- Do not change production data directly without review.

## End-of-Day Closing Checklist

| # | Check | Expected result | Result |
|---|-------|-----------------|--------|
| 1 | Operator smoke notes collected | Daily operator notes are gathered. | |
| 2 | Cashier/payment notes collected | Cashier/payment notes are gathered. | |
| 3 | RME control guardrail reviewed | Control-visit guardrail results reviewed. | |
| 4 | Laravel log reviewed | End-of-day Laravel log reviewed. | |
| 5 | Backup presence confirmed | Backup presence confirmed for the day. | |
| 6 | Daily issue summary prepared | Daily issue summary is written. | |
| 7 | Next-day action list prepared | Next-day action list is written. | |

## Escalation Rules

Escalate immediately (stop and notify support/admin and developer/reviewer) when:

- Multi-user login failure.
- RME/odontogram data appears overwritten.
- Control visit does not create a new visit.
- Previous receivable allocation does not follow FIFO.
- Rp0 invoice appears in active receivables.
- Receipt/billing shows wrong patient/visit/invoice/allocation.
- Repeated critical Laravel log errors.
- Disk/backup issue risks pilot continuity.

## GO / NO-GO

**GO** only if:

- This runbook document exists.
- `docs/sprint_history.md` is updated with the Sprint 28 Phase 28.2 entry.
- The focused test passes.
- No production code change, no migration, no deployment, and no destructive operation.

**NO-GO** if:

- Any production code change.
- Any migration.
- Any destructive operation.
- Any business rule change.
- Incomplete runbook.
- Missing sprint history entry.
- Missing test.

## Recommended Next Phase

Sprint 28 Phase 28.3 may be one of:

- WhatsApp Reminder & Receivable Follow-up Workflow Planning.
- Monitoring/backup/restore rehearsal.
- Pilot issue triage and stabilization backlog.

## Validation Plan

- `php artisan test --filter=Sprint28Phase282PilotDailyOperationRunbook`
- `vendor/bin/pint --test tests/Feature/Sprint28/Sprint28Phase282PilotDailyOperationRunbookTest.php`
- `git diff --check`

## Decision

GO CANDIDATE FOR PR REVIEW
