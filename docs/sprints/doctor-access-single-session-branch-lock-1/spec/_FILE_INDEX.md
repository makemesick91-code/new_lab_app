# Deduplicated file index (106 files)

- [NEW] `.cursor/rules/153-doctor-access-single-session-branch-lock.mdc`  (slices: flags-manifest-seeders-permi)
      The durable AI-assistant mirror of the architecture contract.
- [EDIT] `.github/workflows/foundation-evidence-gates.yml`  (slices: approval-workflows-for-the-d, slice-the-complete-test-plan, flags-manifest-seeders-permi)
      Make the new suite actually run in the critical gate. Without this edit the three test files above match NO gate token and never execute in CI.
- [EDIT] `.sprint/current.yml`  (slices: flags-manifest-seeders-permi)
      Rewrite the ROLLING manifest for this sprint with honest booleans, carrying every inherited block through verbatim.
- [EDIT] `CLAUDE.md`  (slices: flags-manifest-seeders-permi)
      Append this sprint's section in the established house style.
- [NEW] `app/Console/Commands/DoctorDeviceBulkAuthorizeCommand.php`  (slices: o4-bulk-doctor-device-author)
      The operator surface. Thin adapter over the service in the exact shape of LegacyRmeImportAdminCommand: dry-run unless --apply, an explicit human --act
- [EDIT] `app/Http/Controllers/Auth/AuthenticatedSessionController.php`  (slices: single-active-session-lease)
      Release the lease on logout, and hand it off cleanly when a denied browser is sent to the WebAuthn ceremony.
- [EDIT] `app/Http/Controllers/ProfileController.php`  (slices: single-active-session-lease)
      Release the lease before an account is force-deleted, so the audit trail records why it ended.
- [EDIT] `app/Modules/AccessControl/Services/PermissionGroupingService.php`  (slices: approval-workflows-for-the-d, flags-manifest-seeders-permi)
      Classify the new permission into the RME group and give it a description, so it does not land in 'Other / Uncategorized' on the role-assignment screen
