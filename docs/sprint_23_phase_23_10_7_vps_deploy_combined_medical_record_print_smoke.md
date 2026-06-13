# Sprint 23 Phase 23.10.7 — VPS Deploy + Combined Medical Record Print Smoke

## Status
PASS

## Deployment Target
- VPS path: `/var/www/asia-dental-lab-v2`
- Deployed branch: `feature/sprint-23-phase-23-10-6-medical-record-print-odontogram-merge`
- Deployed commit: `88944a9`
- Deployed tag: `sprint-23-phase-23-10-6-medical-record-print-odontogram-merge`

## Pre-Deploy Safety
- Git status on VPS was clean before deploy.
- Database backup completed before deploy:
  `/root/db_backups/asia_dental_lab_pilot_pre_sprint_23_10_7_20260613_072300.dump`
- No destructive database command was used.
- `migrate:fresh`, `migrate:reset`, and `db:wipe` were not used.

## Migration
No new migration was detected between current VPS commit and target deploy commit.

Migration action:
- `php artisan migrate` was skipped.
- Migration status was checked only.

## Deploy Actions
- Switched VPS branch to:
  `feature/sprint-23-phase-23-10-6-medical-record-print-odontogram-merge`
- Confirmed deployed commit:
  `88944a9`
- Rebuilt Laravel cache:
  - `php artisan optimize:clear`
  - `php artisan config:cache`
  - `php artisan route:cache`
  - `php artisan view:clear`
  - `php artisan view:cache`
- Fixed storage/cache permissions.
- Restarted `php8.3-fpm`.
- Reloaded `nginx`.

## Route Verified
Combined medical record print route:

- `GET /rme/visits/{clinicVisit}/print`
- Route name: `rme.visits.print`

## Smoke Test Data
- Visit ID: `5`
- Visit number: `VIS-20260613-001`
- Patient: `Megasanti`
- Odontogram ID: `5`
- Odontogram status: finalized
- Selected odontogram results payload: available

Selected teeth verified as caries:
- 11
- 12
- 21
- 31
- 32
- 41
- 42

## Browser Smoke Test Result
PASS:

- Halaman tidak error 500.
- Nama pasien Megasanti muncul.
- Nomor kunjungan VIS-20260613-001 muncul.
- Section odontogram muncul.
- Tabel Hasil Odontogram yang Dipilih muncul.
- Gigi 11, 12, 21, 31, 32, 41, 42 muncul sebagai caries.
- Kolom Tanda Klinis / Kondisi Tambahan muncul.
- Kolom Catatan Gigi / Catatan Tambahan muncul.
- Layout print rapi dan layak untuk pilot/owner.

## Verification Note
The additional fields columns were verified visually in the combined medical record print.

Filled-value verification for:
- `additional_conditions`
- `summary_notes`

is pending because current pilot odontogram data still contains null values for those fields.

## Known Issue Found During Smoke
A separate issue was found during RME visit creation:

- Creating a new visit can fail with duplicate `visit_number`.
- Example duplicate:
  `VIS-20260613-001`
- Error:
  `SQLSTATE[23505]: duplicate key value violates unique constraint trx_clinic_visits_visit_number_unique`

This issue is not caused by the combined medical record print deploy and should be handled as a separate hotfix:
`visit_number` generator must ensure global uniqueness before insert.

## Final Decision
Sprint 23 Phase 23.10.7 is closed as:

PASS with note:
Combined medical record print smoke passed. Selected odontogram results are now visible inside the visit print bundle. Additional field value verification is pending real pilot data with filled additional conditions and summary notes.
