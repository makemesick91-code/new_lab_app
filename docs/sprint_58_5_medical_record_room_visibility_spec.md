# Sprint 58.5 — Medical Record Room Visibility Hotfix — Implementation Spec

**Branch:** `feature/sprint-58-5-medical-record-room-visibility-hotfix`
**Date:** 2026-06-23
**Mode:** LIMIT SAVER 1 — minimal safe fix, display-only.

## 1. Goal
Show the assigned room (`Ruangan`) on the RME Medical Records list page so doctors
can answer "Pasien ini ada di ruangan mana?" / "Pasien mana yang harus saya kerjakan?".

## 2. Current Medical Records page behavior
- Route `rme.medical-records.index` → `MedicalRecordController@index`.
- Service `MedicalRecordService::paginate()` → `MedicalRecordRepository::paginateForBranches(rmeEnabledIds(), $filters)`.
- Repository eager loads `['clinicVisit', 'patient', 'doctor', 'recordedBy']`, scoped `whereIn('branch_id', $branchIds)`.
- View `resources/views/rme/visits/medical-record/index.blade.php` renders a 7-column table:
  Tanggal Kunjungan, Nomor Kunjungan, Pasien, Dokter, Status, Difinalisasi Pada, Aksi.
- No room column currently exists.

## 3. Expected behavior
- An additional `Ruangan` column appears in the table, showing the clinic visit's room name.
- When no room is assigned (`clinic_room_id` is null), a safe fallback `—` is shown.

## 4. Non-goals
- No new room table/system, no migration (room data already exists).
- No change to visit/registration/finalization/cashier/payment/branch-isolation logic.
- No authorization/policy/permission/role changes.
- No sensitive field exposure (only room name).

## 5. Files inspected
- `app/Modules/MedicalRecord/Controllers/MedicalRecordController.php`
- `app/Modules/MedicalRecord/Services/MedicalRecordService.php`
- `app/Modules/MedicalRecord/Repositories/MedicalRecordRepository.php`
- `app/Modules/MedicalRecord/Models/MedicalRecord.php`
- `app/Modules/ClinicVisit/Models/ClinicVisit.php`
- `app/Modules/ClinicRoom/Models/ClinicRoom.php`
- `resources/views/rme/visits/medical-record/index.blade.php`
- `database/factories/ClinicVisitFactory.php`, `database/factories/ClinicRoomFactory.php`
- `tests/Feature/RME/MedicalRecordTest.php`

## 6. Files expected to change
1. `app/Modules/MedicalRecord/Repositories/MedicalRecordRepository.php` — add `clinicVisit.clinicRoom` eager load (both paginate methods, prevent N+1).
2. `resources/views/rme/visits/medical-record/index.blade.php` — add `Ruangan` header + cell; bump empty-state `colspan` 7 → 8.
3. `tests/Feature/RME/MedicalRecordTest.php` — add room-visibility + fallback tests.
4. `docs/sprint_58_5_medical_record_room_visibility_spec.md` — this spec.

## 7. Room data source discovered
- `MedicalRecord::clinicVisit()` → `belongsTo(ClinicVisit, clinic_visit_id)`.
- `ClinicVisit::clinicRoom()` → `belongsTo(ClinicRoom, clinic_room_id)` (FK nullable, default null).
- `ClinicRoom` table `mst_clinic_rooms`, human-readable column `name`.
- Access path: `$record->clinicVisit?->clinicRoom?->name`.

## 8. Query/eager-load design
Add `'clinicVisit.clinicRoom'` to the `->with([...])` array in `paginateForBranches()`
(and mirror in `paginateForBranch()` for consistency). No new query, no filter change,
no branch-scope change. Prevents N+1 on the room relation.

## 9. UI placement design
Insert a `Ruangan` column after `Nomor Kunjungan` (a doctor scans visit number → room → patient).
Header `<th class="px-3 py-3 font-medium">Ruangan</th>` and cell
`<td class="px-3 py-3 text-gray-600">{{ $record->clinicVisit?->clinicRoom?->name ?? '—' }}</td>`.
Matches existing Tailwind cell styling; no overflow risk (short room name).

## 10. Fallback design if room empty
Null-safe `?->` chain with `?? '—'` (matches the existing `—` fallback used by other cells).

## 11. Branch isolation design
No change. Repository still uses `whereIn('branch_id', rmeEnabledIds())`. The room is read
through the already-branch-scoped medical record's visit. A new test asserts a record in a
non-RME-enabled branch (and its room) is not shown.

## 12. Privacy design
Only `clinicRoom.name` is rendered. No KTP, phone, WhatsApp, address, diagnosis, treatment
notes, token, password, or `.env`. A test asserts none of these appear on the page.

## 13. Test plan (`tests/Feature/RME/MedicalRecordTest.php`)
1. Index shows room name when the visit has a room.
2. Index shows `—` fallback when the visit has no room.
3. Branch isolation: another branch's room name not shown.
4. Page does not expose sensitive fields (sanity assertion).

## 14. Risk checklist
- [ ] No schema/migration change.
- [ ] No permission/policy/role change.
- [ ] No payment/generation/conversion change.
- [ ] No branch-isolation regression.
- [ ] No sensitive data exposure.
- [ ] Existing RME tests still pass.
- [ ] Pint clean, `git diff --check` clean.

## 15. Rollback plan
Revert the three code/test files (or `git revert` the single commit). No data/schema/state
to undo — change is additive display-only.
