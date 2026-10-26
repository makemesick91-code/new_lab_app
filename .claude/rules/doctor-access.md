---
paths:
  - "app/Modules/DoctorAccess/**"
  - "app/Modules/Doctor/**"
  - "app/Http/Controllers/Auth/**"
  - "config/doctor_access.php"
  - "config/doctor_global_enforcement_readiness.php"
  - "resources/views/rme/doctor-branch-locks/**"
  - "tests/Feature/DoctorAccess/**"
  - "tests/Feature/Auth/**"
---

# Doctor access: account link, single session, branch lock, fleet readiness & activation — domain rules

Loaded automatically when you open a file under the paths above. Global rules CG-01…CG-24 live in `CLAUDE.md` and
are not repeated here. Authority: code > canonical `.cursor/rules/` > this file > archive. On disagreement follow the
code, then the canonical rule, and report the drift. Device registry, Android Keystore enrolment, WebAuthn
ceremonies and the login gate's proof mechanics belong to the sibling `.claude/rules/doctor-device-android.md`.

## Invariants

- **AIR-DAC-01** Resolve "which doctor is this user" only through `App\Modules\Doctor\Services\DoctorIdentityResolver`,
  which reads the one link column `mst_doctors.user_id`. Never add a second linkage, never write an ad-hoc
  `where('user_id', …)` in a controller/policy/Blade/test, never infer identity from name, email or phone (the email
  fallback in `DoctorUserResolver` was removed). Source: `92-doctor-account-identity-link.mdc`.
- **AIR-DAC-02** Manage links only through `DoctorAccountLinkService` (row lock + unique index = one account ↔ one
  doctor), routes `settings.doctors.account-links.*` or `rme:doctor-link-user` (dry run unless `--apply`), gated by
  `manage_doctor_account_links`. Relink is explicit; every change audits `DOCTOR_ACCOUNT_LINK/_RELINK/_UNLINK`. A link
  never grants the Doctor role, never rewrites visits or financial rows, never changes a kinerja/pendapatan formula.
  Source: `92-…`.
- **AIR-DAC-03** An unlinked doctor account fails closed with a clear 403; self-service derives the doctor
  server-side and ignores a request `doctor_id`. Before narrowing any identity resolver, measure who depends on the
  loose path (removing the email fallback cut off live doctors). Source: FEATURE-DOCTOR-ACCOUNT-PERFORMANCE-INCOME-LINKAGE-1.
- **AIR-DAC-04** A doctor's read scope for legacy RME and legacy odontogram archives (`LegacyRmeWorkspaceScope`,
  `LegacyOdontogramWorkspaceScope`) is `mst_doctor_branches ∩ active RME-enabled branches`
  (`DoctorClinicalBranchResolver`), never `BranchContext::forUser()` or `users.branch_id`; empty ⇒ deny everything;
  the online context never widens it. Native RME reads are not narrowed this way: they follow the RME branch set plus
  `DoctorPatientScopeService`, narrowed only by an effective branch lock. Source:
  `99-legacy-rme-doctor-clinical-branch-context.mdc`; `RmeWorkingBranchScope`.
- **AIR-DAC-05** `App\Modules\RME\Services\DoctorRoomScopeService` is the only room-scope/print-deny authority. The
  room comes from the live doctor online context, never the request; the restriction covers only
  `ClinicVisit::PRE_EXAM_STATUSES`; `applyRoomScope()` and `deniesActiveVisitOutsideRoom()` stay mirror images and
  never emit a bare `where('clinic_room_id', …)` (the closure also feeds the historical visit list). Owner/Super
  Admin/Supervisor RME stay exempt via `DoctorPatientScopeService::shouldApplyDoctorScope()`. Source: `138-…`.
- **AIR-DAC-06** A scoped Doctor may read and edit but never print `rme.visits.print`, `rme.visits.pdf` or
  `rme.odontograms.print` (policy `print`); prescription printing keeps working. Audit rejections from controllers,
  never policies. Do not room-guard `completeExamination`. Source: `138-…` (FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 1).

