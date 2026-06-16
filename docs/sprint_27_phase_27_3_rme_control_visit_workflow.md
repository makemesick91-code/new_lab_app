# Sprint 27 Phase 27.3 — RME Follow-up / Control Visit Workflow

**Branch:** `feature/sprint-27-phase-27-3-rme-control-visit-workflow`

## Summary

- Control visits reuse existing patient ID/RM (`medical_record_number`).
- Control visits create new `trx_clinic_visits` records with new `visit_number` / `queue_number`.
- Old RME, odontogram, and invoice records are not overwritten.
- Added `visit_type` and `follow_up_of_visit_id` on clinic visits.
- Added patient visit history panel, medical-record history summary, odontogram parent reference, cashier parent receivable summary, and **Buat Kontrol** action.

## Schema

| Column | Type | Default |
|--------|------|---------|
| `visit_type` | string(30) | `new` |
| `follow_up_of_visit_id` | nullable FK → `trx_clinic_visits.id` | `null` |

`visit_type` values: `new`, `control`, `continued_treatment`, `emergency`.

## Deploy note

Run additive migration only (never `migrate:fresh` on pilot):

```bash
php artisan migrate --path=database/migrations/2026_06_16_130001_add_control_visit_fields_to_trx_clinic_visits_table.php --force
```
