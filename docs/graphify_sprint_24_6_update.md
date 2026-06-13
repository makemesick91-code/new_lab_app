# Graphify Update — Sprint 24.6

## Context

This document records the Graphify refresh for Sprint 24 Phase 24.6.

## Previous Graphify Update

- Commit: `ae5fb4a`
- Tag: `sprint-24-graphify-sprint-24-4-to-24-5-update`
- Covered through: Sprint 24.5

## New Sprint Coverage

### Sprint 24 Phase 24.6 — RME Receivable Aging + Export Foundation

- Adds aging buckets to Piutang RME:
  - 0–7 Hari
  - 8–14 Hari
  - 15–30 Hari
  - >30 Hari
- Adds aging summary count and remaining balance.
- Adds `aging_bucket` filter.
- Adds CSV export route:
  - `rme.cashier.receivables.export`
- Export preserves Piutang RME filters.
- Export includes aging and remaining balance.
- No migration.
- No payment logic change.
- No Excel package.

## Important Files

- `app/Modules/RmeInvoice/Controllers/RmeInvoiceController.php`
- `resources/views/rme/cashier/receivables.blade.php`
- `routes/web.php`
- `tests/Feature/RME/CashierBillingTest.php`
- `docs/sprint_24_phase_24_6_rme_receivable_aging_export_foundation.md`

## Graphify Result

- Command: `graphify update .`
- Result: PASS
- Nodes: 11769
- Edges: 16779
- Communities: 1715
- Files extracted: 1609

## Notes

- `graphify-out/` is generated and gitignored.
- Only this companion doc should be committed.
