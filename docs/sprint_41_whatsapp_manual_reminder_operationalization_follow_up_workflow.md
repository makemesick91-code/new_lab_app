# Sprint 41 — WhatsApp Manual Reminder Operationalization & Follow-up Workflow

Status: Draft / Local Validation Pending
Baseline: Sprint 40 GO at 8647b0f
Scope: Controlled WhatsApp manual reminder operationalization / follow-up workflow / local implementation / targeted regression only

## Purpose

Sprint 41 follows Sprint 40 Reporting, Export & Owner Dashboard Improvement. It implements
controlled **manual** WhatsApp reminder and follow-up workflow improvements on top of the existing
RME receivable follow-up workflow (Sprint 24 Phase 24.8) and the existing `WaReminderTemplate`
master-data module (Sprint 19 Phase 5).

It focuses on:

- manual WhatsApp reminder clarity
- follow-up logging context
- reminder template guidance
- receivable/piutang follow-up continuity
- dashboard/reporting continuity
- privacy protection
- targeted regression coverage

It does **not** deploy or touch production/VPS. It does **not** send WhatsApp messages, create
WhatsApp automation, or call any external WhatsApp/API provider. The `wa.me` helper link added in
this sprint is a **client-side hyperlink only** — the operator reviews and sends the message
manually; the server never sends anything and never calls any external API.

## Baseline references

Sprint 39 GO: sprint-39-cashier-payment-receivable-improvement-batch-1-go at 1097d98
Sprint 40 GO: sprint-40-reporting-export-owner-dashboard-improvement-go at 8647b0f
Sprint 40 feature commit: 8ed83ad

## Implemented scope summary

Discovery confirmed the workflow is already mature, so Sprint 41 stays small and additive:

- **Manual WhatsApp reminder clarity:** the follow-up create view already carried a manual-only
  disclaimer. Sprint 41 adds a dedicated "Bantuan Pengingat WhatsApp (Manual)" card that states the
  text is copy-only and the operator reviews and sends manually.
- **Follow-up logging workflow:** reused the existing `RmeReceivableFollowUp` model /
  `RmeReceivableFollowUpController` / `StoreRmeReceivableFollowUpRequest` / follow-up create view.
  Context already includes patient name, WA number, invoice/receivable context, follow-up status,
  channel, note, contacted date / created-at, and next follow-up date. No new follow-up schema was
  added — existing schema already supports the required context.
- **Reminder template guidance:** reused the existing `WaReminderTemplate` module. The template
  index safety notice copy was sharpened to state templates are operator-facing copy-only text,
  copied manually to WhatsApp, with no auto-send and no WhatsApp API. Existing trigger types already
  distinguish appointment/visit reminder, payment reminder, installment reminder, follow-up/control
  reminder, lab-case-ready, and general.
- **Receivable/piutang follow-up continuity:** the receivables list keeps the manual WA disclaimer,
  WA number display, last follow-up summary, next follow-up scheduling indicators, and the
  "Tambah Follow-up" entry point. No receivable query/service was changed.
- **Dashboard/reporting continuity:** no dashboard/reporting code was changed. Owner dashboard
  receivable follow-up KPI behavior from Sprint 24/40 remains intact and branch-aware.
- **WA manual follow-up context:** added a privacy-safe, copyable draft message (patient name,
  branch, invoice number, remaining balance) plus a clearly-labeled manual `wa.me` client-side link
  built from the patient's WhatsApp number. The link opens a WhatsApp draft for the operator to
  review and send manually.
- **KTP/privacy protection:** the manual draft, helper card, and template guidance never include
  No. KTP / identity number. Existing follow-up/receivable/template views already omit KTP. Added
  regression assertions to keep KTP out of the follow-up helper.
- **Zero-remaining receivable exclusion:** preserved. Paid / zero-remaining invoices remain excluded
  from active receivables; partial/unpaid remain visible. No change to the exclusion query/service;
  a Sprint 41 regression assertion guards it.
- **Permission/authorization:** reused `manage_rme_billing` and existing follow-up policy / branch
  isolation. Unauthorized users and non-RME-branch invoices remain forbidden from the follow-up
  helper. No permission added or relaxed.
- **Tests added/updated:** `tests/Feature/Sprint41/Sprint41WhatsAppManualReminderOperationalizationFollowUpWorkflowTest.php`
  (sprint checklist + functional regression: manual helper visibility, copyable draft, manual `wa.me`
  link, KTP privacy, zero-remaining exclusion preservation, authorization).
- **Migration added:** none. No schema change was necessary.
- **Deferred items:** no WhatsApp automation, no queue/job/cron, no external WhatsApp API
  integration, no new notification provider — all intentionally deferred and out of scope. Any future
  automated sending remains a separate, explicitly-approved initiative.

## Safety boundaries

- no production/VPS access
- no deployment
- no production migration
- no external WhatsApp send
- no WhatsApp automation
- no WhatsApp API integration
- no new notification provider
- no queue/job/cron/scheduler automation
- no new dependency/package install
- no risky financial calculation rewrite
- no signature upload/capture integration
- no backup/restore/rollback execution
- no destructive operation
- no `.env` change
- no GO tag

## Validation commands

```bash
php artisan test --filter=Sprint41WhatsAppManualReminderOperationalizationFollowUpWorkflow
php artisan test --filter=RmeReceivableFollowUp
php artisan test --filter=WaReminderTemplate
vendor/bin/pint --test
git diff --check
```

## GO CANDIDATE FOR PR REVIEW

## Next sprint recommendation

Sprint 42 — Monitoring, Backup & Recovery Governance Hardening

Sprint 42 should focus on controlled monitoring evidence review, backup/recovery governance
hardening, restore readiness documentation, operational review cadence, and safety gates — without
executing real production backup/restore unless separately approved.
