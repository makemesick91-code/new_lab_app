# AUDIT-RME-CLINIC-VISIT-BRANCH-AUTHORITY-1

**Type:** SECURITY_FIX (audit + the fix the audit proved necessary) · **Rule:** 199
(extends 198, closes 198 §31) · **Base:** `a98af8fc` (= production runtime
`f0044fd6` + docs-only #504) · **Branch:** `audit/rme-clinic-visit-branch-authority-1`

No migration. No permission, role, route or feature-flag change. No owner decision
taken; one open owner decision recorded (OD-1).

## 1. Executive summary

The prior release (rule 198) moved `RmeInvoiceService` off the ambient session and
named `ClinicVisitService:94/:143` as the remaining residual. This audit inspected
the whole service rather than those two lines and found **eight** ambient
`Auth::user()`/`Auth::id()` reads and one `BranchContext` read, plus **two
cross-branch reads reachable today over HTTP** in callers of the service.

| ID | Finding | Reachability | Severity | Status |
|---|---|---|---|---|
| F1 | Prior-visit picker (`GET rme/visits/patient-options`) authorized `viewAny` only: a Kasir pinned to branch A read branch B patient visit history (number, date, type, doctor, initial treatment, status) | HTTP, authenticated insider | MEDIUM | FIXED |
| F2 | RME dashboard summed today's **payment total**, receivables, cashier-pending, month counts and draft records over every RME branch for a context-bound operator; `?branch_id=` reached any branch | HTTP, authenticated insider | MEDIUM | FIXED |
| F3 | Visit list / queue / worklist / counts / branch selector scoped from `Auth::user()`; with no session `branchIdsFor(null)` answered every RME branch (fail-open); ambient session decided scope | Latent — no non-HTTP caller exists | LOW | FIXED |
| F4 | "Selesai Pemeriksaan" (`→ cashier_pending`) skipped the clinical check when `Auth::user()` was null | Latent — only HTTP reaches it | LOW | FIXED |
| F5 | `create()` attributed `created_by`, judged the doctor lock and wrote the lock audit from the ambient session; with no session it failed only at the DB (`QueryException`) | Latent | LOW | FIXED |
| F6 | Visit-list medical-record widgets counted every RME branch next to working-branch-scoped visit counts | HTTP, aggregate counts only | LOW | FIXED |
| F7 | `find()` read `BranchContext` and had ZERO callers | Dead code | INFO | REMOVED |
| F8 | A stale comment in `create()` described a BranchContext fallback the code has not had | Documentation | INFO | CORRECTED |
| F9 | `UpdateClinicVisitRequest` accepted `clinic_room_id` / `doctor_id` checked only for existence (since Sprint 20, before room rules existed); the edit form sends neither. A crafted PUT moved a branch-A visit into a branch-B room (bypassing same-branch, active-room, terminal-visit and doctor-from-room rules) or set any doctor (widening that doctor's patient scope) | HTTP, authenticated insider; found by the independent review, reproduced RED (`room 3 instead of 1`) | MEDIUM (pre-existing) | FIXED — room and doctor written only by `assignRoom()` |

`CRITICAL=0 HIGH=0`. F2 and F9 affect accounts that exist in production today and
are the reason this audit ends in a runtime change rather than an audit-only closure.

**Production calibration (read-only, 2026-10-22).** Active accounts holding
`view_clinic_visits` without `manage_clinic_visits`: **1, and it is Owner**
(governance, already sees every branch). Active registrars: 26. Inactive accounts:
0; inactive accounts with a session: 0. Consequences stated plainly:
* F1 protects **no account that exists today**. Kasir has been merged into Front
  Office, which is a registrar, and registrars keep cross-branch history (OD-1). F1
  is a real code-level hole — any view-only role assigned later would exploit it —
  but it is not reported as closing a live exposure.
* The `is_active` tightening (deactivated account → empty lists, refused writes)
  changes nothing for any current account.
* F2 (dashboard) and F9 (edit path) are reachable by current context-bound
  operators.

## 2. Method

1. Read-only baseline of repository and production (§9).
2. Every ambient-context read in `ClinicVisitService` enumerated (§3).
3. Every caller of every public method enumerated from source (§4). Graphify was
   built for the audited tree and queried as a second method; see §10 for why it
   could not confirm callers.
4. Each finding reproduced against the **unmodified** base with a throwaway probe
   before any code changed (§5); the probe was deleted.
5. TDD: new suite written first and run RED (34 failed / 7 passed for the right
   reasons), then implementation, then GREEN.

## 3. Ambient authentication inventory (base `a98af8fc`)

| Line | Method | Read | Purpose | Null behaviour |
|---|---|---|---|---|
| 94 | `scopeBranchIds` | `Auth::user()` | list/queue/worklist/count scope | every RME branch |
| 143 | `selectableRmeBranches` | `Auth::user()` | branch filter selector | every RME branch |
| 251 | `assignRoom` | `Auth::user()` | `assigned_by` of auto doctor assignment | `assigned_by` null |
| 312 | `create` | `Auth::id()` | `created_by` | DB NOT NULL error |
| 505 | `assertWithinDoctorEffectiveBranch` | `Auth::user()` | doctor branch lock | lock skipped |
| 554 | `auditEffectiveBranchWriteRefusal` | `Auth::id()` | audit payload | `user_id = 0` |
| 661 | `transitionStatus` | `Auth::user()` | `completeExamination` | check skipped |
| 726 | `doctorVisitScope` | `Auth::user()` | doctor patient + room scope | no doctor narrowing |
| 258 | `find` | `BranchContext::requireId()` | branch lookup | — (0 callers) |

## 4. Caller inventory

| Method | Callers | Actor source | Read/Write | HTTP | Non-HTTP |
|---|---|---|---|---|---|
| paginate, roomWorklist, registeredQueue | `ClinicVisitController` index / roomWorklist / patientQueue | `$request->user()` | R | yes | none |
| visitsTodayCount, waitingCount, inProgressCount | `ClinicVisitController@index`, `RmeDashboardController@index` | `$request->user()` | R | yes | none |
| selectableRmeBranches | `ClinicVisitController@index`, `RmeDashboardController@index` | `$request->user()` | R | yes | none |
| patientVisitOptions | `ClinicVisitController@patientVisitOptions` | `$request->user()` | R | yes | none |
| create | `ClinicVisitController@store` | `$request->user()` | W | yes | none |
| update, assignRoom | `ClinicVisitController@update / assignRoom` | `$request->user()` | W | yes | none |
| transitionStatus | `ClinicVisitController@transitionStatus` (all statuses but `completed`); `RmePaymentService` ×3 (`completed` only) | `$request->user()` / none | W | yes | payment service |
| patientVisitHistory, adjacentVisits | visit show, MedicalRecordController, Odontogram, Prescription | anchor visit authorized first | R | yes | none |
| activeRoomsByRmeBranch, activeRoomsForBranch | ClinicVisitController, OnlineContextController | — (room master data) | R | yes | none |
| (injected, unused) | `RmeInvoiceController` | — | — | — | — |

No job, command, listener, scheduler or queue worker calls `ClinicVisitService`.
Hence F3–F5 are classified LATENT, consistent with rule 198 §A.2's classification of
the identical pattern in the invoice service.

## 5. Reproduction on the unmodified base

| Probe | Result |
|---|---|
| no session → `paginate([])` / `registeredQueue([])` | branches `[A, B]` — fail-open |
| no session → `waitingCount()` / `selectableRmeBranches()` | `2` / 7 branches |
| Kasir pinned to A → `GET patient-options?patient_id=<B patient>` | **HTTP 200**, visit list with `doctor_name`, `initial_treatment` |
| no session → `transitionStatus(consented in_progress, cashier_pending)` | **succeeded** |
| governance session, intended actor = Kasir@A | scope `[A, B]` (session decided) |
| no session → `create(...)` | `QueryException` NOT NULL `created_by` |

## 6. The contract (rule 199)

* Every actor-dependent method takes a **non-nullable explicit `User $actor`**;
  null is a `TypeError`. `transitionStatus` keeps a nullable actor **only** for the
  cashier-owned `completed` transition (`RmePaymentService`, rule 197 actor).
* `ClinicVisitActorGuard` re-reads the account (persisted, active, not soft-deleted)
  and asks `ClinicVisitPolicy` the **same ability the controller checks** — so no
  legitimate HTTP request changes outcome.
* Unusable/unauthorized actor → empty scope / zero counts / empty selector (reads),
  `AuthorizationException` 403 (create, assignRoom, update, picker),
  `ValidationException` on `status` (transitions). A refused create writes nothing.
* Prior-visit picker = registration boundary (`create` + `isSelectable`), not
  narrowed to the working branch (see OD-1).
* Dashboard and visit-list widgets take their branch set from
  `ClinicVisitService::scopedBranchIdsFor($request->user())`.

## 7. Open owner decision

**OD-1 — cross-branch Kontrol.** `StoreClinicVisitRequest::validateFollowUpVisit()`
accepts a prior visit from **any** active RME branch, so a registrar at branch A
may link a follow-up to a visit at branch B, and the picker must show it. This
fix therefore keeps cross-branch history visible **to registrars** (and only to
them). Narrowing the picker to the working branch would silently remove
cross-branch Kontrol from the UI while the server still accepts it. Whether
cross-branch Kontrol should remain a supported workflow is the owner's decision.

## 8. Changed files

Runtime: `ClinicVisitService.php`, new `ClinicVisitActorGuard.php`,
`ClinicVisitController.php`, `UpdateClinicVisitRequest.php` (room/doctor no longer
editable), `RmeDashboardController.php`, `MedicalRecordService.php` (count methods
take the branch set).

Tests: new `tests/Feature/RME/ClinicVisitBranchAuthorityTest.php`; call sites
updated in 10 existing suites (see §11).

Governance: rule 199 (new), rule 198 §31 marked closed, `CLAUDE.md`,
`.sprint/current.yml`, `config/ci_runner.php` mandatory suite, CI critical-gate
token `ClinicVisitBranchAuthority` in both runner variants.

## 9. Baselines (read-only)

Repository: base `a98af8fc`; local base ref stale (`9eabff07`) — DEVFLOW gates run
with `--base-sha`.

Production (before): HEAD `f0044fd6` tree `adad24bf`, exact tag
`fix-rme-invoice-branch-scope-explicit-actor-1-go`; patients 1522, visits 9
(cashier_pending 1, completed 7, registered 1), invoices 7 / Rp7.548.000,
payments 8 / Rp5.548.000, corrections 0, audit rows 1601, role_has_permissions 514,
model_has_roles 36, failed_jobs 0, jobs 0; correction flag ON, pilot SPN4, maker
29, checker 1; health 200 ×4; deploy lock free. Pre-existing, not task-owned:
untracked `storage/runtime-home/` on the VPS.

## 10. Graphify

v0.8.35, built for the audited tree: 39 143 nodes / 57 154 edges;
`ClinicVisitService` = community 19, 34 nodes. **Limitation:** the AST extractor
records no inbound cross-file edges to the service's methods — it does not resolve
calls dispatched through an injected property (`$this->visits->paginate()`). The
caller inventory (§4) therefore rests on the source scan, not on the graph.

## 11. Tests

| Run | Result |
|---|---|
| New suite, RED on the unmodified service | 34 failed / 7 passed (reasons: 403-vs-200, 1 000 000-vs-100 000, 2-vs-1, `QueryException` where a refusal belongs, signature `TypeError`) |
| New suite GREEN | 42 passed |
| F9 RED / GREEN | `room 3 instead of 1` → passes |
| 21 suites calling the changed signatures | 497 passed |
| Wide regression (RME, DoctorAccess, AccessControl, Pilot, Ui, PatientMerge, Branch, Auth, Satusehat, LabWorkflow, BranchScope, Odontogram, MedicalRecord, Prescription, Consent, Owner) | 3 655 passed, 22 skipped, 9 failed — 8 = `DoctorMultiDeviceAccessTest` WebAuthn (`Undefined array key "challenge"`), **identical on a pristine base checkout** (local environment; CI ran it green for #503); 1 = the superseded picker assertion, since rewritten |
| Post-F9: new suite + ClinicVisitTest + ControlWorkflow + ClinicOversight | 204 passed |
| **PostgreSQL 16.15** (task-owned container, migrations verified in it): 12 affected suites | 280 passed |
| **PostgreSQL 16.15**: invoice/payment explicit actor, payment, carry-over, cashier completion gate, D10 race, invoice correction (incl. pilot identity, payment-version binding, concurrency), manual pricing, tariff authority | 518 passed |

Mutation (18 + 1 combined, applied by exact substitution, every file restored by
copy and checksum-verified): **18 KILLED, 1 equivalent, 0 actionable survivors.**
M12 (picker controller back to `viewAny`) survives because the service still
refuses; removing BOTH checks (M19) is KILLED, proving M12 equivalent. M15 was
first reported NOT-APPLIED (its anchor moved after the F9 edit), retargeted, KILLED.

Performance (SQL statements per request; fixture 2 branches; base `a98af8fc` vs
candidate):

| Page / actor | 5 visits/branch | 20 visits/branch | Added |
|---|---|---|---|
| Visit list, context-bound | 92 → 114 | 152 → 175 | +22 / +23 |
| Dashboard, context-bound | 33 → 52 | 34 → 52 | +19 / +18 |
| Visit list, governance | 35 → 52 | 40 → 57 | +17 / +17 |
| Dashboard, governance | 17 → 26 | 17 → 26 | +9 / +9 |

The addition is the guard's persisted-row re-read (~3 statements per resolution) and
is **constant in data volume** — O(1), no N+1. Authorization is deliberately not
memoised (a memo on a container- or route-cached object outlives the request). The
probe also shows a **pre-existing** N+1 on the context-bound visit list (92 → 152
as visits go 10 → 40) — recorded, not touched.

Existing suites updated, each for a stated reason:
* `SunuClinicRoomProvisioningTest` — room-rule tests now pass an actor holding
  `update`; room rules unchanged.
* `DoctorHomeBranchLockTest` — the locked doctor (already `actingAs`) is the explicit
  actor; lock assertions unchanged.
* `RmeLabCandidateE2EValidationTest` — the visit sits in the examining doctor's
  online room (the factory put it in a room no doctor was in, a path HTTP refuses);
  the doctor is passed explicitly.
* `CashierBillingTest`, `RmeConsentOdontogramPreConsentEditTest` — authorized doctor
  passed explicitly.
* `RmeExamConsentCorrective3Test`, `RmePreConsentFirstPageUiGateTest` — actor passed
  AND the refusal reason pinned to the consent message; otherwise they would pass
  because the actor was missing. (`PreConsentFirstPage` already passed an actor that
  PHP silently dropped — the old signature had two parameters.)
* `PatientMergeGuardAndSearchTest` — registrar passed; the merge guard is still the
  refusal (an actor refusal would be a different exception class).
* `RmeVisitListBranchFilterTest` — counts take the actor.
* `ClinicVisitControlWorkflowTest` — **superseded assertion**: it pinned that a
  view-only account reads the picker (200). Now the registrar gets 200 and the
  viewer 403.
* `ClinicVisitTest` — **superseded assertion**: "updates room but ignores status
  field" pinned that the edit endpoint moved the visit into the submitted room (F9).
  Now both room and status are ignored there; rooms move through assign-room.

## 12. Recorded residuals (not changed here)

1. `RmeWorkingBranchScope::branchIdsFor(null)` still answers every RME branch (rule
   198 §30) — ClinicVisitService can no longer pass null.
2. No per-request `is_active` middleware (rule 198 §30a).
3. `MedicalRecordService::paginate()` has no callers and still reads `Auth::user()`
   (MedicalRecord domain; separate audit candidate). The Rekam Medis index uses
   `UnifiedMedicalRecordIndexService`, which this audit did not inspect.
4. `MedicalRecordService::finalizedTodayCount()` uses UTC `now()` for "today".
5. `create()` does not re-assert patient selectability (the FormRequest does).
6. `RmeInvoiceController` injects `ClinicVisitService` and never uses it.
7. `activeRoomsByRmeBranch()` returns room names of every RME branch (no patient data).
8. Pre-existing N+1 on the context-bound visit list (~2 statements per visit).
9. `transitionStatus(completed)` checks `status === cashier_pending` on the in-memory
   model without a row lock (pre-existing; the payment service holds its own lock).
10. `show`/`edit` of a visit still render for a deactivated account with a live
    session (policy-only surfaces; rule 198 §30a).
