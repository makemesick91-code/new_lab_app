# Sprint 68.1 — Visit-Create Write-Path Dependency Graph

Scope: the `POST /rme/visits` write journey exercised by the Sprint 67.6 k6
write-flow test. Local stress env only (`daengtisia_stress`, `127.0.0.1:8008`).

## End-to-end chain (existing-patient flow — the path k6 drives)

```
tests/k6/rme-write-full-flow-local.js  (writeFlow)
  GET  /login                         -> auth scaffold (CSRF)
  POST /login                         -> session
  GET  /rme/online-context/select     -> OnlineContext select page (CSRF)
  POST /rme/online-context/admin-clinic -> sets active RME branch context
  GET  /rme/visits                    -> ClinicVisitController@index   (CSRF token source, ~70 KB)
  POST /rme/visits                    -> ClinicVisitController@store    *** THE WRITE ***
  GET  /rme/visits/{id}               -> ClinicVisitController@show
  GET  /rme/patient-queue             -> ClinicVisitController@patientQueue
```

## Route

* `routes/web.php:266` — `Route::resource('visits', ClinicVisitController::class)`
  inside the RME group (prefix `rme.`), gated by
  `view_clinic_visits|manage_clinic_visits`.
* Store = `rme.visits.store` → `ClinicVisitController@store`.

## Controller — `App\Modules\ClinicVisit\Controllers\ClinicVisitController@store` (L217)

1. `authorize('create', ClinicVisit::class)`  (policy)
2. `StoreClinicVisitRequest::validated()`     (FormRequest)
3. existing-patient branch → `ClinicVisitService::create($data)`
4. redirect → `rme.visits.show`

## Validation / Authorization

* FormRequest: `App\Modules\ClinicVisit\Requests\StoreClinicVisitRequest`
* Policy: `App\Modules\ClinicVisit\Policies\ClinicVisitPolicy@create`
* Branch context: `BranchContext` / `OnlineContext` (RME branch resolved before store)

## Service — `App\Modules\ClinicVisit\Services\ClinicVisitService::create` (L183)

Wrapped in a single `DB::transaction`:

| Step | Code | DB op | Cost |
|------|------|-------|------|
| resolve branch | `resolveBranchId()` | `Branch::find` | cheap (PK) |
| resolve patient | `resolvePatient()` | existing → none; new → `patients->create` | n/a (existing path) |
| **queue number** | `nextQueueNumber()` (repo L130) | `SELECT queue_number … WHERE branch_id,visit_date ORDER BY queue_number DESC … FOR UPDATE` | **fast** — Index Only Scan Backward on `(branch_id,visit_date,queue_number)` (0.07 ms) |
| **visit number** | `generateUniqueVisitNumber()` (L273) | `SELECT visit_number … WHERE visit_number LIKE 'VIS-{code}-{date}-%' … FOR UPDATE` then PHP `max()`; then `WHERE visit_number = ? exists()` loop | **SLOW (root cause)** — non-sargable `LIKE` → full parallel index scan of ~1M rows per insert (~250 ms) |
| branch code | `resolveVisitBranchCode()` (L305) | `Branch::find` | cheap (PK) |
| **INSERT** | `visits->create()` (repo L174) | `INSERT INTO trx_clinic_visits` + FK checks | cheap, FK targets all PK-indexed |

## Models / Tables

* Written: `trx_clinic_visits` (1 row/insert; `created_by = Auth::id()`)
* Read: `trx_clinic_visits` (queue+visit-number lookups), `mst_branches` (PK)
* Models: `ClinicVisit`, `Branch`, `Patient` (existing → not written), `ClinicRoom`/`Doctor` not touched on create

## Foreign keys on `trx_clinic_visits` (all reference PK-indexed targets)

`branch_id→mst_branches`, `patient_id→mst_patients`, `clinic_id→mst_clinics`,
`clinic_room_id→mst_clinic_rooms`, `doctor_id→mst_doctors`,
`initial_treatment_id→mst_treatments`, `created_by→users`,
`follow_up_of_visit_id→trx_clinic_visits`, `consent_verified_by→users`.
→ No missing FK supporting index implicated in the write.

## Indexes on `trx_clinic_visits`

* `trx_clinic_visits_pkey (id)`
* `trx_clinic_visits_branch_date_queue_unique (branch_id, visit_date, queue_number)` — serves `nextQueueNumber` (efficient)
* `trx_clinic_visits_branch_date_status_index (branch_id, visit_date, status)`
* `trx_clinic_visits_visit_number_unique (visit_number)` — **default btree; CANNOT serve `LIKE 'prefix%'` under en_US.UTF-8 collation**
* `patient_id`, `doctor_id`, `clinic_room_id` single-column indexes

## Transaction boundary / locking

* Single `DB::transaction` per create.
* Two `lockForUpdate()` reads (queue + visit number) over the branch+date row set
  → serialize concurrent same-branch/date creates, but EXPLAIN shows the
  visit-number `FOR UPDATE` query is dominated by a **full-index scan**, not lock waits.

## Sequence / identity

* `id` is bigint identity (PK) — not a contention point.
* `visit_number` is generated in PHP (max-suffix + 1, prefix `VIS-{code}-{Ymd}-NNN`),
  guarded by a global unique constraint + a `do/while` existence re-check.

## Audit / activity logs

* None on the create path (no activity-log insert observed in the write transaction).

## Verification page

* `redirect()->route('rme.visits.show', $visit)` → `ClinicVisitController@show`
  (eager-loads patient/doctor/clinic/room/branch + visit history).
