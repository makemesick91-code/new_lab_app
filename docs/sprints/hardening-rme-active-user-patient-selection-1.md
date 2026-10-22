# HARDENING-RME-ACTIVE-USER-PATIENT-SELECTION-1

Status: **SHIPPED + PRODUCTION DEPLOYED — GO** (scoped to this hardening; it does
not certify the invoice correction pilot). Runtime authority `b76b0ed2`, GO tag
`hardening-rme-active-user-patient-selection-1-go`.

| Item | Value |
|---|---|
| Base branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| Base commit | `b1e6d3c6` (docs-only evidence on top of the deployed runtime `3f0ec809`) |
| Production runtime before | `3f0ec809` = `audit-rme-clinic-visit-branch-authority-1-go` |
| Type | FOUNDATION_SPRINT, audit level 3 (first declared SECURITY_FIX; `sprint:scope-audit` correctly refused it — SECURITY_FIX forbids more than 10 code files and this installs an app-wide authentication foundation across 4 modules; no gate is lowered) |
| Rule | `.cursor/rules/200-rme-active-user-patient-selection-cross-branch-control.mdc` |
| Owner decisions | `docs/decisions/rme-billing-correction-owner-decisions.md` → OD-1, OD-1a |
| Migration | none |
| Permission / role / route / feature flag change | none |
| GO tag (on acceptance) | `hardening-rme-active-user-patient-selection-1-go` |

## 1. Objectives

1. **Active accounts (A).** A deactivated or deleted account must not keep working
   through a live session, a remember-me cookie, the Android app or a device login
   ticket, and the login form must refuse it — without an application-wide outage,
   without logging out other users, and without inventing a new account state.
2. **Patient selection (B).** Visit registration must be validated at the
   authoritative boundary (`ClinicVisitService::create()`), not only by the form,
   so a direct service caller cannot register a visit for a patient it may not
   select or link a Kontrol to another patient's visit.
3. **Owner decision OD-1 (C).** Cross-branch and cross-doctor Kontrol are
   allowed; the new visit, its RME, its invoice and its payments belong to the
   branch and doctor that actually treat the patient; history stays where it was.

## 2. Baseline audit (reproduced on the unmodified base before any change)

### 2.1 Active accounts

| Path | Before | Evidence |
|---|---|---|
| Login form, `is_active = false`, correct password | **LOGGED IN** | `LoginRequest::authenticate()` used `Auth::attempt()` with no status condition |
| Live session after deactivation | **kept working** on every surface | no middleware read `is_active`; `SessionGuard` re-reads the row but `EloquentUserProvider` filters only `deleted_at` |
| Remember-me cookie of a deactivated account | **restored** a session | same provider path |
| Remember-me cookie of a soft-deleted account | refused | SoftDeletes scope in the provider (unchanged, now pinned) |
| Android app login (`DoctorAppLoginService`) | checked the DOCTOR record's `is_active`, not the account | a deactivated user with an active doctor record logged in |
| Device ticket redemption | no account check | `DoctorDeviceSessionService::redeem()` |
| Visit / invoice actor guards | `where('is_active', true)` restated in two places | `ClinicVisitActorGuard`, `RmeBillingActorGuard` |

Status vocabulary in the schema: `users.is_active` (boolean, not null) and
SoftDeletes `deleted_at`. No suspended/blocked state exists; none was added.

### 2.2 Patient selection

| Path | Before |
|---|---|
| `create()` with a patient outside the registrar's selectable scope | **accepted** (only `StoreClinicVisitRequest` checked `isSelectable`) |
| `create()` linking a prior visit of ANOTHER patient | **accepted** (`validateFollowUpVisit` lived only in the form) |
| `create()` with a follow-up type and no prior visit | **accepted** |
| `create()` with a doctor who is not online at the branch (non-front-desk registrar) | **accepted** |
| POST `clinic_room_id` at registration | **written**, existence check only — a visit could be placed into another branch's room, bypassing `assignRoom()` and its room rules |
| `update()` | strip-list (`status`, `clinic_room_id`, `doctor_id`) — `patient_id`, `branch_id`, `follow_up_of_visit_id`, `visit_type`, numbering fields reachable by a direct caller |

### 2.3 Cross-branch Kontrol (OD-1) and its money

* Registration already accepted a prior visit from any active RME branch over HTTP;
  nothing pinned it and the rule had no service-side twin.