- [EDIT] `app/Modules/ClinicVisit/Services/ClinicVisitService.php`  (slices: home-lock-temporary-branch-c)
      Consume the read hook (2 call sites) and install the WRITE hook (ruling P#1, CRITICAL 1).
- [NEW] `app/Modules/Doctor/Controllers/DoctorBranchLockController.php`  (slices: approval-workflows-for-the-d)
      Thin HTTP surface for all three workflows plus the lease-release action. Authorize, validate, delegate.
- [NEW] `app/Modules/Doctor/Interfaces/DoctorBranchCoverRepositoryInterface.php`  (slices: approval-workflows-for-the-d)
      Repository contract for covers, including the canonical 'is a cover active' predicate.
- [NEW] `app/Modules/Doctor/Interfaces/DoctorBranchLockRepositoryInterface.php`  (slices: approval-workflows-for-the-d)
      Read/write boundary for the home lock. This is the interface the SIBLING resolver slice consumes for the read side.
- [NEW] `app/Modules/Doctor/Interfaces/DoctorBranchLockRequestRepositoryInterface.php`  (slices: approval-workflows-for-the-d)
      Repository contract for assignment/transfer requests.
- [NEW] `app/Modules/Doctor/Models/DoctorBranchCover.php`  (slices: migrations-and-models-for-do, approval-workflows-for-the-d)
      Eloquent model for trx_doctor_branch_covers. Persists only decided statuses; ACTIVE/EXPIRED/SCHEDULED are derived by DoctorBranchCoverState and are st
- [NEW] `app/Modules/Doctor/Models/DoctorBranchLock.php`  (slices: migrations-and-models-for-do, approval-workflows-for-the-d)
      Eloquent model for mst_doctor_branch_locks. Nothing fillable — every column is the authority or a lifecycle stamp.
- [NEW] `app/Modules/Doctor/Models/DoctorBranchLockRequest.php`  (slices: migrations-and-models-for-do, approval-workflows-for-the-d)
      Eloquent model for trx_doctor_branch_lock_requests. Requester-contributed columns only in $fillable; every decision column is excluded so a forged sta
- [NEW] `app/Modules/Doctor/Policies/DoctorBranchCoverPolicy.php`  (slices: approval-workflows-for-the-d)
      Who may see, file, cancel and decide a cover.
- [NEW] `app/Modules/Doctor/Policies/DoctorBranchLockRequestPolicy.php`  (slices: approval-workflows-for-the-d)
      Who may see, file, cancel and decide an assignment/transfer request. Permission-based, never a role-name comparison.
- [NEW] `app/Modules/Doctor/Repositories/DoctorBranchCoverRepository.php`  (slices: approval-workflows-for-the-d)
      Implementation of the cover repository.
- [NEW] `app/Modules/Doctor/Repositories/DoctorBranchLockRepository.php`  (slices: approval-workflows-for-the-d)
      Implementation of the home-lock repository.
- [NEW] `app/Modules/Doctor/Repositories/DoctorBranchLockRequestRepository.php`  (slices: approval-workflows-for-the-d)
      Implementation of the request repository.
- [NEW] `app/Modules/Doctor/Requests/DecideDoctorBranchCoverRequest.php`  (slices: approval-workflows-for-the-d)
      A decision on a pending cover, and the cancellation reason.
- [NEW] `app/Modules/Doctor/Requests/DecideDoctorBranchLockRequestRequest.php`  (slices: approval-workflows-for-the-d)
      A decision on a pending assignment/transfer request.
- [NEW] `app/Modules/Doctor/Requests/ReleaseDoctorSessionRequest.php`  (slices: approval-workflows-for-the-d)
      The approver's manual lease-release action (ruling P17).
- [NEW] `app/Modules/Doctor/Requests/StoreDoctorBranchCoverRequest.php`  (slices: approval-workflows-for-the-d)
      Filing a temporary cover.
- [NEW] `app/Modules/Doctor/Requests/StoreDoctorBranchLockRequestRequest.php`  (slices: approval-workflows-for-the-d)
      Filing an initial-assignment or transfer request. The client supplies a destination, a reason and (only when filing on someone's behalf) a doctor.
- [NEW] `app/Modules/Doctor/Services/DoctorBranchCoverApprovalService.php`  (slices: approval-workflows-for-the-d)
      The whole authority for TEMPORARY_BRANCH_COVER: request(), approve(), reject(), cancel(). Separate service from the lock approval because the guards d
- [NEW] `app/Modules/Doctor/Services/DoctorBranchLockApprovalService.php`  (slices: approval-workflows-for-the-d)
      The whole authority for INITIAL ASSIGNMENT and PERMANENT TRANSFER: request(), approve(), reject(), cancel(). Modelled on app/Modules/RmeOnlineContext/
- [NEW] `app/Modules/Doctor/Support/DoctorBranchCoverState.php`  (slices: migrations-and-models-for-do)
      The DERIVED cover vocabulary as a final Support class with a private constructor — the house shape for a closed vocabulary, since no native PHP enum e
- [NEW] `app/Modules/Doctor/Support/DoctorBranchCoverStatus.php`  (slices: approval-workflows-for-the-d)
      Separates the four PERSISTED approval states from the three DERIVED time states, so nothing can persist ACTIVE or EXPIRED by accident.
- [NEW] `app/Modules/Doctor/Support/DoctorBranchLockRequestStatus.php`  (slices: approval-workflows-for-the-d)
      Closed status vocabulary for assignment/transfer requests.
- [NEW] `app/Modules/Doctor/Support/DoctorBranchLockRequestType.php`  (slices: approval-workflows-for-the-d)
      INITIAL_ASSIGNMENT vs TRANSFER, and the single place that derives which one applies.
- [NEW] `app/Modules/DoctorAccess/Interfaces/DoctorSessionLeaseRepositoryInterface.php`  (slices: single-active-session-lease)
      The repository boundary the enterprise architecture baseline (ENT-1) requires between service and model.
- [NEW] `app/Modules/DoctorAccess/Listeners/ClaimDoctorSessionLease.php`  (slices: single-active-session-lease)
      Claims the lease on EVERY production authentication entry, including the remember-me recaller path that no login controller can see.
- [NEW] `app/Modules/DoctorAccess/Middleware/EnsureDoctorSessionLease.php`  (slices: single-active-session-lease)
      Per-request lease revalidation and the next-request eviction primitive.
- [NEW] `app/Modules/DoctorAccess/Models/DoctorSessionLease.php`  (slices: single-active-session-lease)
      Eloquent model + the stable reason/action vocabularies.
- [NEW] `app/Modules/DoctorAccess/Repositories/DoctorSessionLeaseRepository.php`  (slices: single-active-session-lease)
      Concrete repository.
- [NEW] `app/Modules/DoctorAccess/Services/DoctorBranchLockResolver.php`  (slices: flags-manifest-seeders-permi)
      The single reader of both flags, and the place the declared dependency is actually enforced. Shared with the runtime slice; this slice specifies only 
- [NEW] `app/Modules/DoctorAccess/Services/DoctorSessionLeaseService.php`  (slices: single-active-session-lease)
      The claim/deny/release engine. All lease business logic lives here and nowhere else.
- [NEW] `app/Modules/DoctorAccess/Support/IncumbentSessionProbe.php`  (slices: single-active-session-lease)
      The ONLY place that answers 'is the incumbent's session still alive?', and the place that refuses to guess when it cannot observe.
- [NEW] `app/Modules/DoctorBranchLock/Interfaces/DoctorBranchCoverRepositoryInterface.php`  (slices: home-lock-temporary-branch-c)
      Repository boundary for covers.
- [NEW] `app/Modules/DoctorBranchLock/Interfaces/DoctorBranchLockRepositoryInterface.php`  (slices: home-lock-temporary-branch-c)
      Repository boundary for the home lock (enterprise baseline ENT1-R001: Service must not query a Model directly).
- [NEW] `app/Modules/DoctorBranchLock/Models/DoctorBranchCover.php`  (slices: home-lock-temporary-branch-c)
      Eloquent model for trx_doctor_branch_covers, carrying the DERIVED active predicate.
- [NEW] `app/Modules/DoctorBranchLock/Models/DoctorBranchLock.php`  (slices: home-lock-temporary-branch-c)
      Eloquent model for mst_doctor_branch_locks.
- [NEW] `app/Modules/DoctorBranchLock/Repositories/DoctorBranchCoverRepository.php`  (slices: home-lock-temporary-branch-c)
      Concrete implementation.
- [NEW] `app/Modules/DoctorBranchLock/Repositories/DoctorBranchLockRepository.php`  (slices: home-lock-temporary-branch-c)
      Concrete implementation.
- [NEW] `app/Modules/DoctorBranchLock/Services/DoctorEffectiveBranchResolver.php`  (slices: home-lock-temporary-branch-c)
      THE resolver. The single canonical answer to EFFECTIVE_CLINICAL_BRANCH. Pure: takes a User, takes no Request, no device id, no branch argument, writes
- [NEW] `app/Modules/DoctorBranchLock/Support/DoctorEffectiveBranch.php`  (slices: home-lock-temporary-branch-c)
      Immutable value object carrying the answer AND why — so the degradation reason exists without the resolver writing anything.
- [EDIT] `app/Modules/DoctorDevice/Interfaces/DoctorDeviceAuthorizationRepositoryInterface.php`  (slices: o4-bulk-doctor-device-author)
      Add the one read method the global row-delta reconciliation needs.
- [NEW] `app/Modules/DoctorDevice/Models/DoctorSessionLease.php`  (slices: migrations-and-models-for-do)
      Eloquent model for trx_doctor_session_leases. Nothing fillable. Carries the constant-time token comparison and the effective-branch match used by the 
- [EDIT] `app/Modules/DoctorDevice/Repositories/DoctorDeviceAuthorizationRepository.php`  (slices: o4-bulk-doctor-device-author)
      Implement countAll().
- [NEW] `app/Modules/DoctorDevice/Services/DoctorDeviceBulkAuthorizationService.php`  (slices: o4-bulk-doctor-device-author)
      The plan builder and the transactional applier. All eligibility, bucketing, counting, reconciliation and batch audit live here; the command is a thin 
- [NEW] `app/Modules/DoctorDevice/Support/DoctorDeviceBulkAuthorizationPlan.php`  (slices: o4-bulk-doctor-device-author)
      Immutable plan value object plus the PLAN_TOKEN. Separate from the service so the token derivation is a single testable function and the plan cannot b
- [EDIT] `app/Modules/RmeOnlineContext/Controllers/OnlineContextController.php`  (slices: home-lock-temporary-branch-c)
      Narrow the selector's source collection so the doctor is never offered a branch the server will refuse — and so the Alpine room map narrows with it, f
- [EDIT] `app/Modules/RmeOnlineContext/Middleware/EnsureRmeOnlineContext.php`  (slices: approval-workflows-for-the-d)
      Exempt the new routes, or a Doctor with no online context can never reach the surface that would give them one.
- [EDIT] `app/Modules/RmeOnlineContext/Services/RmeWorkingBranchScope.php`  (slices: home-lock-temporary-branch-c)
      THE READ HOOK. Exactly one NEW narrowing point inside the canonical class. It must be a NEW method, not an edit to branchIdsFor(), or it reaches per-r
- [EDIT] `app/Modules/RmeOnlineContext/Services/UserOnlineContextService.php`  (slices: home-lock-temporary-branch-c)
      THE SERVER BOUNDARY for the branch selector. The dropdown is cosmetic; this is enforcement.
- [EDIT] `app/Providers/AppServiceProvider.php`  (slices: single-active-session-lease)
      Register the Login listener explicitly, because this application has NO event auto-discovery.
- [EDIT] `app/Providers/RepositoryServiceProvider.php`  (slices: single-active-session-lease, home-lock-temporary-branch-c, approval-workflows-for-the-d)
      Bind the lease repository interface to its implementation.
- [EDIT] `bootstrap/app.php`  (slices: single-active-session-lease)
      Register the middleware FIRST in the existing web append list.
- [EDIT] `config/ci_runner.php`  (slices: o4-bulk-doctor-device-author, slice-the-complete-test-plan, flags-manifest-seeders-permi)
      Declare both new suites mandatory, so their coverage by the critical gate is a governance decision rather than a side effect of where the files happen
- [NEW] `config/doctor_branch_lock.php`  (slices: approval-workflows-for-the-d)
      Bounds that make ONE approval permission safe, and the reason-length policy. No env keys, nothing risky, so it never interacts with FLAG-RISKY-DEFAULT
- [EDIT] `config/feature_flags.php`  (slices: single-active-session-lease, home-lock-temporary-branch-c, flags-manifest-seeders-permi)
      Register doctor.single_active_session, default OFF.
- [NEW] `database/migrations/2026_09_10_100001_create_mst_doctor_branch_locks_table.php`  (slices: migrations-and-models-for-do, home-lock-temporary-branch-c)
      HOME_LOCKED_BRANCH (section Q concept 1). One durable row per doctor. The ABSENCE of a row IS the UNSET state, which is what makes owner decision O1 (
- [NEW] `database/migrations/2026_09_10_100001_create_trx_doctor_session_leases_table.php`  (slices: single-active-session-lease)
      The lease store and, in the SAME migration, the unguarded partial unique index that is the cardinality-1 invariant.
- [NEW] `database/migrations/2026_09_10_100002_create_trx_doctor_branch_covers_table.php`  (slices: home-lock-temporary-branch-c)
      TEMPORARY_BRANCH_COVER: explicit approved temporary operational authority with starts_at/ends_at. ACTIVE and EXPIRED are DERIVED, never persisted (own
- [NEW] `database/migrations/2026_09_10_100002_create_trx_doctor_branch_lock_requests_table.php`  (slices: migrations-and-models-for-do)
      ONE table serving BOTH O1 workflows — INITIAL BRANCH ASSIGNMENT (UNSET -> branch) and BRANCH TRANSFER (branch A -> branch B) — discriminated by `reque
- [NEW] `database/migrations/2026_09_10_100003_create_trx_doctor_branch_covers_table.php`  (slices: migrations-and-models-for-do)
      TEMPORARY_BRANCH_COVER (section Q concept 2) with explicit starts_at/ends_at. Carries TWO partial unique indexes: one that IS the pending invariant, a
- [NEW] `database/migrations/2026_09_10_100004_create_trx_doctor_session_leases_table.php`  (slices: migrations-and-models-for-do)
      The single-session lease. Cardinality 1 active lease per user, enforced by an UNGUARDED partial unique index created in the SAME migration as the tabl
- [NEW] `database/migrations/2026_09_10_110001_create_trx_doctor_branch_lock_requests_table.php`  (slices: approval-workflows-for-the-d)
      The approval record for INITIAL ASSIGNMENT and PERMANENT TRANSFER. One table for both because the decision transaction and every guard are identical; 
- [NEW] `database/migrations/2026_09_10_110002_create_mst_doctor_branch_locks_table.php`  (slices: approval-workflows-for-the-d)
      HOME_LOCKED_BRANCH — the permanent, authoritative per-doctor lock. Written ONLY by an approval in this slice; read by the sibling resolver slice.
- [NEW] `database/migrations/2026_09_10_110003_create_trx_doctor_branch_covers_table.php`  (slices: approval-workflows-for-the-d)
      TEMPORARY_BRANCH_COVER — one row is both the request and, once approved, the grant. ACTIVE/EXPIRED are never persisted.
- [EDIT] `database/seeders/PermissionSeeder.php`  (slices: approval-workflows-for-the-d, flags-manifest-seeders-permi)
      Define the new permission.
- [EDIT] `database/seeders/RoleSeeder.php`  (slices: approval-workflows-for-the-d, flags-manifest-seeders-permi)
      Grant the permission to Supervisor RME and to no other role.
- [NEW] `docs/architecture/doctor-access-single-session-branch-lock.md`  (slices: flags-manifest-seeders-permi)
      The durable contract. Numbered rules DSB-1..DSB-22 that later sprints inherit.
- [NEW] `docs/runbooks/doctor-branch-lock-and-session-lease-operations.md`  (slices: flags-manifest-seeders-permi)
      The operator runbook: how to assign, transfer, cover, release and roll back — and what never to do.
- [NEW] `docs/sprints/doctor-access-single-session-branch-lock-1.md`  (slices: flags-manifest-seeders-permi)
      The sprint record, in the house style of docs/sprints/doctor-pwa-global-rollout-readiness-1.md.
- [EDIT] `resources/views/layouts/partials/sidebar.blade.php`  (slices: approval-workflows-for-the-d)
      Add the approver entry under the existing 'Konteks Kerja' group.
- [NEW] `resources/views/rme/doctor-branch-locks/cover-create.blade.php`  (slices: approval-workflows-for-the-d)
      Filing a temporary cover. Approver-tier only.
- [NEW] `resources/views/rme/doctor-branch-locks/create.blade.php`  (slices: approval-workflows-for-the-d)
      Filing an initial assignment or a permanent transfer.
- [NEW] `resources/views/rme/doctor-branch-locks/index.blade.php`  (slices: approval-workflows-for-the-d)
      The approver queue: pending assignments/transfers, pending covers, current locks, presence, and the decision actions.
- [EDIT] `resources/views/rme/online-context/select.blade.php`  (slices: home-lock-temporary-branch-c)
      Tell the locked doctor why there is one option, reusing the existing locked-branch presentation verbatim.
- [EDIT] `routes/web.php`  (slices: approval-workflows-for-the-d)
      Register the eleven routes inside the existing rme. group, immediately after the FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 block.
- [EDIT] `tests/Feature/AccessControl/DailyBranchContextLockTest.php`  (slices: slice-the-complete-test-plan)
      Repin two cases so they assert the COMPATIBILITY state deliberately instead of assuming a rule the sprint narrows. Selected by the `DailyBranchContext
- [NEW] `tests/Feature/AccessControl/DoctorBranchLockOperationalScopeTest.php`  (slices: home-lock-temporary-branch-c)
      Pin owner decision O2's mandatory three-case regression, ruling P#1 (write hook) and ruling P#2 (per-record authorization must NOT narrow).
- [NEW] `tests/Feature/AccessControl/DoctorEffectiveBranchResolverTest.php`  (slices: home-lock-temporary-branch-c)
      Pin the resolver contract and every Q/P ruling it owns.
- [EDIT] `tests/Feature/Auth/SupervisorRmeRolePermissionTest.php`  (slices: approval-workflows-for-the-d, slice-the-complete-test-plan, flags-manifest-seeders-permi)
      Repin the exact-list assertion that the RoleSeeder grant breaks.
- [NEW] `tests/Feature/DoctorAccess/DoctorAccessConcurrencyTest.php`  (slices: slice-the-complete-test-plan)
      The four concurrency scenarios the owner named, plus the lease cardinality and the aborted-transaction trap — run against real PostgreSQL with real co
- [NEW] `tests/Feature/DoctorAccess/DoctorAccessFeatureFlagContractTest.php`  (slices: flags-manifest-seeders-permi)
      Pins the flag registry contract for this sprint and proves the dependency is enforced rather than declared. Lives in tests/Feature/DoctorAccess/ so th
- [NEW] `tests/Feature/DoctorAccess/DoctorAccessGovernanceTest.php`  (slices: slice-the-complete-test-plan)
      The structural contracts: flags, permissions, schema, audit-action separation, the two source-scan guards this sprint could trip, the O5 boundary, and
- [NEW] `tests/Feature/DoctorAccess/DoctorBranchCoverTest.php`  (slices: slice-the-complete-test-plan)
      Owner decision O6 as corrected by section Q — TEMPORARY_BRANCH_COVER with explicit starts_at/ends_at, lazily resolved, invalidating the session on BOT
- [NEW] `tests/Feature/DoctorAccess/DoctorBranchTransferTest.php`  (slices: slice-the-complete-test-plan)
      Owner decision O1's two approval workflows (INITIAL ASSIGNMENT and TRANSFER) plus rulings P10, P11 and the section Q rule that a permanent transfer mu
- [NEW] `tests/Feature/DoctorAccess/DoctorDeviceBulkAuthorizationTest.php`  (slices: slice-the-complete-test-plan)
      Owner decision O4 — the idempotent bulk device-authorization tool, which O4 says SHIPS in this sprint. Not named in the slice's coverage list but mand
- [NEW] `tests/Feature/DoctorAccess/DoctorHomeBranchLockTest.php`  (slices: slice-the-complete-test-plan)
      Owner decisions O1, O2, O3 and rulings P1, P2, P6, P9, P11 — HOME_LOCKED_BRANCH starts UNSET, narrows lists and writes once SET, and never touches arc
- [NEW] `tests/Feature/DoctorAccess/DoctorMultiDeviceAccessTest.php`  (slices: slice-the-complete-test-plan)
      Requirement: a doctor authenticates from ANY approved ACTIVE cryptographically-verified clinic tablet regardless of which branch owns it — while every
- [NEW] `tests/Feature/DoctorAccess/DoctorSingleSessionLeaseTest.php`  (slices: slice-the-complete-test-plan)
      Requirement 1: a doctor holds AT MOST ONE authenticated session across browser, PWA and tablet, and a second login is DENIED without evicting the firs
- [NEW] `tests/Feature/DoctorAccess/helpers.php`  (slices: slice-the-complete-test-plan)
      Shared fixtures for all six RefreshDatabase DoctorAccess suites. Deliberately NOT added to tests/Pest.php, mirroring tests/Feature/AccessControl/helpe
- [NEW] `tests/Feature/DoctorBranchLock/DoctorBranchCoverApprovalTest.php`  (slices: approval-workflows-for-the-d)
      Pins cover semantics: derived ACTIVE/EXPIRED with no job, overlap refusal, self-cover refusal, home-lock prerequisite, timezone.
- [NEW] `tests/Feature/DoctorBranchLock/DoctorBranchLockAccessTest.php`  (slices: approval-workflows-for-the-d)
      Pins the HTTP boundary: permission gating, the IDOR boundary on doctor_id, the flag gate, and the lease-release action.
- [NEW] `tests/Feature/DoctorBranchLock/DoctorBranchLockApprovalTest.php`  (slices: approval-workflows-for-the-d)
      Pins the assignment/transfer transaction: SoD, stale source, deactivated/unlinked doctor, cover block, presence confirmation, session invalidation wit
- [NEW] `tests/Feature/DoctorDevice/DoctorDeviceBulkAuthorizationNonEscalationTest.php`  (slices: o4-bulk-doctor-device-author)
      Structural proof that the command CANNOT revoke, reopen, mint credentials, arm enforcement or write a branch lock — the primitives are absent from the
- [NEW] `tests/Feature/DoctorDevice/DoctorDeviceBulkAuthorizationTest.php`  (slices: o4-bulk-doctor-device-author)
      Behavioural contract. File name begins with DoctorDevice so the `DoctorDevice` token in the critical filter selects it in both gate variants.
- [EDIT] `tests/Feature/DoctorDevice/DoctorDeviceEnforcementGateTest.php`  (slices: slice-the-complete-test-plan)
      Add one assertion so a single-session denial cannot silently satisfy a replay test for the wrong reason — migration cost E6.
- [NEW] `tests/Feature/DoctorDevice/DoctorSessionLeaseTest.php`  (slices: single-active-session-lease)
      The proof. Placed in tests/Feature/DoctorDevice/ so the critical gate actually runs it on PostgreSQL.
- [EDIT] `tests/Feature/DoctorDeviceWebAuthn/DoctorPwaWebAuthnTest.php`  (slices: slice-the-complete-test-plan)
      Two cases whose teardown does not run the logout controller, so they must state which refusal they are measuring — migration cost E6 and E7.
- [EDIT] `tests/Feature/MasterData/DoctorRmeBranchSourceTest.php`  (slices: slice-the-complete-test-plan)
      Repin four cases as explicit UNSET-doctor compatibility assertions. CRITICAL PROCESS NOTE: this file matches NO token in either critical filter, so it
