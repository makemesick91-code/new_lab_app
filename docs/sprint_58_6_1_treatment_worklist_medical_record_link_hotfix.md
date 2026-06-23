# Sprint 58.6.1 — Treatment Worklist Medical Record Link Hotfix

**Branch:** `feature/sprint-58-6-1-treatment-worklist-medical-record-link-hotfix`
**Type:** Minimal safe hotfix. No schema, no permission slugs, no cashier/payment changes.

## 1. Bug description

On `/rme/treatment-room-worklist` (`Daftar Pasien Ruang Perawatan`, route
`rme.treatment-room-worklist.index`), clicking **Buka Rekam Medis** returns **404**
for room-assigned patients that do not yet have a MedicalRecord.

## 2. Current wrong action discovered

`resources/views/rme/visits/room-worklist.blade.php:99` renders the button
**unconditionally** for every row:

```blade
<x-ui.button variant="primary" :href="route('rme.visits.medical-record.show', $visit)" ...>Buka Rekam Medis</x-ui.button>
```

The route name and `{clinicVisit}` param are correct, but
`MedicalRecordController@show` does `abort_if($medicalRecord === null, 404)`
(`MedicalRecordController.php:48-52`). Worklist patients are freshly room-assigned
and usually have **no** MedicalRecord yet (records are created via a separate
`store` POST), so the link 404s.

## 3. Expected correct route/action

Mirror the proven pattern already used on the visit Detail page
(`resources/views/rme/visits/show.blade.php:208-230`):

- **Existing record:** `GET rme.visits.medical-record.show` ({clinicVisit}) — "Buka Rekam Medis".
- **No record yet:** `POST rme.visits.medical-record.store` ({clinicVisit}), guarded by
  `@can('create', [MedicalRecord::class, $visit])` — "Mulai Rekam Medis". `store`
  creates the draft then redirects to the show route.

## 4. MedicalRecord existence behavior

- A MedicalRecord is NOT auto-created; it is created explicitly via `store` → `createDraft`.
- `MedicalRecordService::createDraft` (`MedicalRecordService.php:68-72`) already throws if a
  record already exists for the visit → **no duplicate risk**.
- `MedicalRecordPolicy::create` requires `manage_clinic_visits` + active RME branch.
  Both worklist-visible roles (Doctor, Perawat) hold `manage_clinic_visits`
  (RoleSeeder.php:191/228) → no 403, no gate mismatch.

## 5. Files inspected

- `resources/views/rme/visits/room-worklist.blade.php` (broken link line 99)
- `resources/views/rme/visits/show.blade.php` (correct reference pattern, 208-230)
- `routes/web.php` (`rme.visits.medical-record.*`, no `rme.medical-records.show`)
- `app/Modules/MedicalRecord/Controllers/MedicalRecordController.php` (show/store)
- `app/Modules/MedicalRecord/Services/MedicalRecordService.php` (createDraft dup guard)
- `app/Modules/MedicalRecord/Policies/MedicalRecordPolicy.php` (create → manage_clinic_visits)
- `app/Modules/ClinicVisit/Controllers/ClinicVisitController.php` (roomWorklist)
- `app/Modules/ClinicVisit/Services/ClinicVisitService.php` (roomWorklist)
- `app/Modules/ClinicVisit/Repositories/ClinicVisitRepository.php` (worklistForBranches eager loads)
- `app/Modules/ClinicVisit/Models/ClinicVisit.php` (medicalRecord HasOne)
- `database/seeders/RoleSeeder.php` (Doctor/Perawat permissions)

## 6. Files to change

1. `app/Modules/ClinicVisit/Repositories/ClinicVisitRepository.php` — add `medicalRecord`
   to the `worklistForBranches` eager-load `with([...])` (avoid N+1 in the blade branch).
2. `resources/views/rme/visits/room-worklist.blade.php` — conditional action mirroring
   the Detail page.
3. Test file under `tests/Feature/RME/` (RoomAssignmentWorklist coverage).

## 7. Route/link design

Per-row Aksi cell:

```blade
@if ($visit->medicalRecord)
    {{-- Buka Rekam Medis → show --}}
@elseif (...can create...)
    {{-- Mulai Rekam Medis → POST store --}}
@else
    {{-- — (no action; cannot create) --}}
@endif
```

No new routes, no controller changes.

## 8. Branch isolation review

- Worklist query already scoped to RME-enabled branch IDs (`scopeBranchIds`).
- `MedicalRecordPolicy::create` / `createDraft` re-validate active RME branch.
- Eager-loading `medicalRecord` does not widen branch scope. No change.

## 9. Access control review

- Route gate unchanged (`view_treatment_worklist`).
- `store` still gated by `manage_clinic_visits` + `create` policy; the button only
  renders when `@can('create')` passes, so no role can trigger a 403 from the worklist.

## 10. Privacy review

- No new fields exposed. Only renders a status-dependent action button. `medicalRecord`
  relation is used for existence/id only, no clinical content surfaced on the list.

## 11. Test plan (tests/Feature/RME)

1. Worklist "Buka Rekam Medis" link for a room-assigned visit WITH an existing record →
   200 (no 404), points at the show route for that visit.
2. Worklist for a room-assigned visit WITHOUT a record → page renders "Mulai Rekam Medis"
   POST form; following store creates exactly one record then show → 200.
3. Opening an existing record does not create a duplicate (count stays 1).
4. Unauthorized role cannot access the worklist (403).
5. Branch isolation: visit from a non-active RME branch not listed.

## 12. Risk checklist

- [x] No schema/migration change
- [x] No permission slug/role change
- [x] No cashier/payment/invoice/receivable change
- [x] No duplicate MedicalRecord (createDraft dup guard + existence branch)
- [x] No broad workflow rewrite
- [x] Branch isolation preserved
- [x] N+1 avoided via eager load

## 13. Rollback plan

Revert the two source files (repository eager-load line + blade action block) and the
added test. No data migration to undo. Pre-hotfix baseline: branch
`feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`.
