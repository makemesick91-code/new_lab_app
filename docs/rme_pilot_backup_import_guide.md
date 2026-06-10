# RME Pilot Backup Import Guide

## Purpose

Load realistic **master data** for the Sprint 20 RME limited pilot from a legacy PostgreSQL plain SQL backup, without restoring the full database or overwriting Sprint 20 schema, roles, permissions, or transactional history.

Use this when you need branches, doctors, patients, and treatment/tariff masters for manual pilot testing of:

Admin visit → Doctor RME/odontogram → Finalize → Cashier invoice → Full payment → Completed.

## Why direct SQL restore is not allowed

A full `psql` restore would:

- Overwrite or conflict with current migrations and Sprint 20 tables
- Re-import roles, permissions, users, sessions, jobs, cache
- Restore old lab invoices/payments and other transactional data
- Break branch isolation and permission contracts hardened in Sprints 10–20

The importer reads **COPY blocks only** for a whitelisted set of master tables and maps rows into current ADLMS models.

## Backup file location

Place the dump at:

```text
storage/app/imports/asia_dental_lab_2026-06-08_2246.sql
```

Or pass another path with `--file=`.

## Dry-run (recommended first)

```bash
php artisan rme:import-pilot-backup --dry-run
php artisan rme:import-pilot-backup --dry-run --only=branches,doctors
php artisan rme:import-pilot-backup --dry-run --limit=5
```

Dry-run shows detected row counts, skipped protected tables, and planned inserts/updates. **No database writes.**

## Real import

Run only after dry-run looks safe and tests pass:

```bash
php artisan rme:import-pilot-backup
php artisan rme:import-pilot-backup --only=patients,treatments
php artisan rme:import-pilot-backup --limit=10
```

Imports run inside a DB transaction. Existing rows are matched with `updateOrCreate` / `firstOrCreate`; tables are **never truncated**.

## Imported tables (whitelisted)

| Backup table | Target |
|---|---|
| `mst_branches` | `mst_branches` (Branch) |
| `mst_doctors` | `mst_doctors` (Doctor) |
| `mst_patients` | `mst_patients` (Patient) |
| `mst_lab_services` | `mst_treatment_categories`, `mst_treatments`, `mst_tariffs` |

Doctors/patients are linked to a placeholder clinic `PILOT-IMPORT` when clinics are not imported (FK requirement). No login users are created.

## Protected / skipped tables

Never imported:

- `migrations`, `roles`, `permissions`, `role_has_permissions`, `model_has_roles`, `model_has_permissions`
- `users`, `sessions`, `cache`, `jobs`, `failed_jobs`, `job_batches`, `password_reset_tokens`
- `sys_audit_logs`, `sys_attachments`
- `trx_invoices`, `trx_invoice_items`, `trx_payments`
- `trx_rme_invoices`, `trx_rme_invoice_items`, `trx_rme_payments`
- `trx_clinic_visits`, `trx_medical_records`, `trx_odontograms`
- Old lab order / reporting summary tables

Visits, RME records, odontograms, and billing must be created through the Sprint 20 app workflow.

## Mapping rules (summary)

- **Branches:** unique by `code` (fallback `name`); fill empty fields only on existing rows
- **Doctors:** unique by `code` (fallback `name` + `phone`); `clinic_id` → pilot placeholder clinic
- **Patients:** unique by `medical_record_number` (fallback `name` + `phone` + `date_of_birth`); `doctor_id` resolved via backup doctor code
- **Lab services:** `code`/`name`/`description`/`category`/`price` → Treatment + TreatmentCategory + Tariff for default active branch; `requires_lab` from conservative keyword match (crown, bridge, veneer, retainer, night guard, lab, etc.)

Tariff `effective_date`: `2026-06-10`.

## Post-import validation checklist

1. `php artisan test --filter=PilotBackupImport`
2. Confirm branch/doctor/patient counts in UI or tinker
3. Create a **new** clinic visit with imported patient + doctor + initial treatment
4. Complete doctor RME + odontogram + finalize → visit `cashier_pending`
5. Cashier creates RME invoice from treatments/tariffs and records full payment
6. Verify no old `trx_payments` / lab invoices appeared

## Recommended pilot data counts

For first pilot pass, start small:

```bash
php artisan rme:import-pilot-backup --dry-run --limit=5
php artisan rme:import-pilot-backup --limit=5
```

Typical full backup snapshot (2026-06-08): ~1 branch, ~14 doctors, ~many patients, ~10 lab services. Adjust `--only` and `--limit` for staged loading.

## Command reference

```bash
php artisan rme:import-pilot-backup
  {--file=storage/app/imports/asia_dental_lab_2026-06-08_2246.sql}
  {--dry-run}
  {--only=branches,doctors,patients,treatments}
  {--limit=}
```
