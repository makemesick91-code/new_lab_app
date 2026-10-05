# FEATURE-RME-MEDICAL-RECORDS-UNIFIED-NATIVE-LEGACY-1

Rule mirror: `.cursor/rules/173-unified-medical-record-index.mdc`.

## Before (measured, not assumed)

- **Route** `GET /rme/medical-records` → `rme.medical-records.index` →
  `MedicalRecordController@index`, inside the `rme.` group behind
  `permission:view_clinic_visits|manage_clinic_visits`; policy
  `MedicalRecordPolicy::viewAny` (same two permissions).
- **Query** `MedicalRecordService::paginate()` →
  `MedicalRecordRepository::paginateForBranches()`: `MedicalRecord::query()`
  over `trx_medical_records`, `branch_id IN rmeEnabledIds()`, doctor scope via
  `DoctorPatientScopeService::applyMedicalRecordScopeForUser`, ordered by
  `created_at DESC`, 15 per page.
- **Shape**: one row per NATIVE medical record (a patient with three records
  appeared three times). Legacy archives were not read at all.
- **Production** (`asia_dental_lab_pilot`, PG 16, read-only, 2026-10-05):
  1519 live patients, **0** native medical records (estate reset), 19 PUBLISHED
  legacy RME, 1 PUBLISHED legacy odontogram, 0 VOID. The page showed **nobody**.

## After

One row per patient who holds at least one record the actor may READ:

```
mst_patients (non-deleted, doctor patient scope)
WHERE EXISTS(native record in rmeEnabledIds)
   OR EXISTS(PUBLISHED legacy RME in LegacyRmeWorkspaceScope)        -- if readable
   OR EXISTS(PUBLISHED legacy odontogram in LegacyOdontogramWorkspaceScope) -- if readable
```

| Source | Read gate | Branch envelope |
|---|---|---|
| Native | `view_clinic_visits` / `manage_clinic_visits` (route + policy, unchanged) | `rmeEnabledIds()` (unchanged) |
| Legacy RME | `LegacyRmeRecordPolicy::READ_PERMISSIONS` | `LegacyRmeWorkspaceScope` (+NULL rows for governance only) |
| Legacy Odontogram | `LegacyOdontogramRecordPolicy::READ_PERMISSIONS` | `LegacyOdontogramWorkspaceScope` |
| All | doctor patient scope (`applyPatientScopeForUser`) | — |

These are exactly the three predicates each canonical viewer's `view()` policy
applies (permission + branch scope + doctor clinical scope), so the list can
never advertise an archive the viewer would refuse.

### Files

- `app/Modules/MedicalRecord/Support/UnifiedMedicalRecordScope.php` — the
  actor envelope, resolved once per request.
- `app/Modules/MedicalRecord/Support/UnifiedMedicalRecordSource.php` — source
  filter vocabulary (`all|native|legacy|native_legacy|legacy_rme|legacy_odontogram`).
- `app/Modules/MedicalRecord/Interfaces/UnifiedMedicalRecordIndexRepositoryInterface.php`
  + `Repositories/UnifiedMedicalRecordIndexRepository.php` — set-based read model.
- `app/Modules/MedicalRecord/Services/UnifiedMedicalRecordIndexService.php` —
  composes the canonical scopes.
- `MedicalRecordController@index` (rewritten) + `@patient` (new).
- Route `GET rme/medical-records/patients/{patientId}` →
  `rme.medical-records.patients.show` (same permission group, `whereNumber`).
- Views `rme/visits/medical-record/index.blade.php` (rewritten) and
  `patient.blade.php` (new).
- Binding in `RepositoryServiceProvider`.

**No migration, no index, no permission, no seeder, no JS/CSS, no write path.**

### UI

Row columns: Pasien (name + Nomor RM), Cabang, Native RME (`N sheet` + latest
status), Kunjungan Terakhir, Ruangan, Legacy RME (`Tersedia`/—), Legacy
Odontogram (`Tersedia`/—), Terakhir Diperbarui, Aksi (`Ruang Kerja RM` when a
native record exists — the old one-click native path, preserved — and `Buka`).
Summary tiles: Total / Native / Legacy / Native + Legacy, all actor-scoped.

### Patient workspace

`Rekam Medis Native` lists visible native records with links into the
canonical RM workspace, or **"Rekam Medis Native: Belum Ada"**. `Arsip Legacy`
lists records from `LegacyRmePatientHistoryService::publishedRecordsFor()` and
`LegacyOdontogramPatientHistoryService::publishedRecordsFor()` with links to
`rme.legacy-records.show` / `rme.legacy-odontograms.show`. No print button, no
storage path, no fabricated visit. A patient outside the index → 404.

### Deliberate behaviour changes (stated, not hidden)

1. Rows are patients, not records. A patient with several native records shows
   once, with the count and the latest record's visit/room/doctor/status.
2. A soft-deleted patient no longer appears (the old record list showed their
   records with a blank name).
3. `status` and visit-date filters narrow to patients holding a matching
   native record.
