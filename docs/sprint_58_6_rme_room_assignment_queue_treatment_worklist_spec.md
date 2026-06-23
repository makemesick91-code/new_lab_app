# Sprint 58.6 — RME Room Assignment Queue & Treatment Room Worklist (Implementation Spec)

**Branch:** `feature/sprint-58-6-rme-room-assignment-queue-treatment-worklist`
**Date:** 2026-06-23
**Mode:** LIMIT SAVER 1 — minimal safe change. No deploy, no GO tag, no VPS, no schema change.

## 1. Goal
Move room selection out of patient registration. Admin Klinik assigns a room from the
existing RME queue (Daftar Kunjungan) before treatment. Doctors/nurses (Perawat) get a new
dedicated worklist page that shows only patients already placed in a treatment room.
Preserve Sprint 58.5 medical-records Ruangan column, Sprint 58.4 RME dashboard, and the
Admin Warehouse inventory dashboard.

## 2. Current patient registration flow
- `ClinicVisitController@create` / `@store` (`app/Modules/ClinicVisit/Controllers/ClinicVisitController.php`).
- Shared form `resources/views/rme/visits/_form.blade.php` currently renders a
  `Ruangan (opsional)` `<select name="clinic_room_id">` (lines ~184-192).
- `StoreClinicVisitRequest` validates `clinic_room_id` as `nullable|integer|exists`.
- `ClinicVisitService@create` persists `clinic_room_id` from validated data; new visits start
  `status = registered`.

## 3. Current queue/antrian flow
- The queue IS `rme.visits.index` → `ClinicVisitController@index` → `resources/views/rme/visits/index.blade.php`.
- Visits listed across all active RME-enabled branches via `ClinicVisitService@paginate` →
  `ClinicVisitRepository@paginateForBranches` (branch-scoped, MAIN excluded).
- Columns: No. Kunjungan, Antrian, Pasien (+RM), Klinik/Cabang, Dokter, Tanggal, Status, Aksi.

## 4. Current room field/relationship
- Column `trx_clinic_visits.clinic_room_id` — **already exists, nullable** (in `$fillable`, cast int).
- `ClinicVisit::clinicRoom()` BelongsTo `ClinicRoom` (`mst_clinic_rooms`, columns `branch_id`, `name`, `status`).
- **No migration required.**

## 5. Current treatment/medical-record start flow
- Doctor opens RME via `rme.visits.medical-record.show` → `MedicalRecordController@show(ClinicVisit)`.
- Store/finalize/handwriting routes gated `permission:manage_clinic_visits`. **Unchanged.**

## 6. Current cashier/payment completion flow
- `rme.cashier.*` group gated `permission:manage_rme_billing`. Finalized RME → cashier_pending →
  payment → completed. **Not touched by this sprint.**

