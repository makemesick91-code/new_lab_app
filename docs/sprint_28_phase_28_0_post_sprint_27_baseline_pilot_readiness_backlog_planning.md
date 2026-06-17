# Sprint 28 Phase 28.0 — Post Sprint 27 Baseline, Pilot Readiness & Next Backlog Planning

## Status

**Mode:** Planning / baseline / backlog alignment only
**Deployment:** No deployment
**Migration:** No migration
**Production code change:** No production code change
**Destructive data operation:** No destructive data operation
**Baseline commit:** `c9e378a`
**Baseline GO tag:** `sprint-27-rme-control-workflow-go`

## Purpose

Sprint 28 Phase 28.0 establishes the post-Sprint 27 baseline after the RME Control Workflow was closed as GO.

This phase prevents uncontrolled feature work after Sprint 27 closure. It locks the technical and business baseline, records pilot-readiness assumptions, and prepares the next backlog lanes before any implementation phase begins.

## Baseline

Sprint 27 is considered closed as GO with the following final posture:

- RME control workflow remains closed as Sprint 27 GO.
- Control visit uses the same patient/RM but creates a new visit.
- Previous visit, RME, odontogram, invoice, and invoice items are preserved.
- Previous receivable can be paid from the control cashier page.
- Payment allocation remains FIFO: previous receivable first, then current control invoice.
- Parent receivable does not block control visit completion.
- Free control can complete after a payment batch toward old receivable.
- Paid control completes only when the current control invoice is fully paid.
- Rp0 control invoices remain billing/history records but are excluded from active receivables.
- Active receivables only include invoices with remaining balance greater than 0.

## Scope

Included in this phase:

- Document post-Sprint 27 baseline.
- Record pilot-readiness posture.
- Define candidate Sprint 28 backlog lanes.
- Define safety rules for the next implementation phases.
- Add a lightweight documentation checklist test.
- Update sprint history.

Excluded from this phase:

- No deployment.
- No migration.
- No controller/service/model/view changes.
- No cashier, RME, invoice, payment, odontogram, or receivable logic changes.
- No database mutation.
- No queue/WhatsApp integration implementation.
- No new production route.
- No destructive data operation.

## Pilot Readiness Posture

The application is ready for controlled pilot continuation using the Sprint 27 GO baseline, with the following guardrails:

- Keep pilot testing focused and observable.
- Do not change RME control workflow rules without a new dedicated sprint phase.
- Avoid broad refactors while pilot is ongoing.
- Prefer small, reversible, test-backed phases.
- Document operator findings before changing workflow.
- Any data-related operation must be backup-first and non-destructive.
- Any deployment should have a separate VPS verification phase.

## Candidate Sprint 28 Backlog Lanes

### Lane A — Pilot readiness and operator smoke checklist

Goal: create a daily or weekly checklist for pilot operators and support staff.

Candidate items:

- Login and role check.
- Patient search and registration smoke.
- Visit creation smoke.
- RME input smoke.
- Odontogram print smoke.
- Cashier billing smoke.
- Payment receipt smoke.
- Active receivables check.
- Export and print report check.
- Backup and log check.

### Lane B — WhatsApp appointment reminder and receivable follow-up planning

Goal: design automation workflow for appointment reminders and piutang follow-up.

Candidate items:

- Appointment reminder data source.
- Patient WA number validation.
- Template message rules.
- Manual approval versus automatic sending.
- Follow-up schedule.
- Payment reminder escalation.
- Audit log requirement.
- Opt-out or wrong-number handling.
- WhatsApp provider decision.
- No implementation before requirements are frozen.

### Lane C — RME cashier/reporting polish

Goal: improve operator clarity without changing business rules.

Candidate items:

- More explicit payment allocation display.
- Better labels for previous receivable versus current invoice.
- Receipt wording review.
- Report filter clarity.
- Export naming consistency.
- Print layout polish.

### Lane D — Monitoring, backup, and restore rehearsal

Goal: protect pilot data and operational continuity.

Candidate items:

- Daily DB backup verification.
- Runtime file backup verification.
- Restore rehearsal checklist.
- Laravel log check.
- Queue or scheduler check if enabled later.
- Disk usage alert.
- VPS health snapshot.
- Deployment rollback note.

### Lane E — Branch rollout readiness

Goal: prepare from one pilot branch toward multi-branch usage.

Candidate items:

- Branch access review.
- Branch context smoke.
- Cross-branch isolation smoke.
- Role and permission checklist.
- Per-branch reporting check.
- Branch onboarding SOP.
- Branch-specific backup and restore note.

## Recommended Next Phase

Recommended next phase:

**Sprint 28 Phase 28.1 — Pilot Readiness & Operator Smoke Checklist**

Reason:

- It is low-risk.
- It does not require database changes.
- It supports pilot usage immediately.
- It creates a repeatable validation checklist before adding new automation features.
- It keeps Sprint 27 RME control workflow protected.

Alternative next phase if WhatsApp automation is urgent:

**Sprint 28 Phase 28.1 — WhatsApp Reminder & Receivable Follow-up Workflow Planning**

This should remain planning-only first, because WhatsApp automation can affect patients, payment reminders, privacy, and operational trust.

## Safety Rules for Sprint 28 Implementation Phases

All Sprint 28 implementation phases should follow these rules:

- Start from the Sprint 27 GO baseline unless explicitly stated otherwise.
- One phase, one purpose.
- No migration unless the phase explicitly requires it and has rollback notes.
- No destructive data operation.
- No broad refactor during pilot stabilization.
- No business rule change without a dedicated rule document.
- No deploy mixed with feature coding.
- No WhatsApp auto-send before template and approval rules are documented.
- No payment or receivable logic change without focused regression tests.
- No route duplication.
- No service duplication.
- No test duplication.

## Validation Plan

Minimum validation for this phase:

- `php artisan test --filter=Sprint28Phase280BaselinePlanning`
- `vendor/bin/pint --test tests/Feature/Sprint28/Sprint28Phase280BaselinePlanningTest.php`
- `git diff --check`

## GO/NO-GO Checklist

GO candidate if all are true:

- Sprint 27 GO baseline is confirmed at `c9e378a`.
- Sprint 27 GO tag exists: `sprint-27-rme-control-workflow-go`.
- Sprint 28.0 planning document exists.
- Sprint history references Sprint 28 Phase 28.0.
- Documentation checklist test passes.
- No production code change.
- No migration.
- No deployment.
- No destructive data operation.
- Next backlog lane is explicitly documented.

NO-GO if any are true:

- Working tree is dirty unexpectedly.
- Sprint 27 GO baseline is not confirmed.
- The phase modifies production logic.
- The phase introduces migration unexpectedly.
- The phase mixes planning with implementation.
- The phase changes RME/payment/receivable behavior.

## Decision

**GO CANDIDATE FOR PR REVIEW**

Sprint 28.0 is a planning baseline phase only. It prepares the project for the next controlled Sprint 28 phase without changing application runtime behavior.
