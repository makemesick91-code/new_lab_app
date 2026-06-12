# Sprint 23 Phase 23.8 — Patient ID Format Finalization + New Patient Registration Flow

## 1. Summary

- **Status:** PASS (local only — no VPS work, no deploy, additive migration only).
- **Branch:** `feature/sprint-23-phase-23-8-patient-id-registration` (from `sprint-23-phase-23-7-branch-master-crud` / `ed73294`).
- **Commit:** see closing tag `sprint-23-phase-23-8-patient-id-registration-flow`.
- **Scope:** Finalize the patient medical record number format, add a branch-aware
  new patient registration flow (Master Data Cabang + manual RM number), let the
  RME visit create flow register a brand-new patient or select an existing one,
  and harden `BranchContext` so it never depends on a missing `users.branch_id`
  column. No payment / generation / conversion logic changed. Lab stays global.

## 2. Final Patient RM Format

Format:

```
RM DG-{KODE_CABANG}-{TAHUN_DAFTAR}-{NOMOR_RM_MANUAL}
```

Examples:

- `RM DG-TKM1-2026-0001`
- `RM DG-LDK2-2026-0025`
- `RM DG-ATG3-2026-0150`

Composed by `App\Modules\Patient\Services\PatientMedicalRecordNumberService`:

- Prefix is fixed: `RM DG`.
- Branch code is **uppercased and trimmed**.
- Year must be exactly four digits (derived from the registration date).
- Manual RM number is **trimmed but never auto-generated and never auto-padded** —
  leading zeros entered by the admin are preserved verbatim.

## 3. Business Rules

- Branch is selected **manually** from active + `is_rme_enabled` branches only.
- Year comes from the patient registration date (`registered_at`, default today).
- Manual RM number is entered by the user/admin (numeric only).
- The final `medical_record_number` is composed by the system and validated to be
  **globally unique** in `mst_patients`.
- The same manual number may exist on different branches/years because the
  composed final value differs.
- Existing patients are **not** rewritten. An explicit `medical_record_number`
  always wins (legacy / manual override path).
- No auto-sequence is used for the finalized manual RM number. The legacy
  `PatientCodeGenerator` auto-sequence remains only as a backward-compatible
  fallback when neither an explicit code nor branch+manual components are given.
- Patient-ID branch selection uses the **form-selected branch**, not the
  `BranchContext` fallback.

## 4. Data Model

Additive migration `2026_06_16_100001_add_registration_fields_to_mst_patients_table`
(all columns nullable, guarded by `Schema::hasColumn`, no backfill, no rewrites):

| Column | Type | Notes |
| --- | --- | --- |
| `branch_id` | nullable FK → `mst_branches` (`nullOnDelete`) | selected RME branch |
| `registered_at` | nullable date | registration date; year drives `TAHUN_DAFTAR` |
| `manual_rm_number` | nullable string(50) | manual RM number entered by admin |
| `medical_record_number` | existing nullable unique string(50) | final composed value |

Backward compatibility: legacy patients keep their existing
`medical_record_number`; the three new fields are null for them and they continue
to display and function normally.

## 5. Patient Registration UI

`resources/views/settings/patients/_form.blade.php` (create + edit):

- **Cabang** dropdown (active + `is_rme_enabled` only; shows `CODE — name`).
- **Tanggal Daftar** date input (defaults to today).
- **Nomor RM Manual** numeric input.
- **Nomor RM Final (Preview)** read-only field, recomputed live in JS as
  `RM DG-{CODE}-{YEAR}-{MANUAL}`.
- Helper text: "Nomor RM final dibentuk otomatis dari format:
  RM DG-{KODE_CABANG}-{TAHUN_DAFTAR}-{NOMOR_RM_MANUAL}. Nomor RM manual diisi oleh admin."
- A collapsible "Override Nomor RM manual penuh (pasien lama / format lama)" exposes
  the raw `medical_record_number` field for legacy patients; when filled it is used
  as-is and the RM DG composition is skipped.

Inventory-only branches and the global Lab are never shown for patient
registration.

## 6. RME Visit Integration

`resources/views/rme/visits/_form.blade.php` + `StoreClinicVisitRequest` +
`ClinicVisitService`:

- **Existing patient flow:** default mode. Select a registered patient; the select
  renders `medical_record_number — name` (or `Belum ada RM — name` for legacy).
- **New patient flow:** toggle to "Pasien Baru" reveals an embedded section
  (name, Cabang, Tanggal Daftar, Nomor RM Manual, optional demographics, live final
  RM preview). On submit the patient is created first (final RM composed) and the
  visit is attached to it — inside the same DB transaction.
- **Mode disambiguation:** a single `patient_mode` (`existing` | `new`) decides which
  branch is validated. `existing` requires `patient_id`; `new` requires
  `new_patient.{name,branch_id,manual_rm_number}`. The two modes are mutually
  exclusive by construction, so existing-and-new cannot both apply.
- Creating a new patient inside the visit flow additionally authorizes
  `create` on `Patient` (`manage patients`), on top of `manage_clinic_visits`.
- The visit's own `branch_id` continues to come from `BranchContext` (unchanged);
  only the patient's branch comes from the form.

## 7. BranchContext Hotfix

`App\Modules\Branch\Services\BranchContext`:

- `users.branch_id` is **not required**. `branchIdFromUserColumn()` is guarded by
  `Schema::hasColumn` and never touches a missing column, so it cannot 500 on the
  VPS schema (confirmed: no `users.branch_id`).