4. Search also matches the Nomor RM (previously name, doctor, visit number).

`MedicalRecordService::paginate()` / `MedicalRecordRepository::paginateForBranches()`
now have no caller; left in place to keep the diff to this feature.

## Query and performance

Three steps, constant query count (verified by test across page sizes):

1. `COUNT` over the eligibility predicate alone (EXISTS legs hash).
2. A page of ids ordered by `GREATEST(MAX(created_at) per readable source)`,
   id tie-break (`MAX(...)` scalar on SQLite). Only readable sources contribute,
   so an unreadable archive cannot reorder — and thereby hint at — a patient.
3. Hydration + per-row counts for THOSE ids only.

| Query | Production (19 eligible) | Synthetic PG16 (50k patients, 120k native, 20k legacy, 31k eligible) |
|---|---|---|
| count | 0.79 ms | 284 ms |
| page ids | 0.82 ms | 615 ms |
| indicators (15 rows) | 0.54 ms | 0.12 ms |
| summary (3 counts) | ~1.2 ms | 646 ms |

The first implementation computed six correlated subqueries for every eligible
patient before the sort and re-ran them in the count: synthetic page 1.99 s,
count 905 ms. Restructured before merge.

Indexes: every leg already has a `patient_id`-leading index
(`trx_medical_records_patient_id_index`, `trx_rme_legacy_records_patient_status_index`,
`trx_odo_legacy_records_patient_status_idx`). **None added.** The remaining
synthetic page cost is the sort key across all eligible patients; a
`(patient_id, created_at)` index would be the next lever if the estate reaches
that scale — not justified at production's current size.

## Tests

`tests/Feature/RME/UnifiedMedicalRecordIndexTest.php` — 32 tests;
list matrix (native-only, legacy-RME-only, legacy-odontogram-only,
every combination as one row, staging-only, VOID-only, VOID + replacement,
none, soft-deleted), filters with per-source totals and summary, unknown
source, pagination after the union, ordering, search, native-only filters,
unparseable dates, permission-only refusal, cross-archive permission
separation, branch-scoped actor (list + crafted `branch_id` + workspace 404),
doctor clinical scope (same branch, outside practice branches), no-permission
403, viewer still policy-protected, no NIK, workspace with zero native records,
native workspace links, 404 parity, zero clinical mutation, constant queries.

**Mutation: 14 mutants, 14 killed.** Three survivors along the way were real
test gaps, all closed:

- **M4** (legacy read permission ignored) — the no-permission actor's branch
  fallback did not cover the record, so branch scope masked the missing
  permission check. Added a case where the branch matches.
- **M5** (soft-deleted patients) — hydration dropped the row a second time, so
  ids looked right while the total and the workspace were wrong. Now asserted.
- **M11** (count ignores source filter) — per-source totals were never
  asserted. Now asserted.

Verified on **PostgreSQL 16.15** (throwaway container; production's major):
the new suite plus `MedicalRecordTest`, `RmeClinicalDocumentationUixTest`,
`RoomAssignmentWorklistTest` — 112 passed.

**CI**: before adding the `UnifiedMedicalRecord` token the critical filter
selected 1 of the 30 tests, only because one description contained
"legacy_odontogram". Token added to both variants (verified identical); suite
declared in `critical_gate_mandatory_suites`; `CriticalGateSuiteCoverageTest`
passes.

## Security review

Independent adversarial review of the diff: **no CRITICAL, no HIGH.**

- **MEDIUM — fixed.** `DoctorPatientScopeService`'s two SQL scopes
  (`applyMedicalRecordScopeForUser`, `applyPatientScopeForDoctor`) counted a
  **soft-deleted** visit as a doctor's clinical relationship, while the
  canonical `doctorHasPatientAccess()` (Eloquent, `ClinicVisit` uses
  `SoftDeletes`) does not. The index could therefore list a patient — and the
  "Legacy RME · Tersedia" badge — that the canonical legacy viewer would refuse
  the same doctor. Fixed in the shared service by adding
  `whereNull('v.deleted_at')` to both, so SQL and canonical agree. This only
  narrows: the native record list had the same divergence and now matches the
  record policy too. Regression test fails without the fix, passes with it.
- **LOW — fixed.** With an empty legacy branch set but the governance
  NULL-branch allowance, the index matched NULL-origin rows while the record
  repositories' `scoped()` matches nothing. Aligned to `scoped()`.
- **LOW — inherited, NOT changed.** `LegacyOdontogramWorkspaceScope::GOVERNANCE_PERMISSIONS`
  includes `create_legacy_odontogram_imports`, so intake operators (Front
  Office / Admin Klinik) read the odontogram archive across every RME branch.
  The index faithfully inherits that canonical breadth and makes it browsable.
  It does not widen past the viewer; changing it is an owner decision about the
  odontogram archive scope, out of this sprint.

## Clinical mutation

NONE. Asserted by test (visit, medical record, legacy record and patient counts
unchanged across a render) and by construction (no write path).
