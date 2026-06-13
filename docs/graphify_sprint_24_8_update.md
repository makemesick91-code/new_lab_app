# Graphify Update — Sprint 24.8

## Context

This document records the Graphify refresh for Sprint 24 Phase 24.8.

## Previous Graphify Update

- Commit: `fd27c43`
- Tag: `sprint-24-phase-24-7-vps-rme-receivable-aging-export-smoke`
- Covered through: Sprint 24.7

## New Sprint Coverage

### Sprint 24 Phase 24.8 — RME Receivable Follow-up / Reminder Foundation

- Adds follow-up/reminder tracking for RME receivables.
- Adds follow-up history per RME invoice.
- Adds latest follow-up summary on Piutang RME.
- Adds next follow-up date / due indicator.
- Adds follow-up status and channel.
- Adds follow-up form and store action.
- Uses same billing/receivable permission scope.
- No payment logic change.
- No WhatsApp sending.
- No external reminder service.

## Important Files

- `app/Modules/RmeInvoice/Models/RmeReceivableFollowUp.php`
- `app/Modules/RmeInvoice/Controllers/RmeReceivableFollowUpController.php`
- `app/Modules/RmeInvoice/Requests/StoreRmeReceivableFollowUpRequest.php`
- `app/Modules/RmeInvoice/Models/RmeInvoice.php`
- `resources/views/rme/cashier/receivables.blade.php`
- `resources/views/rme/cashier/follow-ups/create.blade.php`
- `routes/web.php`
- `tests/Feature/RME/RmeReceivableFollowUpTest.php`
- `docs/sprint_24_phase_24_8_rme_receivable_follow_up_reminder_foundation.md`

## Graphify Result

- Command: `graphify update .`
- Result: PASS
- Nodes: 11848
- Edges: 16875
- Communities: 1712
- Files extracted: 1621

## Notes

- `graphify-out/` is generated and gitignored.
- Only this companion doc should be committed.