- Generic fallback (`id()` / `defaultBranchId()`): active MAIN branch, else the
  first active branch.
- New `rmeBranchId()` / `requireRmeBranchId()`: prefer MAIN when active +
  `is_rme_enabled`, else first active RME-enabled branch; `requireRmeBranchId()`
  throws a clear `RuntimeException` when none exists.
- New `inventoryBranchId()`: prefer MAIN when active + `is_inventory_enabled`, else
  first active inventory-enabled branch.
- Lab is **not** branch-enforced — no Lab branch logic reintroduced.

## 8. Tests

Commands run (local):

```
php artisan test tests/Feature/RME/PatientMedicalRecordNumberTest.php \
  tests/Feature/RME/PatientRegistrationTest.php \
  tests/Feature/RME/ClinicVisitNewPatientFlowTest.php \
  tests/Feature/Branch/BranchContextFallbackTest.php   # 28 passed
php artisan test --filter=Patient        # 49 passed
php artisan test --filter=ClinicVisit    # 65 passed
php artisan test --filter=Branch         # 305 passed
php artisan test --filter=Rme / Dashboard / Permission / Sidebar / MasterData
./vendor/bin/pint --dirty                # clean
npm run build                            # success
```

New test files:

- `tests/Feature/RME/PatientMedicalRecordNumberTest.php` — format, uppercasing,
  leading-zero preservation, four-digit year, no auto-generate/increment,
  uniqueness detection, same-manual-different-branch.
- `tests/Feature/RME/PatientRegistrationTest.php` — create with branch+manual,
  stores `branch_id`/`registered_at`/`manual_rm_number`, duplicate-final rejection,
  numeric-only rule, active+RME-enabled branch requirement, legacy explicit code,
  null-RM legacy display.
- `tests/Feature/RME/ClinicVisitNewPatientFlowTest.php` — existing flow, new flow
  creates patient+visit, mode validation, duplicate-final rejection, select display.
- `tests/Feature/Branch/BranchContextFallbackTest.php` — no `users.branch_id`
  dependency, RME/inventory fallback, MAIN-missing fallback, clear exception.

Known limitation: the full suite (~1900 tests) was not run end-to-end in this phase;
the affected modules (Patient, ClinicVisit/RME, Branch) were run focused instead.

## 9. Files Changed

**Model / migration**
- `database/migrations/2026_06_16_100001_add_registration_fields_to_mst_patients_table.php` (new)
- `app/Modules/Patient/Models/Patient.php`

**Services**
- `app/Modules/Patient/Services/PatientMedicalRecordNumberService.php` (new)
- `app/Modules/Patient/Services/PatientService.php`
- `app/Modules/ClinicVisit/Services/ClinicVisitService.php`
- `app/Modules/Branch/Services/BranchContext.php`
- `app/Modules/Branch/Services/BranchService.php`
- `app/Modules/Branch/Repositories/BranchRepository.php`
- `app/Modules/Branch/Interfaces/BranchRepositoryInterface.php`

**Requests / controllers**
- `app/Modules/Patient/Requests/StorePatientRequest.php`
- `app/Modules/Patient/Requests/UpdatePatientRequest.php`
- `app/Modules/Patient/Controllers/PatientController.php`
- `app/Modules/ClinicVisit/Requests/StoreClinicVisitRequest.php`
- `app/Modules/ClinicVisit/Controllers/ClinicVisitController.php`

**Views**
- `resources/views/settings/patients/_form.blade.php`
- `resources/views/settings/patients/create.blade.php`
- `resources/views/settings/patients/edit.blade.php`
- `resources/views/rme/visits/_form.blade.php`
- `resources/views/rme/visits/create.blade.php`

**Tests**
- `tests/Feature/RME/PatientMedicalRecordNumberTest.php` (new)
- `tests/Feature/RME/PatientRegistrationTest.php` (new)
- `tests/Feature/RME/ClinicVisitNewPatientFlowTest.php` (new)
- `tests/Feature/Branch/BranchContextFallbackTest.php` (new)

**Docs**
- `docs/sprint_23_phase_23_8_patient_id_registration_flow.md` (this file)
- `docs/sprint_history.md`

## 10. Watch Items

- Review existing patient rows with null or old-format `medical_record_number`
  **before** any backfill — backfilling legacy patients is **deferred** and must be
  an explicit, documented decision (the composer needs branch + year + manual number
  per row).
- Master Data Cabang must have the correct branch codes (e.g. `TKM1`, `LDK2`,
  `ATG3`) entered **before** final pilot patient entry — the code feeds the RM
  number directly.
- VPS deploy requires a DB backup and `php artisan migrate --force` only. Never
  `migrate:fresh` / `db:wipe` / `migrate:refresh` / `migrate:reset` on VPS.
- Do **not** use `users.branch_id` unless a future schema migration adds it
  intentionally; `BranchContext` is defensive but the patient form is the source of
  truth for the patient's branch.
- The legacy `PatientCodeGenerator` auto-sequence still exists as a fallback; it is
  not the finalized format and should not be used for new RME patients.

## 11. Next Recommended Phase

**Sprint 23 Phase 23.9 — VPS Deploy + Patient Registration / RME Visit Smoke**

- Backup DB, deploy this branch, `migrate --force`, reset storage/cache permissions.
- Confirm Master Data Cabang codes, smoke new patient registration (final RM format)
  and the RME visit new-patient flow on the pilot VPS.
- Decide/plan the optional legacy patient RM backfill as a separate, explicit step.
