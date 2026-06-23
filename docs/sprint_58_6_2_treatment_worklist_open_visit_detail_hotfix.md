# Sprint 58.6.2 — Treatment Worklist: Open Visit Detail Hotfix

**Branch:** `feature/sprint-58-6-2-treatment-worklist-open-visit-detail-hotfix`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Mode:** LIMIT SAVER 1 — minimal safe hotfix. No deploy, no GO tag, no schema/permission/cashier changes.

## 1. Requirement clarification
From the treatment room worklist (`/rme/treatment-room-worklist`), Doctor/Perawat must open the
**patient/visit detail page first**, not the MedicalRecord page directly. The detail page lets
Doctor/Perawat reach **both** Rekam Medis **and** Odontogram (plus other visit actions). The previous
Sprint 58.6.1 behavior (worklist action goes straight to MedicalRecord show/store) is too narrow because
it does not give access to the Odontogram.

## 2. Current (insufficient) behavior
`resources/views/rme/visits/room-worklist.blade.php` action cell (lines 98–109):
- If `$visit->medicalRecord` exists → "Buka Rekam Medis" → `rme.visits.medical-record.show`.
- Else (can create) → POST "Mulai Rekam Medis" → `rme.visits.medical-record.store`.
Both lead only into the MedicalRecord flow — no Odontogram path.

## 3. Expected behavior
Worklist action opens the **visit detail page** via `route('rme.visits.show', $visit)` with label
**"Buka Detail Pasien"**. From there the existing detail page exposes Rekam Medis and Odontogram.

## 4. Correct visit detail route discovered
`rme.visits.show` → `GET rme/visits/{clinicVisit}` → `ClinicVisitController@show`.
Authorization: `$this->authorize('view', $clinicVisit)`. Policy `view` = (`view_clinic_visits` OR
`manage_clinic_visits`) AND visit branch is an active RME branch. Doctor/Perawat hold `view_clinic_visits`,
and the worklist only lists active-RME-branch visits → access guaranteed for worklist rows.

## 5. Visit detail already exposes Rekam Medis? YES
`show.blade.php` lines 209–230: "Lihat Rekam Medis" (`rme.visits.medical-record.show`) when a record
exists, else policy-gated "Buat Rekam Medis" (POST `rme.visits.medical-record.store`).

## 6. Visit detail already exposes Odontogram? YES
`show.blade.php` lines 233–240: policy-gated "Buka Odontogram" → `rme.visits.odontogram.show`.
No change needed to the detail page.

## 7. Files inspected
- `resources/views/rme/visits/room-worklist.blade.php`
- `resources/views/rme/visits/show.blade.php`
- `routes/web.php` (route list)
- `app/Modules/ClinicVisit/Controllers/ClinicVisitController.php` (roomWorklist, show)
- `app/Modules/ClinicVisit/Services/ClinicVisitService.php` (roomWorklist)
- `app/Modules/ClinicVisit/Repositories/ClinicVisitRepository.php` (worklistForBranches eager-loads)
- `app/Modules/ClinicVisit/Policies/ClinicVisitPolicy.php` (viewAny/view)
- `tests/Feature/RME/RoomAssignmentWorklistTest.php`

## 8. Files to change
1. `resources/views/rme/visits/room-worklist.blade.php` — replace action cell with a single
   "Buka Detail Pasien" link to `rme.visits.show`.
2. `tests/Feature/RME/RoomAssignmentWorklistTest.php` — update Section F to assert the new behavior.

The `medicalRecord` eager-load in `worklistForBranches` is left in place (harmless; removing it would
touch the repository and add risk without benefit). No controller/service/policy/route changes.

## 9. UI / action label design
Single primary button per row: **"Buka Detail Pasien"** → `route('rme.visits.show', $visit)`,
same `x-ui.button variant="primary" class="!px-3 !py-1.5 !text-xs"` styling. Shown for all listed
visits (all worklist rows are viewable by the current user).

## 10. Access control review
No permission slugs changed. Worklist still `authorize('viewAny')`. Detail still `authorize('view')`.
Kasir (no worklist permission) still forbidden from the worklist.

## 11. Branch isolation review
Worklist already scopes to active RME branches; `view` policy re-checks `belongsToActiveRmeBranch`.
No cross-branch leak introduced. Existing branch-filter test unchanged.

## 12. Privacy review
The worklist row still shows only name / RM / room / doctor / status / date — no KTP, phone, or address.
The detail page is the existing authorized page; no new sensitive fields exposed.

## 13. Test plan
Update `RoomAssignmentWorklistTest` Section F:
- Worklist action links to `rme.visits.show` (not medical-record show/store).
- Worklist shows "Buka Detail Pasien".
- Opening the visit detail URL returns 200 for a Doctor.
- No 404 when the visit has no MedicalRecord, and when it already has one.
- Visit detail page exposes Rekam Medis access.
- Visit detail page exposes Odontogram access.
Keep existing access-control, branch-isolation, and privacy tests.

## 14. Risk checklist
- [x] No schema/migration changes
- [x] No permission/role slug changes
- [x] No cashier/payment/invoice/receivable changes
- [x] No odontogram logic / finalization rule changes
- [x] No broad RME workflow rewrite
- [x] Single view + targeted test change
- [x] 404 still avoided (worklist no longer links to a route that aborts on missing MedicalRecord)

## 15. Rollback plan
Revert the two changed files (single commit). No data/state migration involved.
Pre-hotfix baseline: Sprint 58.6.1 merge commit `c7e27e8`.