* `RmeControlReceivableService` discovered carry-over receivables across **every**
  RME branch, so a cashier at branch B saw — and `allocateControlPayment` /
  `allocateVisitPayment` would collect — branch A's unpaid balance into branch B's
  payment batch. That is cash taken at B recorded against A's invoice.

### 2.4 Production impact assessment (read-only fingerprint, before)

| Fact | Value |
|---|---|
| users total / active / inactive / soft-deleted | 35 / 34 / **0** / 1 |
| sessions belonging to an inactive or deleted account | **0** |
| visits / control visits / cross-branch control / cross-doctor control | 9 / 1 / **0** / **0** |
| patients with open receivables at more than one branch | **0** |
| payments whose branch differs from their invoice's branch | 0 |

No live session, visit or payment changes behaviour on deploy: there is no inactive
account to log out, no cross-branch Kontrol, and no multi-branch receivable to
re-scope. (The fingerprint stores counts only — no names, RM numbers or NIK.)

## 3. Owner decisions

**OD-1** — verbatim: *"pasien bisa kontrol lintas cabang bahkan dengan dokter
berbeda"*. `CROSS_BRANCH_CONTROL = APPROVED`, `CROSS_DOCTOR_CONTROL = APPROVED`.

**OD-1a** — asked during the sprint because OD-1 left the money question open:
*"Pasien kontrol di Cabang B masih punya piutang dari kunjungan sebelumnya di
Cabang A. Saat kasir Cabang B menerima pembayaran, apa yang berlaku?"* Owner
answer: **"Hanya piutang B (Recommended)"**. Branch A's balance stays branch A's
receivable, collected at branch A.

Neither carries an approval reference, signature or timestamp beyond the wording
and the date it was given; none is invented. Both are recorded in the decision
register as `OWNER_POLICY_APPROVED` + `REPOSITORY_POLICY_RECORDED`; the
`TECHNICAL_ENFORCEMENT_DEPLOYED` label is added only with deploy evidence (§10).

## 4. Design

### 4.1 One definition of a usable account

`App\Support\AccessControl\ActiveAccountResolver` — `resolveUsable()` /
`isUsable()` re-read the persisted row: exists, `is_active = true`, not
soft-deleted. An unsaved object carrying a real id is refused. Not memoised (rule
199 §27d). `ClinicVisitActorGuard` and `RmeBillingActorGuard` delegate to it; a
test asserts nothing else in `app/` restates the actor `is_active` read.

### 4.2 Where the account is checked

```
login form ──► Auth::attemptWhen(callback: resolver->isUsable)
                 └─ inactive message only after the password is proven
web request ─► EnsureAccountIsActive (web group, 2nd after EnsureDoctorSessionLease)
                 └─ deactivated: drop presence → release doctor lease
                    → one audit row → logout (cycles remember token)
                    → invalidate session → regenerate CSRF → login (JSON: 401)
remember-me ─► restored by SessionGuard, then stopped by the middleware above
               (soft-deleted: never restored — provider scope)
app login ──► DoctorAppLoginService: account_inactive after Hash::check
ticket ─────► DoctorDeviceSessionService::redeem(): account_inactive
```

The middleware runs before `TouchOnlineContextLastSeen` and
`EnsureRmeOnlineContext`, so a deactivated account never refreshes presence and
is never redirected to the context picker first. Guest pages, `/login`, health
endpoints and the stateless device API group are not in its path. Bookkeeping
failures are reported and never turn into a 500 or leave the session alive.

The audit row (`users`, `INACTIVE_ACCOUNT_SESSION_TERMINATED`) carries
`reason=account_inactive` and `surface=web` only — no name, e-mail or session id —
and is written once: the next request is a guest.

### 4.3 One rule source for registration

`App\Modules\ClinicVisit\Services\ClinicVisitRegistrationGuard`:

| Method | Used by | Rule |
|---|---|---|
| `assertPatientSelectable()` | service | `PatientSelectorSearchService::isSelectable()` — global RME identity, doctor scope, merged/deleted excluded |
| `followUpViolation()` | form (verdict) and service (via `assertFollowUpLink`) | coherence; prior visit exists at an active RME branch; SAME patient; **no branch or doctor comparison (OD-1)** |
| `resolveDoctor()` | service | front desk: doctor dropped (room decides later); others: doctor required and online at the branch |

Inside the registering transaction: merge guard (FOR SHARE) → selectability →
patient resolution → prior-visit check reading the prior visit FOR SHARE → insert.
A refusal writes nothing.

