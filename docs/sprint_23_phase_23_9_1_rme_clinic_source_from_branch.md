# Sprint 23 Phase 23.9.1 — RME Clinic Source from Branch Master

## 1. Summary

- **Status:** Implemented locally, focused tests green. Not deployed, not pushed.
- **Branch:** `feature/sprint-23-phase-23-9-1-rme-clinic-branch-source`
- **Base:** `sprint-23-phase-23-8-patient-id-registration-flow` (commit `6277bbb`)
- **Commit:** HEAD of the branch, tagged `sprint-23-phase-23-9-1-rme-clinic-branch-source`.
- **Tag:** `sprint-23-phase-23-9-1-rme-clinic-branch-source` (applied after tests pass)
- **Scope:** RME patient registration + RME visit creation now source the
  "Klinik" choice from the branch master (Cabang RME), not from the legacy
  `mst_clinics` master. Display + validation updated. No payment / lab
  generation / conversion logic touched. Legacy clinic master preserved.

## 2. Business Rule

**Klinik = Cabang RME.**

Source of truth for RME Klinik/Cabang choices:

```
mst_branches
where is_active = true
  and is_rme_enabled = true
order by name
```

The legacy `mst_clinics` master is **no longer** used as the source for new RME
patient / visit Klinik choices. `MAIN` is the technical fallback branch only
(`is_rme_enabled = false`) and is hidden from operational RME dropdowns.

## 3. Operational Branches

- TKM1 — Cabang Telkomas
- LDK2 — Cabang Landak
- ATG3 — Cabang Antang

MAIN is the technical fallback only and is hidden from operational RME /
Inventory dropdowns.

## 4. Patient Registration Impact

- Patient create/edit already sourced `branch_id` from active RME branches
  (Phase 23.8). This phase keeps that source authoritative.
- `branch_id` populates `mst_patients.branch_id`.
- `medical_record_number` is composed from the selected `branch.code`
  (`RM DG-{KODE_CABANG}-{TAHUN_DAFTAR}-{NOMOR_RM_MANUAL}`).
- Preview uses the selected branch code.
- Example: TKM1 + 2026 + 0001 → `RM DG-TKM1-2026-0001`.

## 5. RME Visit Impact

- The visit "Klinik" dropdown is replaced by **Klinik/Cabang (Cabang RME)**,
  sourced from `BranchService::listRmeEnabled()`. Field name: `branch_id`.
- Visit `branch_id` now follows the selected RME branch:
  - **New-patient mode:** the new patient's `branch_id` (the chosen Cabang RME)
    drives **both** the patient branch and the visit branch.
  - **Existing-patient mode:** the selected `branch_id` drives the visit branch.
  - **Fallback:** when no branch is submitted (legacy / programmatic callers),
    `BranchContext::requireId()` is used. The operational form always requires a
    Cabang RME selection.
- `clinic_id` is no longer collected on the RME visit form. New RME visits store
  `clinic_id = null`.
- Visit show now displays **Klinik/Cabang** as `{code} — {name}` (falls back to
  the legacy clinic name for old rows that still carry `clinic_id`).

## 6. Legacy Compatibility

- `mst_clinics` is **not** dropped.
- `clinic_id` columns are **not** dropped. They are made **nullable**
  (additive migration) so RME records can omit them.
- Old patient / visit rows are not rewritten — they keep their `clinic_id`.
- Legacy Klinik master settings routes still work (`settings.clinics.*`).
- Lab remains global / not branch-enforced. Inventory remains
  inventory-enabled-branch aware. RME uses RME-enabled branches.

## 7. Tests

**Commands run:**

```
php artisan test --filter=RmeClinicSourceFromBranchTest
php artisan test --filter="ClinicVisit|Patient|Rme|RME"
./vendor/bin/pint --dirty
npm run build
```

**Results:**

- `RmeClinicSourceFromBranchTest`: 15 passed (32 assertions).
- Visit / Patient / RME focused suites: _(see Section 11 / final report)_.

**Known limitations:** Full suite not run in this phase per limit-saver
guidance; focused RME/Patient/visit/branch/dashboard/permission/sidebar filters
used instead.

## 8. Files Changed

**Migrations**

- `database/migrations/2026_06_17_100001_make_clinic_id_nullable_for_rme_branch_source.php`
  — make `trx_clinic_visits.clinic_id` and `mst_patients.clinic_id` nullable.

**Requests / controllers / services**

- `app/Modules/ClinicVisit/Requests/StoreClinicVisitRequest.php`
  — add RME `branch_id` rule; `clinic_id` now nullable.
- `app/Modules/ClinicVisit/Services/ClinicVisitService.php`
  — `resolveBranchId()`; visit branch follows selected RME branch; patient
  created with branch + nullable clinic.
- `app/Modules/ClinicVisit/Controllers/ClinicVisitController.php`
  — stop passing the legacy clinic list to the visit create form.

**Views**

- `resources/views/rme/visits/_form.blade.php` — Klinik/Cabang dropdown uses
  RME branches (`branch_id`).
- `resources/views/rme/visits/create.blade.php` — drop `clinics` include arg.
- `resources/views/rme/visits/show.blade.php` — display Klinik/Cabang branch.

**Tests**

- `tests/Feature/RME/RmeClinicSourceFromBranchTest.php` — new (15 tests).
- `tests/Feature/RME/ClinicVisitTest.php` — replace the old
  "ignores branch_id" test with selected-RME-branch + fallback tests.

**Docs**

- `docs/sprint_23_phase_23_9_1_rme_clinic_source_from_branch.md` (this file).
- `docs/sprint_history.md` — Phase 23.9.1 entry.

## 9. Watch Items

- **VPS deploy needs a DB backup even though there is a migration** — the
  migration alters column nullability on `trx_clinic_visits` and `mst_patients`.
  Always backup before `php artisan migrate --force`. Never `migrate:fresh` /
  `db:wipe`.
- Confirm browser smoke after deploy: (1) patient create, (2) RME visit with an
  existing patient, (3) RME visit with a new patient.
- Confirm MAIN is hidden from the Klinik/Cabang dropdown.
- Confirm the legacy clinic master is not used by new RME flows.
- Decide separately whether the old Klinik master should be archived / renamed
  later (out of scope here).
- `BranchContext` fallback is intentionally retained for legacy/programmatic
  visit callers and for dashboard/default context. The operational visit form
  requires an explicit Cabang RME selection.

## 10. Next Recommended Phase

**Sprint 23 Phase 23.9.2 — VPS Deploy + RME Clinic Source Smoke.**

Deploy this branch following the Phase 21.7 VPS checklist (backup first,
`migrate --force` only), then run the browser smoke checklist above.
