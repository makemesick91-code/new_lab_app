# Sprint 58.7 — RME Registered Patient Queue Page (Antrian Pasien)

Branch: `feature/sprint-58-7-rme-registered-patient-queue-page`
Mode: LIMIT SAVER 1 — minimal safe feature. No schema/migration changes. No deploy. No GO tag.

## 1. Goal
Add a dedicated, focused queue page (`Antrian Pasien`) for **already-registered RME
patients** so Admin Klinik can manage the post-registration queue (assign a treatment
room) before patients enter treatment rooms. The existing `/rme/visits` page and the
treatment room worklist are preserved.

## 2. Current registration flow
Admin Klinik registers a visit via `rme.visits.store` →
`ClinicVisitService::create()` sets `status = registered`, `clinic_room_id = null`,
generates `queue_number` (branch+date scoped) and `visit_number`. No room is chosen at
registration (Sprint 58.6).

## 3. Current `/rme/visits` behavior
`ClinicVisitController@index` → `rme.visits.index`. Full visit list across the active
RME-enabled branch set (`ClinicVisitService::paginate`), with widgets, branch filter,
status/search/date filters, RM cross-branch lookup, and an **inline room-assignment
form** per row (Sprint 58.6) posting to `rme.visits.assign-room`.

## 4. Current room assignment behavior
- Route: `PATCH rme/visits/{clinicVisit}/room` → `rme.visits.assign-room`,
  middleware `permission:manage_clinic_visits`.
- Controller `assignRoom(AssignRoomRequest, ClinicVisit)` → `authorize('update')` →
  `ClinicVisitService::assignRoom()`.
- `ClinicVisitService::assignRoom()` enforces: room exists + active, **same branch as
  the visit** (cross-branch rejected via `ValidationException`).
- `AssignRoomRequest`: `clinic_room_id` required|integer|exists. Authorization at route.
- This route/action is **reused as-is** by the new queue page. No duplication.

## 5. Current treatment room worklist behavior
`ClinicVisitController@roomWorklist` → `rme.visits.room-worklist`, extra gate
`view_treatment_worklist`. Lists **room-assigned, non-terminal** visits
(`worklistForBranches`: `clinic_room_id NOT NULL`, status not in
`cashier_pending/completed/cancelled`). Action opens visit detail (`rme.visits.show`).

## 6. Definition of "registered patient queue"
Active, non-terminal RME visits across the active RME-enabled branch set, i.e. status in
`{registered, waiting, in_progress}` (exclude `cashier_pending`, `completed`,
`cancelled` — same terminal set the worklist excludes). Includes **both** visits without
a room (`Belum dipilih`) and visits with a room (ready for treatment). Branch-scoped via
the same `scopeBranchIds()` used everywhere in the RME visit flow. No forced date filter
(matches worklist behavior); an optional date filter is offered.

## 7. Files inspected
- `routes/web.php` (rme group, L201–239)
- `app/Modules/ClinicVisit/Controllers/ClinicVisitController.php`
- `app/Modules/ClinicVisit/Services/ClinicVisitService.php`
- `app/Modules/ClinicVisit/Repositories/ClinicVisitRepository.php`
- `app/Modules/ClinicVisit/Interfaces/ClinicVisitRepositoryInterface.php`
- `app/Modules/ClinicVisit/Requests/AssignRoomRequest.php`
- `app/Modules/ClinicVisit/Models/ClinicVisit.php` (statuses)
- `app/Modules/ClinicRoom/Models/ClinicRoom.php`, `Branch/Services/BranchContext.php`
- `resources/views/rme/visits/index.blade.php` (inline assign-room form)
- `resources/views/rme/visits/room-worklist.blade.php`
- `resources/views/layouts/partials/sidebar.blade.php` (RME menu)
- `tests/Feature/RME/RoomAssignmentWorklistTest.php`, `tests/Pest.php` helpers
- `database/factories/ClinicVisitFactory.php`

## 8. Files expected to change
- `routes/web.php` — add `rme.patient-queue.index` GET route in the rme group.
- `app/Modules/ClinicVisit/Controllers/ClinicVisitController.php` — add `patientQueue()`.
- `app/Modules/ClinicVisit/Services/ClinicVisitService.php` — add `registeredQueue()`.
- `app/Modules/ClinicVisit/Repositories/ClinicVisitRepository.php` — add `queueForBranches()`.
- `app/Modules/ClinicVisit/Interfaces/ClinicVisitRepositoryInterface.php` — add signature.
- `resources/views/rme/patient-queue/index.blade.php` — new view (created).
- `resources/views/layouts/partials/sidebar.blade.php` — add `Antrian Pasien` item.
- `tests/Feature/RME/PatientQueueTest.php` — new test file (created).
- `CLAUDE.md` — append Sprint 58.7 note.