- **AIR-DAC-07** Refuse the second login; never evict the first. The invariant is the partial unique index
  `(user_id) WHERE released_at IS NULL` on `trx_doctor_session_leases`; released rows are kept as the trail. The claim
  hangs off `Illuminate\Auth\Events\Login` (`ClaimDoctorSessionLease`), never a controller, because a remember-me
  cookie reaches no controller. Source: `153-doctor-single-active-session-lease.mdc`.
- **AIR-DAC-08** A session carrying no lease token is never touched and never given a lease. An incumbent is dead only
  when no `sessions` row for its `user_id` survives inside the session store's own expiry predicate; never reclaim on
  an idle timer (`last_seen_at` is display only). Source: `153-…`.
- **AIR-DAC-09** `DoctorSessionLeaseService::enabled()` requires `IncumbentSessionProbe::observable()`: on a
  non-`database` session driver the engine disarms rather than fail open or closed. Confirm `session.driver` before
  arming. Source: `153-…`.
- **AIR-DAC-10** The deny path never calls `Auth::logout()` (`users.remember_token` is shared by every session) and
  writes no lease row. Eviction frees the room before teardown and releases the lease only when it is this session's
  (branch-changed → `RELEASE_EFFECTIVE_BRANCH_CHANGED`; missing/mismatched write nothing) (supersedes PR-A "releases
  no lease row"). Logout releases after `markOffline()` and before `logout()`; a device-denied login releases
  `RELEASE_DEVICE_INVALIDATED`; `EnsureAccountIsActive` releases `account_inactive`. Source: `153-…`, `200-…`.
- **AIR-DAC-11** Force logout (`doctor:session-force-logout`, dry run unless `--apply`) ends one login session and
  nothing else: no device, `DoctorDeviceAuthorization` or WebAuthn credential is written. It needs a named active
  actor holding `release_doctor_session_leases`, a reason bounded by `config('doctor_access.reason')` (no `env()` in
  that file), refuses self-release inside the service, and takes effect on the victim's next request. "Nothing to
  release" exits 0. Source: `153-…`, DOCTOR-ACCESS-PR-A-SINGLE-SESSION-FOUNDATION.
- **AIR-DAC-12** The HTTP release route `rme.doctor-branch-locks.release-session` 404s unless
  `DoctorEffectiveBranchResolver::enabled()`; never infer the lease flag from whether it answers, and use the CLI,
  which has no flag guard, for incident recovery. Source: `157-doctor-access-governance-phase.mdc`.
- **AIR-DAC-13** `DoctorBreakGlassService::revoke()` releases the lease its own grant created, in the same transaction,
  via `DoctorSessionReleaseService::releaseForDoctor()`; an unused grant releases nothing, a lease claimed before
  `granted_at` is left alone, and self-revocation is skipped, never refused. Grants key on `users.id`, not
  `mst_doctors.id`. Source: `162-sunu-go-live-blocker-closure.mdc` (FIX-SUNU-GO-LIVE-BLOCKERS-1).

- **AIR-DAC-14** A doctor's branch is an approved row, never inferred: every doctor starts UNSET (= pre-capability
  behaviour) and no backfill may ever be added, including for null `effective_branch_id` on old leases. Never derive
  a branch from doctor code, tablet branch, last login, room, visits or history; such signals may only be displayed as
  labelled suggestions. Source: `154-doctor-branch-lock-and-cover.mdc`, `156-…` FR-R4.
- **AIR-DAC-15** `DoctorEffectiveBranchResolver` is the single pure resolver (takes a `User`, ≤3 reads, zero writes,
  never audits). Arming is `enabled()` = `branch_lock AND single_active_session AND probe observable`; read
  `enabled()`, never one flag (the registry `dependencies` array is decorative), and report flag vs effective state
  as two lines. Source: `154-…`, `156-…` FR-R22/R33.
- **AIR-DAC-16** Home lock (`mst_doctor_branch_locks`) and temporary cover (`trx_doctor_branch_covers`) are two
  concepts; a cover never rewrites the lock. Authority is derived from timestamps on every request: no `is_active`, no
  `EXPIRED`, no scheduler in the correctness path. Source: `154-…`.