## 7. Expected new workflow
1. Registration: no room selector → visit created with `clinic_room_id = null`.
2. Queue (`rme.visits.index`): Admin Klinik sees a Ruangan column with `Belum dipilih` +
   inline assign form (rooms scoped to the visit's branch).
3. Admin Klinik assigns room (PATCH `rme.visits.assign-room`).
4. Doctor/Perawat open new worklist `rme.treatment-room-worklist.index` showing only
   room-assigned, non-terminal visits; action `Buka Rekam Medis` → existing medical-record show.
5. Treatment / cashier / completion: unchanged.

## 8. Files inspected
- `app/Modules/ClinicVisit/Controllers/ClinicVisitController.php`
- `app/Modules/ClinicVisit/Services/ClinicVisitService.php`
- `app/Modules/ClinicVisit/Repositories/ClinicVisitRepository.php` + interface
- `app/Modules/ClinicVisit/Requests/{Store,Update}ClinicVisitRequest.php`
- `app/Modules/ClinicVisit/Policies/ClinicVisitPolicy.php`
- `app/Modules/ClinicVisit/Models/ClinicVisit.php`, `ClinicRoom/Models/ClinicRoom.php`
- `resources/views/rme/visits/{_form,index}.blade.php`
- `resources/views/layouts/partials/sidebar.blade.php`
- `database/seeders/{Permission,Role}Seeder.php`
- `routes/web.php` (rme group), `tests/Pest.php`, `tests/Feature/RME/*`

## 9. Files expected to change
- `database/seeders/PermissionSeeder.php` — add `view_treatment_worklist`.
- `database/seeders/RoleSeeder.php` — grant it to `Doctor` + `Perawat`.
- `routes/web.php` — add worklist GET + assign-room PATCH inside rme clinical group.
- `app/Modules/ClinicVisit/Controllers/ClinicVisitController.php` — `roomWorklist()`, `assignRoom()`.
- `app/Modules/ClinicVisit/Services/ClinicVisitService.php` — `roomWorklist()`, `assignRoom()`.
- `app/Modules/ClinicVisit/Repositories/ClinicVisitRepository.php` + interface — `worklistForBranches()`.
- `app/Modules/ClinicVisit/Requests/AssignRoomRequest.php` — new FormRequest.
- `resources/views/rme/visits/_form.blade.php` — remove room selector.
- `resources/views/rme/visits/index.blade.php` — add Ruangan column + inline assign form.
- `resources/views/rme/visits/room-worklist.blade.php` — new worklist view.
- `resources/views/layouts/partials/sidebar.blade.php` — add `Ruang Perawatan` link.
- `tests/Feature/RME/RoomAssignmentWorklistTest.php` — new tests.

## 10. Database/schema review
`clinic_room_id` nullable already present; `mst_clinic_rooms.branch_id` present. No tables/columns added.

## 11. Migration needed?
**No.** Hard-stop condition (schema lacks nullable room) is NOT triggered.

## 12. Route design (inside `rme.` group, clinical sub-group)
- `GET  rme/treatment-room-worklist` → `rme.treatment-room-worklist.index`
  → `ClinicVisitController@roomWorklist`; `middleware('permission:view_treatment_worklist')`.
- `PATCH rme/visits/{clinicVisit}/room` → `rme.visits.assign-room`
  → `ClinicVisitController@assignRoom`; `middleware('permission:manage_clinic_visits')`.

## 13. Controller/service/repository design
- `ClinicVisitService@roomWorklist(filters)` → reuses `scopeBranchIds()`;
  repo `worklistForBranches(branchIds, filters)`: `whereNotNull(clinic_room_id)`,
  `whereNotIn(status, [completed, cancelled, cashier_pending])`, optional `clinic_room_id` +
  `search` filters; eager-load patient, doctor, clinicRoom, branch; ordered by branch/room/queue.
- `ClinicVisitService@assignRoom(visit, roomId)`: load `ClinicRoom`; throw `ValidationException`
  if room missing / not active / `room.branch_id !== visit.branch_id`; persist `clinic_room_id`.
- Controller `assignRoom` authorizes `update` policy (manage + RME branch) then calls service.

## 14. UI — Admin Klinik room assignment (queue)
New `Ruangan` column in `index.blade.php`: shows current room name or `Belum dipilih`; when visit
non-terminal and user `@can('update', $visit)`, inline `<form PATCH assign-room>` with a
`<select>` of rooms for `$visit->branch_id` (passed grouped as `$roomsByBranch`) + `Simpan Ruangan`.

## 15. UI — Doctor/Perawat worklist
New `room-worklist.blade.php` using `x-settings-shell`, `x-ui.card/table/badge/button`. Columns:
Antrian, No. Kunjungan, RM, Nama Pasien, Ruangan, Dokter, Status, Tanggal, Aksi (`Buka Rekam Medis`).
Room + status filters. Empty state: `Belum ada pasien yang sudah ditempatkan ke ruang perawatan.`

## 16. Access control design
- New permission `view_treatment_worklist` (additive; no rename/delete of existing slugs).
- Granted to `Doctor` + `Perawat`; `Super Admin` bypasses via `*`. Admin Klinik/Kasir/Owner do NOT
  get it (they keep queue/billing/report access). Worklist route gated by this permission.
- Room assignment reuses existing `manage_clinic_visits` + `ClinicVisitPolicy@update`.

## 17. Branch isolation design
- Worklist + counts route through `ClinicVisitService::scopeBranchIds()` (active RME set only).
- `assignRoom` rejects a room whose `branch_id` differs from the visit's branch.
- Policy `update`/`view` require `belongsToActiveRmeBranch`.

## 18. Privacy design
Worklist/queue expose operational fields only: RM number, patient name, queue/visit number,
room name, status, doctor, visit date. No KTP, phone, WhatsApp, address, diagnosis, notes,
tokens, passwords, or `.env`.

## 19. Test plan (`tests/Feature/RME/RoomAssignmentWorklistTest.php`)
1. Registration create page shows no `clinic_room_id` selector.
2. New visit registration (no room field) creates visit with null room.
3. Admin Klinik assigns current-branch room → persisted.
4. Admin Klinik cannot assign another branch's room → error + unchanged.
5. Doctor can access worklist.
6. Perawat can access worklist.
7. Role without `view_treatment_worklist` (e.g. Kasir) gets 403.
8. Worklist shows a room-assigned visit.
9. Worklist hides a visit without room.
10. Worklist hides another branch's visit when filtered (branch isolation).
11. Worklist does not expose sensitive fields (KTP/phone/address).
12. `/rme/medical-records` still shows Ruangan column (regression).
13. `/rme/dashboard` still loads (regression).

## 20. Risk checklist
- No schema/migration change. ✅
- No permission slug rename/delete; one additive slug. ✅
- No cashier/payment/receivable/invoice change. ✅
- No broad authorization rewrite — reuse existing policy + permission convention. ✅
- Branch scoping reuses existing service helper. ✅
- Existing room-related tests preserved (validation rules untouched). ✅
- New permission requires seeder re-run on deploy (documented; out of scope here).

## 21. Rollback plan
Revert the Sprint 58.6 commit. No data migration to undo; `clinic_room_id` values remain valid.
Re-running `PermissionSeeder`/`RoleSeeder` from a prior revision is harmless (additive slug only).