`clinic_room_id` is no longer a registration field and `create()` drops it; the
room is set only by `assignRoom()`. `update()` writes only
`ClinicVisitService::EDITABLE_FIELDS` (chief complaint, initial treatment,
initial service note).

### 4.4 Branch-local carry-over (OD-1a)

`RmeControlReceivableService::payingBranchIds()` returns the paying visit's own
branch when it is an active RME branch, else nothing (fail closed). Both discovery
queries (control chain and patient-level) are filtered by it. `RmePaymentService`
re-asserts it under the invoice lock in both allocation loops, so a crafted or
stale receivable id from another branch is skipped even if discovery ever widened.

## 5. Behaviour matrix (OD-1)

| Scenario | Result |
|---|---|
| Kontrol at B following a visit at A | allowed; new visit belongs to B |
| Kontrol by doctor Y following a visit by doctor X | allowed; doctor is Y |
| Prior visit of another patient | refused (`follow_up_of_visit_id`) |
| Prior visit soft-deleted / non-RME branch | refused |
| Follow-up type without prior visit | refused |
| Prior visit on a brand-new patient | refused before the patient row exists |
| Prior visit's RME, consent, odontogram, invoice, payments | unchanged (snapshotted in tests) |
| New invoice / payments | branch B |
| A's open receivable on B's payment page | not shown, not collectable |
| Same-branch carry-over | unchanged (Sprint 62.2 FIFO) |
| Kasir at B opening A's invoice | 403 (existing branch scope) |
| Doctor Y editing doctor X's historical RME | unchanged (consent/encounter gates, rule 109/134) |

## 6. Tests

New suites (all in both critical-gate filter variants and in
`config/ci_runner.php` `critical_gate_mandatory_suites`):

| Suite | Cases (`it`) | Covers |
|---|---|---|
| `tests/Feature/AccessControl/ActiveAccountSessionTest.php` | 26 (37 with datasets) | resolver, login (active/inactive/wrong password/soft-deleted/rate limit, 7 roles), live session (redirect, 401, POST blocked, single audit row, presence, lease, CSRF/session rotation), remember-me (positive, inactive burned, soft-deleted), role/permission revocation, branch/context ended, guest + health untouched, middleware order, single-definition scan |
| `tests/Feature/RME/ClinicVisitPatientSelectionAuthorityTest.php` | 31 | service-level patient/prior-visit/doctor rules, OD-1 acceptance, room never written, HTTP/service parity, single rule source, picker key set, history snapshots, edit allow-list |
| `tests/Feature/RME/CrossBranchKontrolFinancialOwnershipTest.php` | 12 | invoice branch, carry-over never crosses branches (page, HTTP, direct service, crafted id), kasir B forbidden on A's invoice, same-branch regression, leaky-discovery doubles for both loop guards |
| `tests/Feature/RME/ClinicVisitRegistrationConcurrencyTest.php` | 4 (PostgreSQL only) | prior visit held FOR SHARE (probe `FOR NO KEY UPDATE NOWAIT`, not `FOR UPDATE`), FK KEY SHARE not blocked, verdict-only takes no lock, committed soft-delete refused |

Extended: `DoctorDeviceEnforcementGateTest` (+2: app login and ticket redemption
refuse a deactivated account), `DoctorPwaWebAuthnTest` (+1) and
`FrontOfficeDeviceLoginCeremonyTest` (+1): a WebAuthn completion refuses an account
deactivated during the ceremony. Re-pinned with a SUPERSEDED IN PART note:
`ClinicVisitTest` (room no longer a registration error). Fixtures made explicit
(they had relied on the unchecked service): `DoctorHomeBranchLockTest`,
`PatientMergeGuardAndSearchTest` (the latter was passing vacuously).

TDD evidence: the new suites ran RED on the unmodified code (**50 failed, 27
passed**; device gate **2 failed**) and GREEN after the change.

## 7. Verification (local)