- **AIR-DAC-17** Cover periods are half-open `[starts_at, ends_at)`: adjacency is allowed, non-overlap is per doctor
  and enforced in `DoctorBranchCoverApprovalService::approve()` locking cover → doctor → home-lock row, then
  evaluating, then writing. A cover requires a home lock; a transfer is refused while a cover is current. Test
  adjacency as an acceptance and file a reverse-order pair. Source: `154-…`.
- **AIR-DAC-18** Maker-checker is an actor rule (`created_by` ≠ approver) enforced inside the approval transaction,
  never in a policy (`Gate::before`); either tier may be either party. A rejection needs a written reason in the
  service. Bindings are re-derived under lock at decision time. Source: `154-…`.
- **AIR-DAC-19** An approved change invalidates the session on its next request; a branch never switches inside an
  open session, and the deny arm fires only when both recorded and derived values exist. A branch operation never
  revokes a device, authorization or credential; the undo is a transfer back. Source: `154-…`.
- **AIR-DAC-20** Narrowing is an `array_intersect` in exactly two hooks, `RmeWorkingBranchScope::resolve()` and
  `ClinicVisitService::resolveBranchId()`. Per-record reads (`RmeWorkingBranchScope::allows()`,
  `ClinicVisitPolicy`, `DoctorClinicalBranchResolver`) stay cross-branch on purpose. Source: `154-…`.
- **AIR-DAC-21** Pass instants to SQL in the columns' frame (`prepareBindings()` uses the object's timezone; a WITA
  instant silently matches no cover), and re-assert partial indexes with their predicate in `up()` and `down()` when a
  migration adds a column (SQLite rebuilds flatten them). Source: `154-…`.
- **AIR-DAC-22** `doctor:branch-lock-bulk-assign` orchestrates `DoctorBranchLockApprovalService` only: dry run by
  default, `--apply` needs `--confirm-plan=<digest>`, distinct maker and checker each passing the Gate for
  `manage_doctor_branch_locks` / `approve_doctor_branch_locks`, a matrix parsed as a list of pairs (duplicates and JSON
  lists refused), live re-plan before writing, a failed approval withdrawn via `cancel()`, online doctors held unless
  acknowledged. Source: DOCTOR-ACCESS-FLEET-ROLLOUT-READINESS-1.

- **AIR-DAC-23** A `DoctorDeviceAuthorization` is an explicit per-doctor grant; a device's branch never filters it.
  `doctor:device-bulk-authorize` writes nothing on dry run (not even audit), applies only with the plan digest,
  writes only via `DoctorDeviceAuthorizationService::resolveOrRequest()`/`approve()`, never calls `resolveOrRequest()`
  on an existing row, leaves REJECTED/REVOKED untouched, re-asserts eligibility under row lock, runs top-level, and is
  digest-bound single-actor — not maker/checker. Nothing authorizes automatically. Source: `155-…`, `159-…` ECP-R24.

- **AIR-DAC-24** Provisioning is not readiness. `doctor:rollout-readiness` reports `TRUSTED_PATHS_COMPLETE` (never a
  live `GLOBAL_READY`); `doctor:fleet-readiness` is the gate an activation decision reads and adds home lock +
  authorization on every eligible tablet + a proven login. Never hardcode the authorization target; read
  `authorization_matrix.target_pairs` from `doctor:estate-resilience --json`. Source: `156-…` FR-R1/R2/R20, `157-…`.
- **AIR-DAC-25** Count login proofs from `sys_audit_logs` over `DoctorFleetReadinessRepositoryInterface::PROOF_ACTIONS`
  (the two success actions) keyed on `performed_by`, and only on tablets the doctor still holds an ACTIVE
  authorization on. Never use `last_authorized_login_at`, a credential's `last_used_at`, attempts or rejections; keep
  Android and WebAuthn proofs distinguishable; `entity_id` names the authorization, not the doctor. Source: `156-…`
  FR-R7/R8/R36, `157-…`.