## 9. Route design
```
Route::get('patient-queue', [ClinicVisitController::class, 'patientQueue'])
    ->name('patient-queue.index');
```
Inside the existing `rme.` group (so prefix `rme/`, gate `view_clinic_visits|manage_clinic_visits`).
Path `rme/patient-queue` does not collide with the `visits` resource. Route name:
`rme.patient-queue.index`.

## 10. Controller/service/repository design
- Controller `patientQueue(Request)`: `authorize('viewAny', ClinicVisit::class)`; build
  `$filters` (search, status, room_status, visit_date, branch_id); return view with
  `visits => $this->visits->registeredQueue($filters)`, `statuses`,
  `roomsByBranch => $this->visits->activeRoomsByRmeBranch()`, `filters`.
- Service `registeredQueue(array $filters, int $perPage = 20)`: delegates to
  `queueForBranches(scopeBranchIds($filters['branch_id'] ?? null), $filters, $perPage)`.
- Repo `queueForBranches`: `whereIn branch_id`, `whereNotIn status [cashier_pending,
  completed, cancelled]`, optional `room_status` (`unassigned` → whereNull,
  `assigned` → whereNotNull), optional `status`, optional `visit_date`, search across
  `visit_number` / patient `name` + `medical_record_number`; eager load
  `patient, doctor, clinicRoom, branch`; order `visit_date desc, queue_number`.
- Room assignment: **reuse** `rme.visits.assign-room` + `ClinicVisitService::assignRoom`.

## 11. UI design
New blade `rme/patient-queue/index.blade.php` modeled on the worklist + index inline
form. `x-settings-shell`, `x-ui.card/table/badge/button`. Columns: No. Antrian, Waktu
Daftar (visit_date), RM, Nama Pasien, Dokter, Status, Ruangan (inline assign form when
`can('update', $visit)`, else `Belum dipilih`/room name), Aksi (Detail). Filters: search,
room status (Semua / Belum dipilih / Sudah dipilih), status, date. Responsive table,
no overflow.

## 12. Access control design
- View: existing group gate `view_clinic_visits|manage_clinic_visits` (Admin Klinik,
  Doctor, Perawat). `authorize('viewAny')` in controller.
- Assign room: unchanged — `manage_clinic_visits` + policy `update`. Inline form only
  rendered when `auth()->user()->can('update', $visit)`.
- Unauthorized roles (e.g. Kasir without clinic-visit perms) get 403.
- No new permission slug. No role rewrite.

## 13. Branch isolation design
Queue query scoped by `scopeBranchIds()` (active RME-enabled set, optional single RME
branch). Room selector per row uses `activeRoomsByRmeBranch()->get($visit->branch_id)` —
only same-branch active rooms. Assignment still validated same-branch in the service.

## 14. Privacy design
View renders only: queue_number, visit_number, medical_record_number, patient name,
doctor name, status, room name, visit_date. No KTP / phone / WhatsApp / address /
diagnosis / treatment note / password / token / `.env`.

## 15. Test plan (`tests/Feature/RME/PatientQueueTest.php`)
1. Registered patient appears on the queue page.
2. Patient without room shows `Belum dipilih`.
3. Patient with room shows the room name.
4. Search by patient / RM / visit number works.
5. Room status filter `Belum dipilih` (unassigned) works.
6. Room status filter `Sudah dipilih` (assigned) works.
7. Admin Klinik assigns a same-branch room from the queue (reuses assign-room).
8. Cross-branch room assignment is rejected.
9. No cross-branch patient leak (branch filter).
10. No sensitive fields exposed.
11. Unauthorized role (Kasir) cannot access (403).
12. Terminal visits (completed/cancelled) are excluded from the queue.
13. Existing `/rme/visits` still works.
14. Existing `/rme/dashboard` still works.

## 16. Risk checklist
- No schema/migration change. No dependency change. No payment/cashier logic touched.
- No permission slug rename/delete; no broad auth rewrite.
- Room assignment logic not duplicated — existing route/service reused.
- Resource route collision avoided (distinct `patient-queue` path).
- Existing pages (`/rme/visits`, worklist, show, medical-records, dashboard) untouched.

## 17. Rollback plan
Pure additive change. Revert the feature commit (route + controller method + service +
repo + interface + new view + sidebar line + test + doc). No data migration to undo.