| Check | Result |
|---|---|
| Wide regression, SQLite (`tests/Feature/RME`, `AccessControl`, `Auth`, `DoctorDevice`, `DoctorAccess`, `PatientMerge`, `FrontOfficeDevice`, `RmeInvoiceCorrection`, `Invoice`, critical-gate coverage, legacy closure contract, DEVFLOW tooling) | **3895 passed, 28 skipped, 0 failed** (19 710 assertions) — every skip is a PostgreSQL-only row-lock or GD-only test |
| After the security-review fixes, SQLite (`DoctorDeviceWebAuthn`, `FrontOfficeDevice`, clinic-visit registration suites) | **379 passed, 0 failed** |
| New + affected suites, PostgreSQL 16.x (production major) — incl. the row-lock concurrency suite, `DoctorDeviceWebAuthn`, `PatientMerge`, carry-over and payment suites | **450 passed, 0 skipped, 0 failed** (1 736 assertions) |
| Mutation | see §8 |
| `pint --test`, `git diff --check` | clean |

## 8. Mutation testing

Harness: one mutant at a time, restored by COPY with sha256 verification; a mutant
whose target text does not match exactly once is NOT-APPLIED, never KILLED.

| Group | Mutants | Killed | Survived | Equivalent | Not applied |
|---|---|---|---|---|---|
| Account resolver, middleware, login, app login, redeem (M01–M14) | 14 | 14 | 0 | 0 | 0 |
| Registration guard, service, form (M15–M17, M19–M25, M29–M33) | 15 | 15 | 0 | 0 | 0 |
| Carry-over discovery and allocation loops (M26–M28) | 3 | 3 | 0 | 0 | 0 |
| Prior-visit FOR SHARE lock, PostgreSQL 16 (M18) | 1 | 1 | 0 | 0 | 0 |
| **Total** | **33** | **33** | **0** | **0** | **0** |

The first pass left **two survivors, both real test gaps** (the second barrier
masked the first, so no outcome-level test could see the form layer):

* **M24** — the form accepting `clinic_room_id` again survived because the service
  drops it anyway. Pinned: a malformed room in a POST is ignored, never validated
  (it is not a registration field at all).
* **M32** — the form skipping the prior-visit rule survived because the service
  re-asserts it with the same field error. Pinned: with the visit service mocked
  to `shouldNotReceive('create')`, the form refuses a bad prior visit first.

The same review found the OD-1 source scan **vacuous**: it searched for
`$parent->branch_id` while the guard names the prior visit `$prior`. Fixed, and
proven by **M33** (a `$prior->branch_id` comparison inserted into the guard).

The two LOW fixes of §9 were proven RED before the fix (3 failing tests), which is
the kill evidence for "check removed".

## 9. Security review

An independent reviewer traced the uncommitted change through the code (read-only,
no external service, no patient data). **CRITICAL 0, HIGH 0, MEDIUM 0, LOW 2, INFO 4.**

| # | Severity | Finding | Disposition |
|---|---|---|---|
| 1 | LOW | Doctor and Front Office **WebAuthn** completions (`completeLogin`) did not re-check the account, so an account deactivated between the password step and the fingerprint could still open a session (ended on the next request by the middleware, but it wrote a `LOGIN_SUCCESS` row that counts as readiness proof). Pre-existing paths the diff had left uncovered. | **Fixed**: both refuse `account_inactive` before anything is written; 2 tests. |
| 2 | LOW | `create()` skipped selectability for `patient_mode=new` but only created a patient when `new_patient` was an array — a direct caller passing an `ArrayAccess` object plus a `patient_id` reached an existing patient unchecked. Not reachable over HTTP. Introduced by the new guard logic. | **Fixed**: new mode drops `patient_id` and refuses a non-array payload (`new_patient`); 2 tests. |
| 3 | INFO | Route model binding runs before the appended middleware, so a request for a missing model 404s before a deactivated session is ended (it ends on the next resolvable request). | Accepted: an existence oracle for an account that already had access; no data. |
| 4 | INFO | A doctor's termination writes two rows (termination + lease release); concurrent in-flight requests may each write one. | Accepted: two distinct facts; no PII, no session id. |
| 5 | INFO | Console commands acting as `--actor` do not check account status. | Out of scope, pre-existing; shell access required. Recorded in §11. |
| 6 | INFO | Prior-visit messages distinguish "nonexistent" from "another patient" across RME branches. | Pre-existing, consistent with OD-1; over HTTP the form's generic selectability error is met first. |

Verified correct by the reviewer: the inactive message requires the correct password
and still counts against the limiter; middleware order, no redirect loop, fail
closed on a database error; no route bypasses the `web` group; remember-me,
ticket redemption, password reset and verification flows; one account definition;
`positiveInt` rejects floats, booleans, signs and zero; both FOR SHARE reads sit
inside the transaction; room and doctor rules; the edit allow-list; carry-over
filtered at discovery, intersected for forged ids, capped in the request, and
re-checked under the invoice lock in both loops; same-branch flows unchanged.