- **AIR-DAC-26** A device proof identifies the tablet, not the clinician (credentials have no doctor column; one
  enrolment covers every authorized doctor). Absence of evidence is never a pass: an empty population is
  FAIL/UNVERIFIED and `worst()` fails closed on unknown status strings. Source: `156-…` FR-R6/R12, `158-…`, `161-…` HB-R14.
- **AIR-DAC-27** Readiness engines cannot act (source-scan enforced). One report uses one device-estate snapshot,
  memo reset at the top of `build()` and scoped to that build; a query budget guards scale, a load count guards
  snapshot integrity. Source: `156-…` FR-R30–R32 (FIX-READINESS-ESTATE-SNAPSHOT-INTEGRITY).
- **AIR-DAC-28** A real-device readiness ceremony is physical and never manufactured. It uses the Android
  device-login channel, needs no cohort enrolment (supersedes the old rotate-through-cohort protocol), leaves the
  cohort equal before/during/after, and never arms single session. `invalid_credentials` is not a password reset; the
  app shows auth failures as connectivity errors and failed auth rows carry `performed_by` NULL, so query by action;
  reconcile on security fields, never `updated_at`. Source: `156-…` FR-R19/R24/R27–R29/R40–R42.
- **AIR-DAC-29** Estate capacity has three nested levels: Level 1 `trusted_device_activation_test_coverage` (≥1
  logged-into device per staffed branch) is the only gate for activation testing; Level 2 room capacity is reported
  and stays out of the gate array; Level 3 `spare_device_available_per_branch` is high availability. Never let one
  level turn another green; `ESTATE_RESILIENCE` stays worst-of-every-gate. Staffed = home locks OR an active cover;
  unknown room profile or any home-less doctor is UNVERIFIED. Source: `159-…` ECP-R1–R13.
- **AIR-DAC-30** A device's branch is physical location, never authorization. A revoked device is never capacity
  (repaired hardware enrols as a new device). Eligibility is `isActive()` plus `DoctorDeviceIdentityProofPolicy::acceptable()`
  (an Android Keystore proof OR a device-bound WebAuthn credential); never read `cryptographically_verified` directly
  (rule 159 ECP-R19 predates this and is stale on that point); never set `WEBAUTHN_REQUIRE_DEVICE_BOUND=false`; bind the branch at
  admin approval. Adding an eligible device reddens authorization coverage until reconciled. Source: `158-…`, `159-…` ECP-R19–R23; archive
  REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1 Stage 2.
- **AIR-DAC-31** A signature never overrides a measurement: attested `true` beside FAIL or UNVERIFIED fails
  (`DoctorGlobalEnforcementPrerequisite::contradicts()` is the one expression); an absent signature fails nothing. Sign
  only after the measurement is PASS, iterate declared ∪ attested, and treat a non-boolean signature as a finding.
  Source: `159-…` ECP-R14/R25, `161-doctor-access-half-b-readiness.mdc` HB-R1–R3/R21.

- **AIR-DAC-32** `global_permitted`, `expected_posture`, `governance_phase` and `pilot_cohort_maximum` live in
  `config/android_release.php` (reads no environment), never in `config/doctor_device_enforcement.php`. An unknown
  phase resolves to `phase_4a`; `NOT_APPLICABLE` is a fourth status never counted as PASS; armed-over-nobody fails in
  every phase. Source: `152-…`, `157-…`.
- **AIR-DAC-33** Enforcement has four postures (off, bounded_pilot, global_rollout_readiness, global); the declared
  posture is a ceiling; measure global enforcement from the resolved scope (`GLOBAL_ENFORCEMENT_ACTIVE_LIVE`), never
  the recorded `false`. `global_permitted` is unreachable from env, DB or runtime writes but not tamper-evident against
  git. Source: `152-…` GR-R11–R15, `160-…` AW-R6.
- **AIR-DAC-34** The browser enforcement cohort is an explicit `users.id` allowlist
  (`AndroidDoctorEnforcementScope::pilotDoctorUserIds()`, union of the singular and plural host variables). One
  unreadable entry or a list over the ceiling covers nobody. Membership is neither admission nor provisioning;
  provision before covering. Change it with `config:clear && config:cache` as `daengtisiams`, capture the resolved
  union first and restore that capture. Source: `151-…` MD-R1–R14, `160-…` AW-R10.
- **AIR-DAC-35** Half A = `doctor.single_active_session` (every Doctor-role account incl. governance accounts, no
  cohort, effective from each doctor's next login) plus optional `doctor.branch_lock` (immediate narrowing when both
  on). Half B = fleet-wide device requirement (unscoped mode AND `global_permitted` AND flag, phase moved, all five
  `global_prerequisites` attested and measured PASS). Never report one as the other or the model as fully enforced.
  Source: `160-…` AW-R2–R5/R17, `161-…`.
- **AIR-DAC-36** Activation needs an explicit owner APPLY decision; readiness GO never authorizes it
  (`authorizes_activation` stays literal `false`). In the window: re-measure the six-condition clinical gate within
  minutes (`online=false` is not proof), release stale leases immediately before cutover, rebuild caches as
  `daengtisiams` (an environment edit alone does nothing), verify `foundation:feature-flags --json` and
  `android:phase4a-pilot-scope --json`, and fetch before judging tag ancestry. Source: `160-…` AW-R1/R8–R11, `161-…` HB-R8/R16.
- **AIR-DAC-37** Rollback is a flag flip plus cache rebuild with no data change. `DoctorHalfBRollbackProofService`
  restores the captured posture in `finally`, uses a subject outside the cohort, writes nothing, and covers the config
  repository only, not the environment file or cache. When arming exposes a wrong home lock, decide whether the data
  or the model is wrong before writing transfers. Source: `157-…`, `161-…` HB-R10–R13/R24, `160-…` AW-R16.

- **AIR-DAC-38** `daArmDoctorAccess()` arms both flags and the database session driver; flag keys contain dots, so
  rewrite the whole `feature_flags.flags` array (both `default` and `env_value`); only a real `POST /login` claims a
  lease and two `daLoginPost()` calls are one browser; lock/concurrency behaviour is proven only on the PostgreSQL
  critical gate (`DoctorAccess` token); contain a deliberate constraint violation in a nested `DB::transaction()`;
  non-fillable status/decision stamps need `forceFill`; the audit actor column is `performed_by`. Source: `153-…`,
  `154-…`, `156-…` FR-R39.
- **AIR-DAC-39** A lost or broken tablet is handled by a per-account break-glass grant (AIR-DDA-41), never by
  disarming `single_active_session` or device enforcement for the fleet. Source: `DoctorBreakGlassService`.
- **AIR-DAC-40** Patient access for a doctor needs a treating relationship (`DoctorPatientScopeService`: an active
  `trx_rme_patient_doctor_assignments` row or a visit with that doctor); the same branch alone never authorizes, and
  legacy archives never widen a doctor's reach beyond native RME. Owner, Super Admin and Supervisor RME are exempt
  through `shouldApplyDoctorScope()`. Source: `99-legacy-rme-doctor-clinical-branch-context.mdc`; archive
  LEGACY-RME-PDF-ROLL-2 (authorization defect).

## Current posture (snapshot 2026-10-25 — verify before relying on it)

- Taken from the last archived records (2026-09-17 → 2026-09-19); not re-measured in production for this file.
- `doctor.single_active_session` ON in production since the Half-A cutover (2026-09-17); second-login denial and
  no-eviction proven live.
- `doctor.branch_lock` OFF by owner decision: doctors may work at any branch provided they log in on a registered
  device of that branch. All doctors hold home locks (0 UNSET), stored and inert; `BRANCH_LOCK_EFFECTIVE=false`.
  Re-arming is a flag plus cache rebuild, after re-checking the home-branch matrix.
- Browser device enforcement is scoped `pilot` (3 doctors at the last record). `pilot_cohort_maximum` is 12 in code
  (raised from 5 through review to allow one branch at a time); rule 151 still says 5. Read the live cohort with
  `android:phase4a-pilot-scope --json`.