## 10. Release

| Step | Evidence |
|---|---|
| Candidate | `061487c6`, tree `5fb0131c`, PR #507 |
| CI on the exact candidate | run `39814315830`: Classifier, NSF-R012 Quality, **NSF-R011 Critical** (6343 passed, 0 failed, `critical_test_exit_status=0`, warning contract `expected_warning_count=0`; all eight relevant suites PASS in the log), Selective Module, NSF-9, NSF-10, Phase 3 Android — success. The second Critical row is the unused runner variant (exactly one runs). |
| Full Suite | **SKIPPED** by the active temporary Full-Suite policy — not executed, not claimed. |
| Merge | squash `b76b0ed2`, tree `5fb0131c` = candidate tree (identical); base was unmoved at `b1e6d3c6` |
| Post-merge push run | run `39837264070` on `b76b0ed2` — success; Full Suite deferred (`full_suite_authorized=false`, `full_suite_defer_reason=TEMPORARY_FULL_SUITE_POLICY_ACTIVE`) |
| Deploy | `scripts/deploy-vps-runner.sh start` executed INSIDE the VPS: `exit=0`, `DEPLOY OK: 20261022-190721`, `DEPLOY_HEAD_TARGET_MATCH=YES`, `DEPLOY_SNAPSHOT_CLEANED=YES`, backup before migrate, no migration, runtime isolation 70 GO / 0 FAIL, secret file permissions GO. (The SSH follow pipe dropped with exit 255; completion was read from the status file, never from the pipe.) |
| Smoke | deploy smoke passed except the known `http://127.0.0.1/login` 404 (co-tenant nginx loopback shadow); over `https://daengtisia.online`: `/login`, `/health/live`, `/health/ready`, `/health/lb` 200; `/dashboard`, `/rme/visits/create` 302 for a guest; `/storage/…` 403 |
| Code fingerprint | 9/9 changed runtime files byte-identical to the merge commit; `EnsureAccountIsActive` resolved at position 8 of the production `web` group, between `EnsureDoctorSessionLease` (7) and `TouchOnlineContextLastSeen` (9) |
| Data fingerprint | every user, session-of-inactive-account, patient, visit, medical record, invoice, payment, correction, audit and carry-over count identical before and after the deploy; only `sessions_total` moved (normal logins) |
| Posture | env pilot, debug OFF, maintenance OFF; 0 pending migrations; 0 failed jobs; nginx / php8.3-fpm / postgresql / queue worker active; 37 flags, none changed by this release (no `config/feature_flags.php` or environment change); correction pilot `PILOT_ACTIVE`, SPN4, maker 29 / checker 1, unchanged |
| Errors | `laravel.log` unchanged (1 437 201 bytes) — 0 new errors |
| GO tag | annotated `hardening-rme-active-user-patient-selection-1-go` → `b76b0ed2` (the deployed runtime, not this evidence commit); exact-match at VPS HEAD |
| Rollback | `scripts/rollback-vps.sh audit-rme-clinic-visit-branch-authority-1-go` (runtime `3f0ec809`); no data to restore |

## 11. Residuals (recorded, not changed here)

1. **Console `--actor` commands** (`LegacyRmeWaveAdminCommand`,
   `PatientKtpCrossBranchAccessCommand`, `PatientDocumentsBackfillCaptureBranchCommand`,
   two SATUSEHAT synthetic commands) do not consult `ActiveAccountResolver`; a
   deactivated Super Admin named as `--actor` still passes `Gate::before`. Shell
   access is required; pre-existing. Candidate follow-up.
2. **Route model binding precedes the appended middleware**: a request for a missing
   model returns 404 before a deactivated session is ended; the session ends on the
   next resolvable request. No data is reached.
3. **Prior-visit messages** distinguish a nonexistent visit from another patient's
   visit across RME branches (pre-existing, consistent with OD-1).
4. `RmeWorkingBranchScope::branchIdsFor(null)` still answers every RME branch
   (rule 198 §30) — unchanged contract, safe inside `auth`.
5. **Live deactivation is not exercised on production**: production holds 0 inactive
   accounts and this sprint never deactivates a real account to manufacture
   evidence. The behaviour is proven by the suites (SQLite + PostgreSQL 16).
6. **Full Suite**: deferred by the active temporary Full-Suite policy; it is not
   claimed.