- Half B INACTIVE: `global_permitted=false`, `governance_phase=phase_4a`, `GLOBAL_ENFORCEMENT_ACTIVE=false`,
  `global_prerequisites_attested` 0 of 5. Device-loss rehearsal measured PASS (unsigned); spare capacity measures
  FAIL. Half B / Stage 4 APPLY not authorized.
- Estate: one eligible tablet per RME branch (TLK1, LDK2, ATG3, SPN4); Level 1 PASS and signed
  (`activation_test_prerequisites_attested`), Level 2 PARTIAL, Level 3 FAIL; authorization matrix complete at the last
  record. Fleet readiness reached every eligible doctor (2026-09-14) as capability evidence; device-branch
  intersection is not modelled (FR-R44).
- Open owner decisions: Half B APPLY; whether to re-arm `branch_lock`; tablet policy (per-branch tablet with
  per-doctor Android profile is a candidate gated on real-device feasibility).
- Known gap: failed device-login audit rows carry `performed_by` and `entity_id` NULL (FR-R45).

## Canonical rules — read the relevant one before changing this area

| Rule | Covers |
|---|---|
| `.cursor/rules/92-doctor-account-identity-link.mdc` | `mst_doctors.user_id` link, resolver, link management |
| `.cursor/rules/99-legacy-rme-doctor-clinical-branch-context.mdc` | Doctor clinical branch = practice branches ∩ RME branches |
| `.cursor/rules/138-doctor-room-scoped-access-and-print-deny.mdc` | Active-visit room scope, Doctor RME/odontogram print denial |
| `.cursor/rules/151-doctor-pilot-enforcement-cohort.mdc` | Explicit user-id cohort, ceiling, ceremony watermarking |
| `.cursor/rules/152-doctor-global-rollout-readiness.mdc` | Per-doctor trusted path, four postures, console refusal guard |
| `.cursor/rules/153-doctor-single-active-session-lease.mdc` | Session lease: refuse second login, force logout |
| `.cursor/rules/154-doctor-branch-lock-and-cover.mdc` | Home lock, cover, resolver, maker-checker, narrowing hooks |
| `.cursor/rules/155-doctor-device-bulk-authorization.mdc` | Bulk device authorization, plan digest, no auto-grants |
| `.cursor/rules/156-doctor-fleet-rollout-readiness.mdc` | Fleet readiness gate, login proofs, ceremony protocol |
| `.cursor/rules/157-doctor-access-governance-phase.mdc` | Governance phase, NOT_APPLICABLE, rollback, proof sources |
| `.cursor/rules/158-doctor-trusted-device-estate-resilience.mdc` | Estate resilience, revoked devices, UNVERIFIED inputs |
| `.cursor/rules/159-doctor-trusted-device-estate-capacity-policy.mdc` | Three capacity levels, attestations, provisioning traps |
| `.cursor/rules/160-doctor-access-activation-window.mdc` | Half A cutover, activation window, live posture |
| `.cursor/rules/161-doctor-access-half-b-readiness.mdc` | Five global prerequisites, contradiction check, rollback rehearsal |
| `.cursor/rules/162-sunu-go-live-blocker-closure.mdc` | Break-glass revoke releases its lease |
| `.cursor/rules/200-rme-active-user-patient-selection-cross-branch-control.mdc` | Deactivated account ends session and releases lease |
| `.cursor/rules/93-doctor-pwa-webauthn.mdc` | WebAuthn device login (sibling domain) |

## History

Verbatim sprint records: `docs/sprints/claude-md-archive/07-doctor-access-device.part1.md`,
`docs/sprints/claude-md-archive/07-doctor-access-device.part2.md`,
`docs/sprints/claude-md-archive/07-doctor-access-device.part3.md`, and
`docs/sprints/claude-md-archive/03-branch-context.md` (FIX-SUNU-GO-LIVE-BLOCKERS-1). Grep by sprint id; never read
a whole file.
