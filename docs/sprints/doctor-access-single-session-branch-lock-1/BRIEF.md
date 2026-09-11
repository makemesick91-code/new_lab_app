# DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — working brief

Authoritative worktree: /home/fikri/Projects/doctor-access-single-session-branch-lock-1
Base branch: feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report @ 215d277d486ad884482fafaa8ccefd6a8c79c3e8
VPS production HEAD is the same commit, untagged, APP_ENV=pilot, global_enforcement_active=false.

## A. Discovery contract (9 readers + architect, all citations verified against that worktree)

### CURRENT_SESSION_MODEL
A doctor's authenticated session is Laravel's ordinary `web` guard session plus six session-payload keys written by `DoctorDeviceSessionService::bind()` (app/Modules/DoctorDevice/Services/DoctorDeviceSessionService.php:163-179) — `doctor_device.device_id`, `.authorization_id`, `.doctor_id`, `.bound_at`, `.proof_type`, `.webauthn_credential_id`, all declared as constants on DoctorAppLoginGate.php:51-69 — and `DoctorSessionProof` (app/Modules/DoctorDevice/Support/DoctorSessionProof.php:35-67) is a `final` immutable two-field value object (`type`, `webAuthnCredentialId`) with a private constructor and NO persistence of its own, reconstructed per request from two of those session keys via `fromSessionValues()` (:81-96) which returns null rather than a default for anything unexpected; there is no server-side row anywhere that says 'this doctor currently holds this session', and `doctor_device.bound_at` is written at DoctorDeviceSessionService.php:173 and read by nothing, so no session-age semantics exist today.

### CURRENT_SESSION_DRIVER
`config/session.php:21` resolves the driver from `SESSION_DRIVER` defaulting to `database`, and `database/migrations/0001_01_01_000000_create_users_table.php:30-37` creates the `sessions` table with a string `id` primary key, a nullable INDEXED `user_id`, `ip_address`, `user_agent`, `payload` and an indexed `last_activity` — but no application code in app/ ever reads or writes that table (a repo-wide search for `table('sessions')` returns nothing; the only two `sessions` string hits are a dump-reader skip list at app/Support/PilotImport/PostgresCopyDumpReader.php:26 and a query classifier at app/Services/Monitoring/RuntimeQueryObservabilityService.php:28), and both `phpunit.xml:29` and every CI job (.github/workflows/foundation-evidence-gates.yml:323, :545) set `SESSION_DRIVER=array`, so the table is empty in every local and CI test run.

### CURRENT_DOCTOR_SESSION_TRACKING
ABSENT — there is no per-doctor session cardinality tracking, session lease, active-session registry, or concurrent-session limit anywhere in the repository: searches across app/, config/, database/ and routes/ for `single_session`, `active_session`, `lease`, `concurrent`, `logoutOtherDevices`, `AuthenticateSession` and `password_hash_web` return no hits, and the only unique-per-user branch-carrying row that exists, `trx_user_online_contexts` with `unique('user_id')` (database/migrations/2026_06_29_120001_create_trx_user_online_contexts_table.php:26), is a presence record with a nullable `branch_id` (:17) rewritten wholesale by `updateOrCreate` on every session start (app/Modules/RmeOnlineContext/Repositories/UserOnlineContextRepository.php:19-25) and carries no session identity at all.

### CURRENT_BRANCH_CONTEXT_SOURCE
`BranchContext::forUser()` (app/Modules/Branch/Services/BranchContext.php:65-73) is a four-term null-coalescing chain of which only THREE terms are live — I verified in source that `branchIdFromUserRelation()` is guarded by `method_exists($user, 'branches')` (:107-111) and `App\Models\User` declares no `branches()` method (it has only `homeBranch()` at app/Models/User.php:67 and uses HasFactory, HasRoles, Notifiable, SoftDeletes at :20), so link 3 is unreachable dead code and the earlier CLAUDE.md-derived 'four-link chain' description is wrong: the real order is (1) the active RME online-context branch via `UserOnlineContextService::activeContextBranchId()` (:81-88 → app/Modules/RmeOnlineContext/Services/UserOnlineContextService.php:187-245, which fails closed on exemption, offline status, null branch, role mismatch or a non-RME branch), (2) `users.branch_id` when the column exists and the branch is active (:90-105), (3) dead, (4) MAIN or the first active branch (:167-178) — and MAIN is non-RME by design, so for a doctor link 4 produces a branch that yields an empty RME scope.

### CURRENT_BRANCH_LOCK_MECHANISM
The only working-branch lock is `DailyBranchContextService`, whose `LOCKED_ROLE_CONTEXTS` I read directly at app/Modules/RmeOnlineContext/Services/DailyBranchContextService.php:69-72 and which contains exactly `ROLE_ADMIN_CLINIC` and `ROLE_KASIR` — Doctor is deliberately excluded — keyed `UNIQUE(user_id, clinical_date)` (database/migrations/2026_08_29_100002 sibling 2026_08_29_100001_create_trx_daily_branch_contexts_table.php:76) so it grants a fresh free selection every clinical day, enforced through `assertSelectable()` inside a `DB::transaction` with `lockForUpdate` plus a SAVEPOINT-wrapped INSERT (:147-204) and made authoritative only for locked role contexts by the override at UserOnlineContextService.php:229-242; a doctor therefore has NO branch lock today and freely re-selects any of their `mst_doctor_branches` practice branches through `startDoctorSession()` (UserOnlineContextService.php:247-298), whose only server-side boundary is pivot membership at :277-281.

### CURRENT_DEVICE_AUTHORIZATION_MODEL
Device trust is strictly per (doctor, device) pair: `mst_doctor_device_authorizations` carries `UNIQUE(doctor_id, doctor_device_id)` named `mst_dd_authorizations_pair_unique` (database/migrations/2026_09_03_110001_create_mst_doctor_device_authorizations_table.php:92) with NO soft delete (:29) so a REVOKED or REJECTED row permanently occupies the pair slot, and every login path demands an exact ACTIVE row — DoctorAppLoginGate.php:290-297 per request, DoctorDeviceWebAuthnLoginService.php:297-306 at assertion, DoctorDeviceSessionService.php:107-111 at ticket redemption; critically, NOTHING in that path reads a branch: `mst_doctor_devices.branch_id` is written FROM the doctor's own RME branches and documented as 'a placement, not a permission' (app/Modules/DoctorDevice/Services/DoctorAppLoginService.php:479-506), config/doctor_device_enforcement.php:80-84 states outright that it is not an authorization input, and the authorization table has no branch column at all — so sprint requirement 2 (any approved ACTIVE device regardless of owning branch) already holds and needs no code change.

### CURRENT_FORCE_LOGOUT_CAPABILITY
ABSENT and explicitly refused: the only session teardown in the codebase is `DoctorDeviceSessionService::invalidate()` (app/Modules/DoctorDevice/Services/DoctorDeviceSessionService.php:188-207), whose docblock at :181-187 states 'Scoped to the one session in front of us. There is deliberately no "log everyone out" here: a revocation is about one tablet, and a blunt global invalidation would turn a security action into an outage', and it can only act on the request it is handed (`Auth::guard('web')->logout()` plus `$request->session()->invalidate()`); `Auth::logoutOtherDevices`, Laravel's `AuthenticateSession` middleware and any `DELETE FROM sessions` are all absent from app/, bootstrap/, config/ and routes/, so requirement 4's 'atomically invalidates the active session' has no existing primitive and reverses a written prior decision that the sprint must call out.

### RUNTIME_FIX_REQUIRED
YES — new runtime code is required for requirements 1, 3 and 4; requirements 2 and 5 need none. The MINIMUM is: (1) two additive migrations creating NEW `trx_doctor_session_leases` (holder = `users.id`, `session_token_hash`, `claimed_at`, nullable `released_at`/`released_reason`, nullable `doctor_id` for audit) with an UNGUARDED partial unique index `CREATE UNIQUE INDEX ... (user_id) WHERE released_at IS NULL` created in the SAME migration as the table, and NEW `mst_doctor_branch_locks` (`UNIQUE(doctor_id)`, `branch_id`); (2) a NEW `DoctorSessionLeaseService` claiming the lease after the final `session()->regenerate()` at three call sites and releasing it at logout; (3) a NEW globally-appended middleware `EnsureDoctorSessionLease` that re-validates the lease per request and tears the session down via the existing `DoctorDeviceSessionService::invalidate()` — it must NOT live inside `EnsureDoctorDeviceSession` or `DoctorAppLoginGate::denySessionReason()`, both of which return on their first line while `doctor.trusted_device_enforcement` is off (EnsureDoctorDeviceSession.php:39-41, DoctorAppLoginGate.php:224-226; config/feature_flags.php:400 defaults it false) and would make the new rules dead code; (4) a NEW first link in `BranchContext::forUser()` at app/Modules/Branch/Services/BranchContext.php:67 reading the doctor lock and failing closed to null; (5) a NEW `DoctorBranchTransferApprovalService` modelled line-for-line on `BranchChangeApprovalService::approve()` that inside ONE `DB::transaction` locks the request row, locks the lock row, re-asserts the source, moves the branch, and marks the doctor's live lease `released_at` — which is what makes 'invalidate the session' committed data the victim's next request reads, since no in-process cross-session logout exists. No new runtime code is needed for requirement 2 (no branch predicate exists in the device path) or requirement 5 (leaving config/feature_flags.php:400 and android_release.enforcement.scope.global_permitted=false untouched keeps global WebAuthn enforcement off).

## B. Architect decisions

**D1. Reuse DoctorSessionProof for the lease, or add a new lease store?**

- DECIDE: NEW store — a NEW table `trx_doctor_session_leases` holding `user_id`, `session_token_hash`, `claimed_at`, `last_seen_at`, nullable `released_at`/`released_reason`, and a nullable `doctor_id` for audit only. `DoctorSessionProof` is extended only insofar as `bind()` gains the caller's `User`.
- REJECTED: Overloading `trx_user_online_contexts` (which already has `unique('user_id')`, migration 2026_06_29_120001:26) was rejected because its `branch_id` is nullable (:17), it is rewritten wholesale by `updateOrCreate` on every session start (UserOnlineContextRepository.php:19-25), and the codebase already rejected this exact overloading in writing at database/migrations/2026_08_29_100001_create_trx_daily_branch_contexts_table.php:11-17 — 'It is a session representation. Overloading it would make the lock evaporate the moment the operator went offline and back on.'
- EVIDENCE: `DoctorSessionProof` is a `final` class with a private constructor and exactly two fields, `type` and `webAuthnCredentialId` (app/Modules/DoctorDevice/Support/DoctorSessionProof.php:35, :54-67); it has no persistence and is reconstructed per request from two session-payload keys (DoctorAppLoginGate.php:254-257). The whole session binding is six session keys and nothing else (DoctorDeviceSessionService.php:170-178), so there is nothing queryable from outside the owning request and no place to hang a claim.

**D2. Key the lease on users.id or mst_doctors.id?**

- DECIDE: `users.id`, with `doctor_id` stored as a nullable audit column only.
- REJECTED: Keying on `mst_doctors.id` was rejected because it fails open for unlinked accounts and because it is a different number from the user id in this codebase — the recorded Phase-4A hazard where the pilot target was users.id 18 while the doctor record was id 21.
- EVIDENCE: Every authenticated session has a user id available at every claim site with zero extra resolution, whereas `DoctorIdentityResolver::resolveForUser()` (app/Modules/Doctor/Services/DoctorIdentityResolver.php:35-40) can return null for a Doctor-role account that is not linked to a master record — the exact condition the HOTFIX FIX-PRE-68-45 Doctor Performance 403 sprint had to add an `unlinked` tier for. Keying on doctor_id would silently exempt every unlinked doctor from the single-session rule. The existing enforcement cohort is already keyed on users.id (`AndroidDoctorEnforcementScope::coversUser` is called with `(int) $user->id` at DoctorAppLoginGate.php:156).

**D3. Exact cardinality-1 enforcement mechanism, given tests run sqlite locally and production is PostgreSQL 16?**

- DECIDE: An UNGUARDED partial unique index — `CREATE UNIQUE INDEX trx_doctor_session_leases_active_uq ON trx_doctor_session_leases (user_id) WHERE released_at IS NULL` — created via `DB::statement` in the SAME migration that creates the table, backed in PHP by a claim inside `DB::transaction` whose INSERT is wrapped in a nested `DB::transaction` (SAVEPOINT) and whose `catch` discriminates with a copy of `isUniqueViolation()`.
- REJECTED: A pgsql-only guarded index (the style at database/migrations/2026_06_30_110001:37-43) was rejected because it would put the sprint's core invariant outside every local run. A full `UNIQUE(user_id)` on one mutable row was rejected because it destroys the audit trail and pushes the DENY decision back into a raceable application read. `UNIQUE(user_id, released_at)` with NULL as the active sentinel does not work on either engine — both treat NULLs as distinct in a unique index — and a magic non-null sentinel has no precedent here, where every active-row predicate uses real NULL or a status string. `pg_advisory_lock` was rejected outright: config/postgres_runtime_governance.php:90 records 'No known pg_advisory_lock usage in application code', SQLite cannot express it, and a lock does not persist a claim.
- EVIDENCE: I corrected the sprint's premise here: CI does NOT run sqlite for the critical gate. `.github/workflows/foundation-evidence-gates.yml:315` and `:524` set job-level `DB_CONNECTION: pgsql`, and `phpunit.xml:25-26`'s `<env>` elements carry no `force="true"`, so the exported CI value wins — the authoritative run is PostgreSQL 16 and the local run is sqlite. Both engines support partial indexes, which the immediately adjacent daily-lock sprint states outright at database/migrations/2026_08_29_100002_create_trx_branch_change_requests_table.php:87-89 ('PostgreSQL and SQLite both support partial indexes, so the invariant is identical on the production driver and in the test suite') and demonstrates unguarded at :91-94, with a second unguarded `WHERE <col> IS NULL` precedent at database/migrations/2026_07_03_100001_add_inventory_batch_id_to_trx_stock_opname_items_table.php:30. The savepoint is ma …[TRUNCATED]

**D4. Is the locked branch a new table or an existing store?**

- DECIDE: A NEW table `mst_doctor_branch_locks` with `UNIQUE(doctor_id)`, `branch_id`, and approval provenance columns.
- REJECTED: Adding a `locked_branch_id` column to `mst_doctors` was rejected because it would sit immediately beside the deprecated `branch_id` on the same table, and because `DoctorService::canonicalWritePayload()` would have to be taught a second exception — a readability and safety hazard in the one place admins edit doctor master data.
- EVIDENCE: None of the six candidate stores can carry it. `mst_doctors.branch_id` is legacy and actively stripped on every doctor write (`canonicalWritePayload()` unsets it, app/Modules/Doctor/Services/DoctorService.php:76-80). `mst_doctor_branches` is `UNIQUE(doctor_id, branch_id)` (database/migrations/2026_06_29_150001:107) and the master-data forms require an ARRAY of at least one branch — it is the eligibility set, a different fact. `users.branch_id` is documented as an unassigned Kepala-Cabang fallback (database/migrations/2026_07_09_120002:154-161) and is only BranchContext link 2. `trx_user_online_contexts` is a session representation rewritten on every session start. `trx_daily_branch_contexts` is keyed `(user_id, clinical_date)` (2026_08_29_100001:76) and is per-day. `mst_doctor_devices.branch_id` is placement, not permission (config/doctor_device_enforcement.php:80-84).

**D5. Reuse DailyBranchContext for the doctor lock, or leave it alone?**

- DECIDE: LEAVE IT ALONE. Do not add `ROLE_DOCTOR` to `LOCKED_ROLE_CONTEXTS` and do not write doctor rows into `trx_daily_branch_contexts`.
- REJECTED: Extending the existing daily lock was rejected because a permanent lock with a date in its key is not a permanent lock, and because it would break two critical-gated tests while making the sprint's semantics ('one new free selection tomorrow') wrong. What IS reused is the PATTERN — transaction + row lock + savepoint + fail-closed re-validation — not the table.
- EVIDENCE: I read the constant directly: `DailyBranchContextService::LOCKED_ROLE_CONTEXTS` is exactly `[ROLE_ADMIN_CLINIC, ROLE_KASIR]` (app/Modules/RmeOnlineContext/Services/DailyBranchContextService.php:69-72), and it is pinned by an exact assertion plus an explicit `isLockedRoleContext(ROLE_DOCTOR)->toBeFalse()` at tests/Feature/AccessControl/DailyBranchContextLockTest.php:219-227, with a sibling test at :197 asserting 'leaves a doctor free to change branch and room'. Structurally it is also wrong: its identity includes `clinical_date`, so it grants a fresh free selection every clinical day, and it is keyed on `user_id` rather than doctor identity.

**D6. MODEL A (explicit doctor x device authorization rows) or MODEL B (fleet-wide device trust) for all-tablet access?**

- DECIDE: MODEL A, unchanged, with NO bulk provisioning in this sprint.
- REJECTED: MODEL B was rejected because it would delete the only per-doctor device predicate (DoctorAppLoginGate.php:290-297 and DoctorDeviceWebAuthnLoginService.php:297-306), would require widening `DoctorDeviceWebAuthnCredentialRepository::usableForDoctor()` (:45-48) whose narrowing exists to prevent estate disclosure, would invalidate the readiness engine's `REASON_NO_AUTHORIZATION` predicate and its 'all five conditions' test (tests/Feature/DoctorDevice/DoctorGlobalRolloutReadinessTest.php:141-149, :207), and is pinned against by tests/Feature/DoctorDevice/DoctorDeviceEnforcementGateTest.php:443. If a bulk sync is ever wanted it must use `DoctorDeviceAuthorizationService::resolveOrRequest()` then `approve()` — never `firstOrCreate`, because the model sets `protected $fillable = []` deliberately (app/Modules/DoctorDevice/Models/DoctorDeviceAuthorization.php:65-70) and the repository uses `forceF …[TRUNCATED]
- EVIDENCE: Requirement 2 is about BRANCH, and I verified it already holds: no predicate in any login path reads `mst_doctor_devices.branch_id`, `mst_doctor_device_authorizations` has no branch column at all (database/migrations/2026_09_03_110001:46-95), and the device's branch is derived FROM the doctor and documented as 'a placement, not a permission' (app/Modules/DoctorDevice/Services/DoctorAppLoginService.php:485-490). Separately, requirement 5 keeps global enforcement OFF, and with `doctor.trusted_device_enforcement` false (config/feature_flags.php:400) the per-pair check never runs at all — `denyBrowserSessionReason` returns null before touching the database (DoctorAppLoginGate.php:167-169). So no device-authorization change is needed for requirement 2 in either flag state.

**D7. Gate the new behaviour behind a feature flag?**

- DECIDE: YES — two NEW `doctor.*` registry entries, `doctor.single_active_session` and `doctor.branch_lock`, both `default => false`, `risk_level => 'critical'`, each with its own `env_key`, read only through `FeatureFlagService::enabled()`.
- REJECTED: Composing with `doctor.trusted_device_enforcement` was rejected twice over: reading that literal in any new file under app/, routes/ or bootstrap/ breaks the exact-equality readers pin at tests/Feature/DoctorDevice/DoctorDeviceEnforcementGateTest.php:517, and gating on it would make the new rules dead code today (it defaults false) or, via `inEnforcementScope()` (DoctorAppLoginGate.php:150-157), silently narrow them to the named pilot cohort. If the new code needs enforcement state at all it must ask `DoctorAppLoginGate::enforcementEnabled()` (:109-112).
- EVIDENCE: The doctor domain already runs two independent flags related by a declared dependency rather than a shared switch (config/feature_flags.php:397-408 and :410-421, dependency at :419), read at two separate call sites (DoctorAppLoginGate.php:111 and :358), so a third is the established pattern. Risky flags MUST default false or FLAG-RISKY-DEFAULT-OFF fails (app/Services/Foundation/FeatureFlagService.php:30, :126-132), and an `env_key` must be declared so the config-build capture loop at config/feature_flags.php:452-458 injects `env_value` and the flag survives `config:cache`.

**D8. How is the lease bound to a browser session, given the session id rotates?**

- DECIDE: A random token stored in session DATA, persisted only as its sha256 in `trx_doctor_session_leases.session_token_hash`.
- REJECTED: Keying on `session()->getId()` was rejected as self-invalidating; keying on `users.id` alone was rejected because the middleware could not then distinguish 'my own lease' from 'someone else's lease for the same user', which is exactly the discrimination requirement 1 needs.
- EVIDENCE: This is the codebase's own solution to the same problem: `DoctorDeviceWebAuthnChallengeService` mints a random token into session data and stores only its hash (app/Modules/DoctorDevice/Services/DoctorDeviceWebAuthnChallengeService.php:162-183), with the rationale at :133-160 that session DATA survives `regenerate()` but dies with `invalidate()`/`flush()`, and that binding to `session()->getId()` fails on a benign rotation whose failure mode is a doctor who cannot reach their patients. The rotation is real and triple on the WebAuthn path: AuthenticatedSessionController.php:34, then again via `invalidate()` at :83, then DoctorDeviceWebAuthnLoginService.php:353.

## C. Proposed insertion points (UNVERIFIED — the critique disputes several)

**IP1. (a) Single-session lease claim at login — ordinary password path**

- SITE: app/Http/Controllers/Auth/AuthenticatedSessionController.php:101, immediately before `return redirect()->to(` (the line is `return redirect()->to(` at :101 with the resolver call at :102)
- CHANGE: Call the NEW `DoctorSessionLeaseService::claimOrDeny($request, $request->user())`. This point is chosen over `Auth::attempt` (app/Http/Requests/Auth/LoginRequest.php:45) because the session id is rotated afterwards at :34, and over :34 itself because the gate-denial branch at :83 invalidates and rotates the session AGAIN — claiming at :101 is reached only by a session that survived the gate, so no compensating release is needed. A refused claim MUST call `DoctorDeviceSessionService::invalidate()` before throwing, exactly as :83 already does.
- RISK: Throwing a ValidationException alone does not undo `Auth::attempt`; the session is still written when the response is emitted. Also: `remember` is passed to `Auth::attempt` at LoginRequest.php:45 and the checkbox is rendered at resources/views/auth/login.blade.php:29-32, so a recaller cookie can re-authenticate a browser through no controller and bypass a claim enforced only at login call sites — the per-request revalidation in (b) is what closes this, not the claim.

**IP2. (a) Single-session lease claim at login — both device paths (Android ticket + browser WebAuthn)**

- SITE: app/Modules/DoctorDevice/Services/DoctorDeviceSessionService.php:163-179 (`bind()`), reached from :119 after the regenerate at :117, and from app/Modules/DoctorDevice/Services/DoctorDeviceWebAuthnLoginService.php:355 after the regenerate at :353
- CHANGE: Fold the lease claim into `bind()`, which the class docblock at :139-146 already calls 'the sanctioned owner of doctor session writes' and which is the single point both device paths converge on AFTER their final regenerate. `bind()` currently receives `$doctorId` (:167) but not the `User`; add the `User` (or a `userId`) parameter so the lease can be keyed on `users.id`. A refused claim must route through the existing `deny()` (:209-223) after calling `invalidate()`.
- RISK: Both callers already ran `Auth::guard('web')->login()` (DoctorDeviceSessionService.php:113, DoctorDeviceWebAuthnLoginService.php:348) and `session()->regenerate()` before reaching `bind()`, so a bare throw from inside `bind()` leaves an authenticated, regenerated session alive. `bind()`'s proof argument is required by signature on purpose — adding an optional-with-default lease parameter would reintroduce exactly the permissive-default hazard DOCTOR-PWA-WEBAUTHN-PROOF-BINDING-1 removed.

**IP3. (b) Per-request lease revalidation**

- SITE: bootstrap/app.php:59-70 — a NEW fourth entry appended to `$middleware->web(append: [...])`, after `EnsureDoctorDeviceSession::class` at :69
- CHANGE: Register a NEW `App\Modules\DoctorDevice\Middleware\EnsureDoctorSessionLease`. It must gate on its OWN new feature flag, resolve the session's lease token from session DATA, and on mismatch/absence call the existing `DoctorDeviceSessionService::invalidate($request, $user, $reason)` (:188-207) which audit-logs for free. Preserve the three route-name exemptions `doctor-device-login.redeem`, `logout`, `login` used at EnsureDoctorDeviceSession.php:52, and additionally exempt the online-context selector routes, because `EnsureRmeOnlineContext` runs earlier (bootstrap/app.php:61) and can redirect a doctor to `rme.online-context.select` before any lease check is reached.
- RISK: Putting this check inside `EnsureDoctorDeviceSession::handle()` or `DoctorAppLoginGate::denySessionReason()` makes it dead code: both return on their first branch while `doctor.trusted_device_enforcement` is off (EnsureDoctorDeviceSession.php:39-41, DoctorAppLoginGate.php:224-226) and the flag defaults false (config/feature_flags.php:400), and `denySessionReason` narrows again to the pilot cohort at :231-233. Note `POST /login` is UNNAMED (routes/auth.php:23) while GET login is named `login` (:20-21), so a `routeIs('login')` exemption covers only the GET.

**IP4. (c) Logout release**

- SITE: app/Http/Controllers/Auth/AuthenticatedSessionController.php:109-124 (`destroy()`), between the `markOffline` call at :114 and `Auth::guard('web')->logout()` at :117
- CHANGE: Release the lease held by the current session (set `released_at`, `released_reason = 'logout'`) before the guard logs out, while `$request->user()` and the session token are still readable. Mirror the release into `DoctorDeviceSessionService::invalidate()` (:188-207) so every teardown path — the gate denial at AuthenticatedSessionController.php:83, the per-request middleware at EnsureDoctorDeviceSession.php:62, and the new lease middleware — releases too rather than orphaning a lease until TTL.
- RISK: app/Http/Controllers/ProfileController.php:56-63 is a THIRD teardown path (account deletion) that must also release, or a deleted user's lease blocks nothing but lingers. Releasing only in the controller and not in `invalidate()` orphans a lease for every doctor sent to the WebAuthn ceremony at :86-88 who never completes it.

**IP5. (d) Lease expiry / recovery**

- SITE: The NEW `DoctorSessionLeaseService::claimOrDeny()`, at the moment it detects an existing unreleased lease for the user
- CHANGE: Treat a lease whose `last_seen_at` is older than the TTL as reclaimable: inside the claim transaction, mark it released with reason `expired` and continue to claim. Renew `last_seen_at` from the (b) middleware. The TTL must be reconciled against `config/session.php:35` (`SESSION_LIFETIME`, default 120 minutes) and `:37` (`expire_on_close` = false): a TTL >= 120 minutes can never free a slot, and a shorter one costs an idle doctor their own slot unless the middleware renews it.
- RISK: There is NO throttled-write precedent to copy — the only touch pattern, `TouchOnlineContextLastSeen` (app/Modules/RmeOnlineContext/Middleware/TouchOnlineContextLastSeen.php:19-28 → UserOnlineContextService.php:404-413), does one SELECT plus one unconditional UPDATE per authenticated request with no interval guard, and app/ contains no `Cache::add(` or `Cache::remember(` at all. An unthrottled renewal would put a hot row under a unique index on every request. Also: `expire_on_close=false` means closing the tablet lid releases nothing, so TTL reclamation is mandatory, not optional.

**IP6. (e) Force logout (releasing another device's lease)**

- SITE: The NEW `DoctorSessionLeaseService`, plus the release write inside the NEW `DoctorBranchTransferApprovalService::approve()` transaction
- CHANGE: Express force-logout as DATA, not as an in-process call: mark the target lease row `released_at` inside a transaction, and let the (b) middleware tear the victim's session down on its NEXT request. This is the same shape the daily-lock approval already relies on — `BranchChangeApprovalService::approve()` realigns the online context so 'the operator's existing sessions resolve to the new branch on their very next request' (app/Modules/RmeOnlineContext/Services/BranchChangeApprovalService.php:186-192).
- RISK: `DoctorDeviceSessionService::invalidate()` CANNOT be reused here — it operates only on the request in front of it and its docblock at :181-187 explicitly refuses a global variant. A `DELETE FROM sessions WHERE user_id = ?` alternative is physically cheap (user_id is indexed, create_users_table.php:32) but is UNTESTABLE as the suite is configured (SESSION_DRIVER=array in phpunit.xml:29 and in both CI jobs at foundation-evidence-gates.yml:323 and :545) and can be defeated by a remember-me recaller cookie. Be honest in the sprint doc that this is next-request eviction, not instantaneous.

**IP7. (f) Locked branch read inside BranchContext**

- SITE: app/Modules/Branch/Services/BranchContext.php:67 — a NEW first term in the `forUser()` null-coalescing chain, before `$this->branchIdFromOnlineContext($user)`
- CHANGE: Add `private function branchIdFromDoctorLock(User $user): ?int` guarded by `Schema::hasTable(...)` in the style of :83-85, reading the NEW `mst_doctor_branch_locks` row, and place its call first so it outranks the online-context session row (:67), `users.branch_id` (:68), the dead relation link (:69) and the MAIN/first-active default (:70). It must FAIL CLOSED to null when the locked branch is no longer active + RME-enabled, copying the daily-lock rationale verbatim from UserOnlineContextService.php:236-241 — falling through would turn a deactivated branch into a route back to the session row.
- RISK: Returning null makes `requireId()` throw (:54-63), which is the correct fail-closed shape for a write path but will surface as a 500 on read paths unless callers are checked. Editing ONLY BranchContext leaves the lock cosmetic on the doctor's largest surface: `RmeWorkingBranchScope` deliberately excludes Doctor from context binding (app/Modules/RmeOnlineContext/Services/RmeWorkingBranchScope.php:26-28, :40-49) and returns the FULL active RME set (:68-78), and every visit list, queue, worklist and count funnels through it via `ClinicVisitService::scopeBranchIds()` (app/Modules/ClinicVisit/Services/ClinicVisitService.php:74-77).

**IP8. (f-bis) Locked branch enforced on the doctor's operational workspace**

- SITE: app/Modules/RmeOnlineContext/Services/RmeWorkingBranchScope.php:40-49 (`isContextBound()`) and :68-78 (`branchIdsFor()`)
- CHANGE: Make `branchIdsFor()` return `[lockedBranchId]` for a Doctor by consulting the NEW doctor-lock service DIRECTLY, not by adding Doctor to `isContextBound()`. Adding Doctor to `isContextBound()` would route `branchIdsFor()` through `activeContextBranchId()`, which fails closed to `[]` whenever the doctor is offline (:73-76) — that would blank every doctor list rather than lock it.
- RISK: This narrows the doctor's Daftar Kunjungan, patient queue, room worklist and count widgets from the full RME set to one branch, and collapses `ClinicVisitService::selectableRmeBranches()` (:113-119) to a single option. It must NOT be extended to `DoctorClinicalBranchResolver` (app/Modules/Doctor/Services/DoctorClinicalBranchResolver.php:99-125), whose docblock at :33-41 exists specifically so a doctor standing at one branch can read a patient's archive from another; that consumer (app/Modules/LegacyRme/Support/LegacyRmeWorkspaceScope.php:76-78) stays practice-set-wide.

**IP9. (f-ter) Locked branch enforced on the write side of session start**

- SITE: app/Modules/RmeOnlineContext/Services/UserOnlineContextService.php:277-281, immediately AFTER the existing practice-pivot assertion `if (! $doctor->branches->contains('id', $branchId))`
- CHANGE: Assert the submitted branch equals the doctor's locked branch, throwing the same shape of ValidationException. The ordering — eligibility first, lock second — is the established rule: `DailyBranchContextService::assertSelectable` is called only after eligibility (:120-126) so an approved switch can never confer access to a branch the actor was never entitled to work in.
- RISK: The selector Blade at resources/views/rme/online-context/select.blade.php:47-55 renders a free `branch_id` dropdown over `doctorAllowedBranches` supplied by app/Modules/RmeOnlineContext/Controllers/OnlineContextController.php:45-55, :72. Changing only the Blade is not enforcement; changing only the service leaves a dropdown that offers branches the server will refuse. Both are needed, and the service is the boundary.

**IP10. (g) Branch-transfer approval transaction**

- SITE: NEW `App\Modules\Doctor\Services\DoctorBranchTransferApprovalService::approve()`, modelled on app/Modules/RmeOnlineContext/Services/BranchChangeApprovalService.php:147-215
- CHANGE: One `DB::transaction` that: locks the request row via a repository `lockById()` (`whereKey()->lockForUpdate()->first()`, cf. app/Modules/RmeOnlineContext/Repositories/BranchChangeRequestRepository.php:18-24); asserts requester !== approver INSIDE the lock (the enforced boundary, cf. BranchChangeApprovalService.php:311-315); re-asserts status pending; locks the `mst_doctor_branch_locks` row; applies the STALE-SOURCE guard (current lock branch must still equal `source_branch_id`, cf. :166-170); re-validates the destination is active + RME-enabled at decision time (:174, `assertEligibleDestination`); moves the lock; releases the doctor's live lease; stamps the decision; audits last. Decision columns must be written through a repository using `forceFill`, because the exemplar model deliberately excludes them from `$fillable` (app/Modules/RmeOnlineContext/Models/BranchChangeRequest.php:50-58) so `->update()` silently discards them.
- RISK: Do NOT extend `BranchChangeApprovalService`: I verified `BranchChangeRequestPolicy::create()` (app/Modules/RmeOnlineContext/Policies/BranchChangeRequestPolicy.php:54-60) requires a LIVE daily context whose `role_context` is locked — a doctor never has one — and `approve()` hard-requires `$this->contexts->lockForUser(user, clinical_date)` to be non-null (:152-161), and the pending-uniqueness index is keyed on `(requester_user_id, clinical_date)` (database/migrations/2026_08_29_100002_create_trx_branch_change_requests_table.php:91-94). A permanent per-doctor lock has no clinical_date, so reuse would require forking every guard in that service.

**IP11. (h) Session invalidation on branch approval**

- SITE: Inside the same `DB::transaction` opened by (g), at the point corresponding to app/Modules/RmeOnlineContext/Services/BranchChangeApprovalService.php:189-192 (`realignOnlineContext`)
- CHANGE: Set `released_at`/`released_reason = 'branch_transfer_approved'` on the doctor's unreleased lease row, and realign or clear the `trx_user_online_contexts` row so the doctor must re-select a room in the new branch. Both writes commit with the branch move, so there is no replayable 'approved' token and no second endpoint — the property `BranchChangeApprovalService`'s docblock at :23-31 states for the daily lock.
- RISK: This is the one place the sprint reverses a written prior decision (DoctorDeviceSessionService.php:181-187 refuses global invalidation) and it must say so explicitly in the sprint doc. It must NOT touch `mst_doctor_devices`, `mst_doctor_device_authorizations` or `trx_doctor_device_webauthn_credentials` — WebAuthn credential revocation is irreversible (the only write to `revoked_at` is `=> now()` at app/Modules/DoctorDevice/Services/DoctorDeviceWebAuthnRegistrationService.php:282) and requirement 4 forbids it.

**IP12. (i) All-trusted-tablet device authorization**

- SITE: NO CODE CHANGE. The relevant sites are app/Modules/DoctorDevice/Services/DoctorAppLoginGate.php:290-297, app/Modules/DoctorDevice/Services/DoctorDeviceWebAuthnLoginService.php:297-306, app/Modules/DoctorDevice/Services/DoctorDeviceSessionService.php:107-111 and app/Modules/DoctorDevice/Repositories/DoctorDeviceWebAuthnCredentialRepository.php:45-48 — all of which must be left exactly as they are.
- CHANGE: None. I verified that no predicate anywhere in the login path reads `mst_doctor_devices.branch_id`: DoctorAppLoginGate contains zero branch reads (its only 'branch' occurrences at :135, :179 and :324 are prose), `mst_doctor_device_authorizations` has no branch column (database/migrations/2026_09_03_110001:46-95), and config/doctor_device_enforcement.php:80-84 already declares the pilot branch code advisory with BranchContext named as the authority. Requirement 2 therefore already holds and the sprint's obligation is to NOT introduce a device-branch check while adding the locked clinical branch.
- RISK: The tempting shortcut for 'a doctor may use ALL trusted tablets' is to widen `DoctorDeviceWebAuthnCredentialRepository::usableForDoctor()` (:33-68) by dropping its ACTIVE-authorization lookup at :45-48, or to relax the exact-pair assertion at DoctorDeviceWebAuthnLoginService.php:297-306. Either would delete the only per-doctor device predicate and is pinned against by tests/Feature/DoctorDevice/DoctorDeviceEnforcementGateTest.php:443 ('requires each doctor on a shared tablet to hold their own approval') and tests/Feature/DoctorDeviceWebAuthn/DoctorPwaWebAuthnProofBindingTest.php:439.

## D. Blocking unknowns

1. How many ACTIVE `mst_doctor_devices` rows and how many Doctor-role users exist on the VPS pilot — needed to size any future MODEL A provisioning, and unobtainable here: this worktree has no vendor/ and no environment file, so artisan cannot run, and running `tinker` on production is forbidden (config/release_safety.php:137-148 blocks it outside local/testing, and the standing rule is that a REPL pins the monitoring log signal to WATCH for 24h). The query SHAPE is: Doctor-role users via `App\Models\User::whereHas('roles', fn ($q) => $q->where('name', 'Doctor'))` (app/Modules/DoctorDevice/Repositories/DoctorDeviceRolloutReadinessRepository.php:22-28) intersected with `mst_doctors.user_id`, times `mst_doctor_devices WHERE status = 'active'`, minus existing pair rows grouped by status.

2. Which specific doctors currently hold more than one practice branch in `mst_doctor_branches`, and what each one's locked branch should be. The lock cannot be backfilled by code: `mst_doctor_branches` is `UNIQUE(doctor_id, branch_id)` (database/migrations/2026_06_29_150001:107) and the master-data forms require an array of at least one branch (app/Modules/Doctor/Requests/StoreDoctorRequest.php:21-22), so for any multi-branch doctor there is no algorithmic 'correct' single branch. This is an OWNER DECISION per doctor, and the migration must leave the lock row absent (not guessed) for anyone not explicitly assigned.

3. Whether the sprint intends the locked branch to narrow a doctor's OPERATIONAL LISTS (Daftar Kunjungan, patient queue, room worklist, counts) or only the `BranchContext` write path. This is an owner/product decision with visible clinical impact: `RmeWorkingBranchScope` deliberately excludes Doctor (app/Modules/RmeOnlineContext/Services/RmeWorkingBranchScope.php:26-28, :40-49) and every one of those surfaces funnels through it (app/Modules/ClinicVisit/Services/ClinicVisitService.php:74-77), so today a doctor sees the full active RME set. Editing only BranchContext leaves the lock cosmetic there; editing the scope changes what doctors see on their busiest screens.

4. Whether the locked branch should also narrow LEGACY ARCHIVE reads. `DoctorClinicalBranchResolver` (app/Modules/Doctor/Services/DoctorClinicalBranchResolver.php:99-125) returns the whole practice set and its docblock at :33-41 argues explicitly that a doctor standing at one branch must be able to read a patient's archive from another; its consumers are app/Modules/LegacyRme/Support/LegacyRmeWorkspaceScope.php:76-78 and app/Modules/LegacyOdontogram/Support/LegacyOdontogramWorkspaceScope.php:77-78. Narrowing it removes a documented clinical capability and needs an explicit owner decision, not an implementer's judgement.

5. Whether approval authority for a doctor branch transfer should be a NEW permission (`approve_doctor_branch_transfer`, granted to Supervisor RME in RoleSeeder, Super Admin arriving via the single global `Gate::before` at app/Providers/RepositoryServiceProvider.php:596) or a widened role Gate. The existing `branch-change-request.approve` Gate is Super Admin ONLY (app/Providers/RepositoryServiceProvider.php:593) and its comment at :587-592 argues deliberately AGAINST turning it into a permission; widening that closure would also silently widen the daily-lock approval surface to Supervisor RME. The sprint requires Super Admin OR Supervisor RME, so one of the two must be chosen by the owner.

6. Whether `Auth::guard('web')->logout()` cycles `users.remember_token`. This cannot be verified from this worktree — vendor/ is absent — and it is load-bearing: remember-me is live end to end (the checkbox at resources/views/auth/login.blade.php:29-32 feeds `Auth::attempt($credentials, $this->boolean('remember'))` at app/Http/Requests/Auth/LoginRequest.php:45, and `users.remember_token` exists at database/migrations/0001_01_01_000000_create_users_table.php:20), so a recaller cookie may be able to mint a fresh authenticated session without passing through any of the four login call sites. Must be settled empirically before claiming the single-session rule is unbypassable.

7. The chosen lease TTL, which is an operational decision about clinic workflow, not a code fact. It must be reconciled against `SESSION_LIFETIME` 120 minutes and `expire_on_close=false` (config/session.php:35, :37): longer than 120 minutes and a stale lease outlives the session it guards; shorter and an idle doctor between patients loses their own slot unless the per-request middleware renews it. There is no scheduled session pruner (`routes/console.php` contains no session task), so TTL reclamation is the only recovery path.

## E. Migration cost (tests that break)

1. tests/Feature/AccessControl/DailyBranchContextLockTest.php:197 ('leaves a doctor free to change branch and room') asserts a doctor may move from branch A to branch B via `startDoctorSession` and that `BranchContext` then resolves to B. If a doctor branch lock is enforced at UserOnlineContextService.php:277-281 this test's premise becomes the thing the sprint forbids. It is inside the CI critical filter via the `DailyBranchContext` token (.github/workflows/foundation-evidence-gates.yml:422) and must be rewritten, not deleted, as the visible governance record of the reversal.

2. tests/Feature/AccessControl/DailyBranchContextLockTest.php:219-227 pins `DailyBranchContextService::LOCKED_ROLE_CONTEXTS` with `toEqualCanonicalizing([ROLE_ADMIN_CLINIC, ROLE_KASIR])` and separately asserts `isLockedRoleContext(ROLE_DOCTOR)` is false. It breaks ONLY if the doctor lock is implemented by adding Doctor to that constant — which the contract recommends against; if the recommendation is followed this test stays green and is the proof the daily lock was left alone.

3. tests/Feature/DoctorDevice/DoctorDeviceEnforcementGateTest.php:498-518 walks every .php file under app/, routes/ and bootstrap/ for the literal `'doctor.trusted_device_enforcement'` and asserts the reader list is EXACTLY `['app/Modules/DoctorDevice/Services/DoctorAppLoginGate.php']`. Any new lease or branch-lock file that reads that flag key — even in a comment quoting it — fails this test. Cost is avoidance, not a repin.

4. tests/Feature/DoctorDeviceWebAuthn/DoctorPwaWebAuthnCredentialRevocationTest.php:165-190 and :217-229 fake 'two browsers' by snapshotting `session()->all()`, acting as a Super Admin, then `session()->flush()` + `session()->replace()` + `Auth::guard('web')->forgetUser()`. If the lease is written or renewed by MIDDLEWARE on every authenticated request rather than only at the login call sites, the operator's requests register a lease and the restored doctor session then matches none — breaking at least :288, :409, :458, :480, :498, :517, :543 in that file plus tests/Feature/DoctorDeviceWebAuthn/DoctorPwaWebAuthnProofBindingTest.php:439, all for harness reasons. Mitigation is a design constraint: CLAIM only at login, RENEW conditionally, and never create a lease for a session that never had one.

5. Every `actingAs()`-based doctor test bypasses the login pipeline and therefore mints no lease — `doctorWithOnlineContext`/`rmeMakeDoctorOnline` (tests/Pest.php:497, :512) appear 75 times across 36 files, and `actingAs(` appears 3554 times across tests/. If the per-request middleware DENIES a doctor session with no lease row, the migration cost is effectively the whole suite. The middleware must therefore treat 'no lease at all' as pass-through and deny only a session whose token contradicts a live lease.

6. tests/Feature/DoctorDevice/DoctorDeviceEnforcementGateTest.php:325 ('makes a login ticket single-use') redeems a ticket, posts `route('logout')` at :348, then re-redeems and expects a redirect to login at :352. A single-session denial would satisfy that assertion for the WRONG reason and silently hide the replay regression the test exists to catch; the same false-green shape sits at tests/Feature/DoctorDeviceWebAuthn/DoctorPwaWebAuthnTest.php:417-431, whose teardown is `auth()->logout(); session()->flush();` with no logout controller running at all. Both need an added assertion that distinguishes the refusal reason.

7. tests/Feature/DoctorDeviceWebAuthn/DoctorPwaWebAuthnTest.php:528 ('does not let one visitor complete a challenge issued to another') performs a SECOND `post(route('login'))` for the same account after `session()->flush()` and expects `assertSessionHasErrors('credential')` at :542. If the lease claim fires at the password step the error key becomes `email` and the test fails outright — which is the concrete reason the claim belongs at AuthenticatedSessionController.php:101, after the gate, not at :34.

8. tests/Feature/MasterData/DoctorRmeBranchSourceTest.php:272 and :279 assert a doctor with two practice branches may go online in EITHER, and :298/:311 pin the branch-selection UI for doctors. A locked branch breaks all four. Note this file matches NO token in the critical filter (its identity is `Tests\Feature\MasterData\DoctorRmeBranchSourceTest`), so the breakage surfaces only in a full-suite run — it must be repinned deliberately rather than discovered later.

9. tests/Feature/DoctorDevice/DoctorDeviceAppLoginRequestTest.php:341 ('files a new device in the doctor own rme branch, never just any branch') syncs the practice pivot mid-test, and :363 ('refuses a doctor with no active rme branch rather than inventing one') syncs it to empty and expects 422. Both remain valid ONLY if the lock is a new layer over the pivot rather than a replacement; if `mst_doctor_branches` were collapsed to a single branch, :363's setup becomes unreachable.

10. tests/Feature/Auth/SupervisorRmeRolePermissionTest.php:16-95 duplicates the Supervisor RME permission set as a file-level const and asserts it with a sorted whole-set `toBe` at :109-110 — the only exact whole-role pin in the suite. If approval authority is implemented as a NEW permission granted to Supervisor RME in RoleSeeder, that const must be repinned; if it is implemented as a widened role Gate, nothing repins.

11. database/seeders/PermissionSeeder.php:17-303 (`PERMISSIONS`) must gain any new permission name, because three tests compare the grouped permission set against that constant exactly (tests/Feature/AccessControl/RoleManagementTest.php:247, tests/Feature/Sprint55/Sprint55PermissionGroupClassificationCleanupTest.php:173, tests/Feature/Sprint53/Sprint53PermissionPageModuleGroupingHotfixTest.php:176). An unclassified permission is tolerated (it falls into the 'Other' bucket via PermissionGroupingService.php:437-455) but an UNLISTED one is not.

12. New test files must live in tests/Feature/DoctorDevice/ or be declared in `config/ci_runner.php:172` (`critical_gate_mandatory_suites`). The critical filter selects by a substring match against a path-derived identity (app/Support/Cicd/SelfHostedRunnerScanner.php:487, :530-541), and the `DoctorDevice` token matches the DIRECTORY segment — a suite named e.g. `Tests\Feature\Auth\DoctorBranchLockTest` matches no token in .github/workflows/foundation-evidence-gates.yml:422 and would run in NO gate, meaning the PostgreSQL proof of the partial unique index never executes anywhere.

## F. Top traps

1. Believing the sprint premise that tests only run SQLite. I verified job-level `DB_CONNECTION: pgsql` at .github/workflows/foundation-evidence-gates.yml:315 and :524, and phpunit.xml:25-26's `<env>` elements carry no `force="true"` so they do not override an exported value — the authoritative critical gate runs PostgreSQL 16 while the local run is SQLite. Reports 1, 7 and 8 asserted the sqlite-only premise and are WRONG; report 9 checked and is right. Consequence in both directions: a pgsql-only index IS verifiable in CI, and a green LOCAL run proves nothing about it.

2. Trusting the CLAUDE.md-derived 'active online context -> users.branch_id -> branches() relation -> MAIN' description of BranchContext. I read the source: `branchIdFromUserRelation()` is guarded by `method_exists($user, 'branches')` (app/Modules/Branch/Services/BranchContext.php:107-111) and `App\Models\User` has no `branches()` method (only `homeBranch()` at app/Models/User.php:67), so link 3 is dead code and the chain has three live links. An implementer inserting the lock 'before the relation link' would place it in the wrong position.

3. Putting the single-session or branch-lock check inside `EnsureDoctorDeviceSession` or `DoctorAppLoginGate::denySessionReason()`. Both return on their first branch while `doctor.trusted_device_enforcement` is off (EnsureDoctorDeviceSession.php:39-41, DoctorAppLoginGate.php:224-226) and the flag defaults false (config/feature_flags.php:400), and `denySessionReason` narrows again to the named pilot cohort at :231-233. The code would look correct, pass a test that flips the flag on, and enforce nothing in production — while requirement 5 keeps that flag off.

4. Refusing a second login by throwing a ValidationException alone. On all four paths the guard has already authenticated (LoginRequest.php:45, DoctorDeviceSessionService.php:113, DoctorDeviceWebAuthnLoginService.php:348, RegisteredUserController.php:48) and the session is written when the response is emitted, so a throw leaves a live authenticated session. The refusal must call `DoctorDeviceSessionService::invalidate()` first, exactly as AuthenticatedSessionController.php:83 does before its own throw.

5. Catching the unique-index violation INSIDE the claim transaction and then SELECTing the incumbent lease to build the denial message. On PostgreSQL the transaction is already aborted and the follow-up SELECT returns 25P02; on SQLite it appears to work, which is how this shape survives review — there are three live instances of it in the codebase today (app/Modules/RME/Services/PatientDoctorAssignmentService.php:48 and :172, app/Modules/MedicalRecord/Services/DiagnosisRolloutService.php:127). Catch OUTSIDE the transaction (app/Modules/DoctorDevice/Services/DoctorDeviceAuthorizationService.php:73-114) or wrap only the INSERT in a nested transaction/SAVEPOINT (app/Modules/RmeOnlineContext/Services/DailyBranchContextService.php:165-172), and discriminate with `isUniqueViolation()` (:219-233) rather than a bare `catch (QueryException)`.

6. Creating the lease's partial unique index in a migration SEPARATE from and later than the table. On SQLite any subsequent `constrained()` FK column add rebuilds the table and silently DROPS the WHERE clause, flattening `UNIQUE(user_id) WHERE released_at IS NULL` into a plain `UNIQUE(user_id)` — which for a lease does not weaken the constraint, it makes it permanently unreleasable so a doctor could never log in a second time, and only in the local suite. This exact flattening already happened once (database/migrations/2026_07_19_100010_reassert_single_primary_pilot_partial_unique_index.php:8-19) and `SchemaFacts::hasUniqueIndexOn()` cannot detect it, because `Schema::getIndexes()` exposes name/unique/columns but not the predicate (tests/Support/Database/SchemaFacts.php:55-64, :93-102) — it must be caught behaviourally by proving a released doctor can claim again.

7. Keying the lease on `session()->getId()`. The id rotates three times on the WebAuthn path (AuthenticatedSessionController.php:34, again via `invalidate()` at :83, then DoctorDeviceWebAuthnLoginService.php:353), and this codebase already fixed the same defect once by binding the ceremony nonce to session DATA with a sha256 at rest specifically because 'this feature's own login path regenerates twice before the ceremony even starts' (app/Modules/DoctorDevice/Services/DoctorDeviceWebAuthnChallengeService.php:133-183).

8. Expecting `DoctorDeviceSessionService::invalidate()` to end a session held on another device. It acts only on the request in front of it and its docblock at :181-187 explicitly refuses a global variant, so requirement 4's 'atomically invalidates the active session' has no existing primitive; and the obvious alternative, `DELETE FROM sessions WHERE user_id = ?`, is untestable as configured because SESSION_DRIVER is `array` in phpunit.xml:29 AND in both CI jobs (.github/workflows/foundation-evidence-gates.yml:323, :545), so the table is empty in every test run on both drivers.

9. Assuming 'a doctor may use ALL trusted tablets' requires relaxing a device predicate. It does not — no login-path predicate reads a device branch, and `mst_doctor_device_authorizations` has no branch column (database/migrations/2026_09_03_110001:46-95). The tempting shortcuts (widening `usableForDoctor()` at app/Modules/DoctorDevice/Repositories/DoctorDeviceWebAuthnCredentialRepository.php:45-48, or relaxing the exact-pair assert at DoctorDeviceWebAuthnLoginService.php:297-306) would delete the only per-doctor device approval predicate and are pinned against by tests/Feature/DoctorDevice/DoctorDeviceEnforcementGateTest.php:443 and tests/Feature/DoctorDeviceWebAuthn/DoctorPwaWebAuthnProofBindingTest.php:439.

10. Extending `BranchChangeApprovalService` for the doctor transfer. I verified `BranchChangeRequestPolicy::create()` requires a live daily context whose role_context is locked (app/Modules/RmeOnlineContext/Policies/BranchChangeRequestPolicy.php:54-60) — a doctor never has one — `approve()` hard-requires a non-null daily context row (:152-161), and the pending-uniqueness index is keyed on `(requester_user_id, clinical_date)` (database/migrations/2026_08_29_100002:91-94). Reuse means forking every guard; copy the SHAPE into a new service instead.

11. Writing the self-approval check in the new policy and stopping there. The single global `Gate::before` at app/Providers/RepositoryServiceProvider.php:595-598 returns true for Super Admin before any policy method runs, so the clause never executes for the one actor who could be both parties — `BranchChangeRequestPolicy::decide()` states this verbatim at :74-87 and the enforced boundary lives in the service inside the lock at BranchChangeApprovalService.php:311-315. No policy in app/ defines a `before()` hook and the new one must not either.

12. Setting the new feature flags with dot notation. Flag KEYS contain dots, so `config()->set('feature_flags.flags.doctor.single_active_session', true)` builds a nested structure `FeatureFlagService` never reads and the test passes with the flag OFF — documented three times in the doctor corpus (tests/Feature/DoctorDeviceWebAuthn/DoctorPwaWebAuthnTest.php:52-57, tests/Feature/DoctorDevice/DoctorDeviceEnforcementGateTest.php:40-46). Arm flags by rewriting the whole `feature_flags.flags` array and setting BOTH `default` and `env_value`.

13. Registering the new policy the stock-Laravel way. There is no `AuthServiceProvider` in app/ and bootstrap/providers.php lists only three providers; policies are registered from a PRIVATE `$policies` array on RepositoryServiceProvider (app/Providers/RepositoryServiceProvider.php:447, booted at :565-567), so a `protected $policies` anywhere registers nothing at all.

## G. Adversarial critique of the above (16 gaps, 4 wrong claims)

**G1 [critical] A global middleware whose FQCN merely CONTAINS the string `DoctorDevice` fails a critical-gated route contract on every web route — which is exactly what the synthesis's proposed `App\Modules\DoctorDevice\Middleware\EnsureDoctorSessionLease` is.**

- WHY: Insertion point (b) is the load-bearing enforcement of requirements 1 and 4 (per-request revalidation + next-request eviction). As specified it cannot be merged: the test iterates EVERY registered route's `gatherMiddleware()` and, for any entry containing `DoctorDevice`, asserts `expect($entry)->toBe(EnsureDoctorDeviceSession::class)`. The collision is on the NAMESPACE segment, so renaming the class to `EnsureDoctorSessionLease` does not save it — only a different module namespace (e.g. `App\Modules\DoctorAccess\...`) or a deliberate repin of this contract does. The suite is selected by the `DoctorDevice` token in the critical filter, so this reddens CI, not a later full-suite run.
- EVIDENCE: tests/Feature/DoctorDevice/DoctorDeviceApiAndNoEnforcementTest.php:246-278 — `$allowed = EnsureDoctorDeviceSession::class;` then `foreach (app('router')->getRoutes() ...)`, `if (str_contains($entry,'DoctorDevice') ...) expect($entry)->toBe($allowed, "unexpected device middleware on {$uri}")`, with only `device-api`-prefixed URIs skipped. Filter token `DoctorDevice` at .github/workflows/foundation-evidence-gates.yml:422. The synthesis's migration_cost list names neither this test nor this constraint.

**G2 [critical] Two independent tests source-scan the exact three directories the sprint edits — `app/Http/Controllers/Auth/*.php`, `app/Http/Middleware/*.php`, `app/Services/Auth/*.php` — plus `bootstrap/app.php` and `LoginRequest.php`, and reject ANY identifier matching `/[A-Za-z_]*DoctorDevice[A-Za-z_]*/` that is not in a four-item allowlist.**

- WHY: Every one of the synthesis's edit sites is inside this scan: AuthenticatedSessionController (a, c), LoginRequest (rejected alternative), bootstrap/app.php (b), and `app/Services/Auth/` is the natural home for a `DoctorSessionLeaseService` sitting beside `PostAuthenticationRedirectService`. The allowlist is `['DoctorDevice','DoctorAppLoginGate','DoctorDeviceSessionService','EnsureDoctorDeviceSession']`, so a lease or lock class named with a `DoctorDevice` prefix, or a second collaborator imported into those files, fails. The synthesis cites only the single-flag-reader pin (DoctorDeviceEnforcementGateTest:498-518) and misses this broader symbol allowlist entirely.
- EVIDENCE: tests/Feature/DoctorDevice/DoctorDeviceAccessTest.php:199-244 and tests/Feature/DoctorDevice/DoctorDeviceApiAndNoEnforcementTest.php:188-236 — identical `glob(base_path('app/Http/Controllers/Auth/*.php'))` + `app/Http/Middleware/*.php` + `app/Services/Auth/*.php` arrays, `preg_match_all('/[A-Za-z_]*DoctorDevice[A-Za-z_]*/', $contents, $matches)`, `expect($symbol)->toBeIn($allowed, ...)`. DoctorDeviceAccessTest additionally includes `base_path('bootstrap/app.php')` at :214.

**G3 [critical] No reader opened the Android client, and with enforcement OFF (requirement 5) the tablet does NOT redeem a login ticket — it opens the ordinary web login inside the WebView. Both of the synthesis's 'device path' lease-claim insertion points are therefore dead code in the world requirement 5 mandates.**

- WHY: The synthesis treats `DoctorDeviceSessionService::bind()` as the shared chokepoint 'both device paths converge on' and spends a whole insertion point folding the lease into it. In production that path never executes. Worse, the live behaviour is actively hostile to requirement 1: the app collects credentials, calls device-api `doctor/login`, gets no ticket, and then loads `/login` in the WebView — so EVERY app launch is a fresh password login (path A) and therefore a fresh lease claim. Under 'deny the second login' the tablet is refused; under 'newest session wins' every app launch silently evicts the doctor's other session. Neither outcome is discussed, and the WebView's persistent cookie jar means a force-logged-out tablet lands on /login and re-authenticates immediately, producing a two-device ping-pong.
- EVIDENCE: android/daengtisia-clinic/app/src/main/java/com/daengtisia/clinic/enrollment/DoctorLoginState.kt:72-80 — `"active" -> if (!result.loginTicket.isNullOrBlank()) APPROVED_OPEN_CLINIC else APPROVED_ENFORCEMENT_OFF`, commented 'That is the enforcement-off world'. android/.../ui/MainActivity.kt:266 `openClinic("device-login/" + ticket)` vs :271 `APPROVED_ENFORCEMENT_OFF -> openClinic()` (base URL, ordinary login), and :401-410 `view.loadUrl(if (path.isNullOrBlank()) BuildConfig.CLINIC_BASE_URL ...)`.

**G4 [critical] The contract is internally inconsistent: insertion point (g) requires a pending branch-transfer REQUEST row (row lock by id, `status` re-assert, `source_branch_id` stale guard, decision columns written via forceFill), but RUNTIME_FIX_REQUIRED declares only two migrations — `trx_doctor_session_leases` and `mst_doctor_branch_locks`. There is no request table, and no routes, controller, FormRequests, policy, Gate, views or sidebar entry anywhere in the plan.**

- WHY: Requirements 3 and 4 (locked branch + approved transfer that invalidates the session) cannot be operated without a request/approval surface. The named exemplar shipped all of it, so the omission is a large, sizable scope hole rather than a detail: 6 routes, a 6-action controller, 2 FormRequests, 2 Blade views, a policy, a Gate and a sidebar group. A reviewer reading only `decisions[]` would conclude two tables suffice.
- EVIDENCE: Synthesis RUNTIME_FIX_REQUIRED lists two migrations; insertion point (g) requires `re-asserts status pending` and a `source_branch_id` guard. The exemplar's real surface: routes/web.php:650-666 (create/store/cancel/index/approve/reject), resources/views/rme/branch-change-requests/create.blade.php + index.blade.php, app/Modules/RmeOnlineContext/Policies/BranchChangeRequestPolicy.php, Gate at app/Providers/RepositoryServiceProvider.php:593, sidebar group at resources/views/layouts/partials/sidebar.blade.php:264-278.

**G5 [high] The sprint manifest contract is entirely absent from the synthesis. `.sprint/current.yml` is a ROLLING file that this sprint must rewrite, and a critical-gated test asserts it still names the inherited programme closure; separately the validator hard-errors on manifest-vs-diff contradictions the sprint will certainly trip.**

- WHY: Rewriting `.sprint/current.yml` for DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 and dropping the `inherits_closure` line fails `LegacyRmeProgramClosureContractTest` (selected by the `LegacyRme` token in the critical filter). And the sprint adds migrations, touches middleware/policies, and (per f-ter) touches Blade/Alpine — so `schema_change`, `security_impact` and `frontend_change` must all be declared true or `sprint:manifest-check` errors. None of this appears in migration_cost or in any insertion point.
- EVIDENCE: tests/Feature/LegacyRme/LegacyRmeProgramClosureContractTest.php:146-157 — `$manifest = File::get(closureRepoPath('.sprint/current.yml')); ... expect($manifest)->toContain('LEGACY-RME-PROGRAM-CLOSURE-1');` with the comment 'every sprint after closure has to name the closure it inherits'. app/Support/Devflow/SprintManifestValidator.php:148-155 — 'schema_change=false but a migration file changed', 'frontend_change=false but JS/CSS/Vite/build files changed', 'security_impact=false but a policy/middleware/permission file changed'. Current manifest carries `inherits_closure: LEGACY-RME-PROGRAM-CLOSURE-1` at .sprint/current.yml:41.

**G6 [high] The feature-flag decision specifies three metadata keys; the flag registry contract requires ten per flag.**

- WHY: `FeatureFlagFoundationTest` iterates every flag and asserts the presence of `name, description, default, env_key, owner, risk_level, rollout_status, dependencies, rollback_action, enabled`. Adding `doctor.single_active_session` / `doctor.branch_lock` with only `default`, `risk_level` and `env_key` fails it immediately — and this suite is in the critical filter via the `FeatureFlag` token. `rollback_action` in particular is a real design obligation here (what happens to live leases when the flag is turned off mid-shift?), and the synthesis never asks the question.
- EVIDENCE: tests/Feature/Foundation/FeatureFlagFoundationTest.php:15-24 — `foreach (['name','description','default','env_key','owner','risk_level','rollout_status','dependencies','rollback_action','enabled'] as $field)`. The shipped sibling flags carry all of them, incl. a multi-sentence `rollback_action`, at config/feature_flags.php:397-408 and :410-421.

**G7 [high] A doctor-facing cardinality-1 constraint and a doctor-facing idle-TTL reclamation already exist in this codebase, and the contract declares both ABSENT because it searched for the wrong words ('lease', 'concurrent', 'single_session').**

- WHY: CURRENT_DOCTOR_SESSION_TRACKING says tracking is 'ABSENT' and insertion point (d) says 'There is NO throttled-write precedent to copy', reconciling the TTL only against `SESSION_LIFETIME=120` and `expire_on_close=false`. But `UserOnlineContextService` already enforces one-doctor-per-room by a read-then-write with NO row lock and NO unique index — the exact raceable shape the new lease must not copy, and a ready-made worked example of why the DB must own the invariant. It also already expires a doctor lazily at 30 minutes and flips them inactive on read. That 30-minute TTL is a THIRD clock the lease TTL must be reconciled against, and it interacts: an idle doctor's online context goes inactive at 30 min, which fails BranchContext link 1 closed, while their session and any lease would still be alive at 120.
- EVIDENCE: app/Modules/RmeOnlineContext/Services/UserOnlineContextService.php:522-532 `assertRoomNotOccupiedByOtherDoctor()` — `$occupied = $this->activeOnlineDoctorContextsInRoom(...)->contains(...)` then throw, no lock, no index; :18 `public const INACTIVITY_MINUTES = 30;`; :602-609 `isExpired()`; :611 `markExpiredInactive()`; :92-93 and :428-429 and :542-543 the lazy read-triggered reclaim sites.

**G8 [high] `DailyBranchContextBypassTest` — a 19-case bypass suite that is the template for this sprint and pins the approval-authority decision — is never named, while the synthesis leaves approval authority as an open blocking unknown.**

- WHY: The blocking unknown asks whether approval should be a new permission granted to Supervisor RME or a widened role Gate, and says 'one of the two must be chosen by the owner'. One of the two options is already closed by a critical-gated test: :347 'denies the approve gate itself to every non-super-admin role'. Widening `branch-change-request.approve` breaks it, and :364 'keeps the approver menu out of a non-super-admin sidebar' breaks with it. The suite also contains the closest existing analogue of requirement 1 (:83 'refuses a second session trying a different branch') and of the fail-closed rule the synthesis wants to copy (:152).
- EVIDENCE: tests/Feature/AccessControl/DailyBranchContextBypassTest.php — cases at :41, :61, :83, :106, :126, :152, :216, :242, :261, :299, :323, :347, :364, :375, :418, :430. Gate under test at app/Providers/RepositoryServiceProvider.php:593 with its comment 'Deliberately a role check and not a new permission'. Selected by the `DailyBranchContext` token at .github/workflows/foundation-evidence-gates.yml:422.

**G9 [high] The CI mandatory-suite reconciliation is AND, not OR — declaring a new suite in `config/ci_runner.php` WITHOUT also adding a matching token to every critical filter FAILS the gate rather than satisfying it.**

- WHY: The synthesis tells the implementer new test files 'must live in tests/Feature/DoctorDevice/ OR be declared in config/ci_runner.php:172'. Taking the second branch alone actively reddens CI: the scanner emits an issue for a declared suite that no token selects, and it checks EVERY critical variant (github-hosted and self-hosted), so a token added to one workflow job and not the other still fails. This matters because the sprint's own proof — that the partial unique index refuses a second live lease on PostgreSQL — only executes if the suite is selected.
- EVIDENCE: app/Support/Cicd/SelfHostedRunnerScanner.php:481-500 — `foreach ($criticalFilters as $index => $filter) { ... if ($hit === null) { $issues[] = "critical gate filter #{$index} does not select mandatory suite '{$path}'"; } }`, and :477-479 for the stale-registry issue. config/ci_runner.php:157-171 states the same: 'A declared file that no token selects, or that no longer exists, FAILS the gate.'

**G10 [medium] Three public `BranchContext` methods resolve a branch with no reference to the authenticated user at all, and no reader covered them. A lock added only as a first link in `forUser()` is silently absent from them.**

- WHY: `rmeBranchId()`, `requireRmeBranchId()` and `inventoryBranchId()` bypass the whole `forUser()` chain and answer straight from the branch repository (MAIN first, else the first active RME/inventory branch). They are the methods an implementer would instinctively reach for when writing new RME-scoped code ('the RME-aware branch resolver'), and they would hand back a branch the locked doctor is forbidden to work in. They currently have zero callers in `app/`, which is precisely why nobody looked — and why a new caller added by this sprint would go unnoticed.
- EVIDENCE: app/Modules/Branch/Services/BranchContext.php:125-133 `rmeBranchId()`, :136-145 `requireRmeBranchId()`, :152-166 `inventoryBranchId()` — none takes or reads a `User`; `grep -rn 'rmeBranchId()\|requireRmeBranchId()' app/` returns only the definitions inside BranchContext itself.

**G11 [medium] The plan leaves lock rows absent for unassigned doctors (correctly), but never states the consequence: the write-side assertion in (f-ter) must no-op when no lock exists, or every doctor without an owner-assigned lock loses the ability to go online at all.**

- WHY: blocking_unknowns #2 says 'the migration must leave the lock row absent (not guessed)'. Insertion point (f-ter) then says to 'assert the submitted branch equals the doctor's locked branch, throwing the same shape of ValidationException'. An implementer following the codebase's own fail-closed doctrine (which the synthesis quotes approvingly for (f)) will fail closed on a missing lock and lock out the entire fleet on deploy day. The two rules point in opposite directions and the contradiction is not called out in top_traps. The corollary — that requirement 3 ships inert for every doctor until the owner enters data, so it cannot be demonstrated in production by the sprint itself — is also unstated.
- EVIDENCE: Synthesis blocking_unknowns #2 vs insertion point (f-ter); the fail-closed pattern it cites is app/Modules/RmeOnlineContext/Services/UserOnlineContextService.php:236-241, and the pivot assertion it wants to sit after is at :277-281.

**G12 [medium] `RefreshDatabase` is applied globally to every test, and the synthesis never mentions it — so its three concurrency arguments (row locks, savepoints, unique-violation discrimination) are asserted about a test environment whose transaction semantics it did not check.**

- WHY: Every test already runs inside an open transaction, so (a) no cross-connection race is expressible — `lockForUpdate` is untestable and the partial unique index can only be proven by inserting a conflicting row directly; and (b) the 'top-level `DB::transaction` with a nested transaction for the INSERT' shape the contract prescribes is executed at savepoint depth 2/3 in tests versus 1/2 in production, so the PostgreSQL 25P02 recovery path is exercised at a different nesting level than it will run at. The contract asserts the design 'is verifiable in CI' without establishing this.
- EVIDENCE: tests/Pest.php:23 — `->use(RefreshDatabase::class)` applied at the top-level `uses()` chain for the whole suite; noted again at :129 ('so it stays valid after RefreshDatabase rolls back').

**G13 [medium] `NewPasswordController` and `PasswordController` were enumerated by no reader, and the password-reset path rotates `remember_token` — which is the direct evidence bearing on the blocking unknown the synthesis declared unverifiable.**

- WHY: blocking_unknowns #6 says whether `logout()` cycles `users.remember_token` 'cannot be verified from this worktree — vendor/ is absent' and must be settled empirically. But the repo already answers half of it: the reset path explicitly writes a fresh token, showing the app relies on token rotation for invalidation, and `composer.lock` (present, readable without vendor/) pins the framework version so the behaviour is determinable without running anything. Separately, `PasswordController::update` lets an authenticated doctor change their password from inside a session while another session exists, invalidating neither and touching no lease — a state transition requirement 1 has to have an answer for and nobody enumerated.
- EVIDENCE: app/Http/Controllers/Auth/NewPasswordController.php:44-48 — `$user->forceFill(['password' => Hash::make($request->password), 'remember_token' => Str::random(60)])`. app/Http/Controllers/Auth/ directory listing shows nine controllers; reader 1's enumeration covers four. composer.lock is present at the repo root.

**G14 [medium] The doctor branch selector is not a plain Blade dropdown — it is seeded into an Alpine component from `$doctorAllowedBranches`, so narrowing a doctor to one branch has a frontend dependency the plan does not name (and which flips `frontend_change` in the sprint manifest).**

- WHY: Insertion point (f-ter) says 'Changing only the Blade is not enforcement' — correct — but it describes the surface as 'a free `branch_id` dropdown' and stops there. The actual view hands a branch→rooms map into `onlineContextDoctorForm(@js(...))` filtered by `$doctorAllowedBranches`; a locked doctor whose dropdown still offers two branches will also have two branches' rooms in the Alpine payload, so the room selector keeps offering rooms the server will refuse. Whether the JS module itself changes decides the manifest's `frontend_change` flag, which the validator hard-errors on.
- EVIDENCE: resources/views/rme/online-context/select.blade.php:41 — `x-data="onlineContextDoctorForm(@js(collect($roomsByBranch->all())->only($doctorAllowedBranches->pluck('id')->all())...))"`, with the branch `<select name="branch_id" x-model="branchId" @change="syncRooms()">` at :48-53. Validator rule at app/Support/Devflow/SprintManifestValidator.php:151-152.

**G15 [medium] Middleware ordering: `TouchOnlineContextLastSeen` runs before any lease check on every authenticated request, so a session that is about to be evicted still refreshes the doctor's presence row — and thereby keeps their clinic room marked occupied against the other doctor.**

- WHY: The synthesis picks the append position correctly (after `EnsureDoctorDeviceSession`) and correctly flags the `EnsureRmeOnlineContext` redirect, but never looks at the middleware that runs FIRST. `TouchOnlineContextLastSeen` does an unconditional write per authenticated request; appending the lease check last means the evicting request has already committed that write. The practical effect of a force-logout is then a ghost presence that keeps `assertRoomNotOccupiedByOtherDoctor` failing for the doctor who is supposed to take over — for up to the 30-minute inactivity window.
- EVIDENCE: bootstrap/app.php:59-70 — `$middleware->web(append: [TouchOnlineContextLastSeen::class, EnsureRmeOnlineContext::class, EnsureDoctorDeviceSession::class])`; the write at app/Modules/RmeOnlineContext/Middleware/TouchOnlineContextLastSeen.php:19-28 → UserOnlineContextService.php:413 `$context->update(['last_seen_at' => now()])`; the occupancy check at UserOnlineContextService.php:522-532.

**G16 [low] The 'no throttled-write precedent' argument is built on a keyword search narrow enough to miss the codebase's actual distributed-lock and TTL-state precedents.**

- WHY: Insertion point (d)'s risk note says 'app/ contains no `Cache::add(` or `Cache::remember(` at all' and concludes there is no pattern to copy for renewal throttling. The literal grep is true and the conclusion is not: there is a working `Cache::lock()` single-flight precedent guarding exactly this 'two requests must not both do this' shape, and a TTL-keyed state machine using `Cache::put` with expiry. Either is a better model for lease renewal than an unthrottled per-request UPDATE. Note also that `CACHE_STORE=array` in tests and in both CI jobs, so any cache-based throttle is per-request under test — a fact the contract would need to state if it adopted one.
- EVIDENCE: app/Modules/Satusehat/Gateways/OAuthClientCredentialsSatusehatTokenProvider.php:38 `$lock = Cache::lock($this->lockKey(), ...)` and :134 `Cache::put($this->cacheKey(), ...)`; app/Modules/Satusehat/Support/SatusehatCircuitBreaker.php:61-64 `Cache::put(... now()->addSeconds($cooldown * 2))`. `CACHE_STORE: array` at phpunit.xml:24 and .github/workflows/foundation-evidence-gates.yml:320, :541.

## H. Claims the critique found WRONG

1. CLAIM: "New test files must live in tests/Feature/DoctorDevice/ or be declared in config/ci_runner.php:172 (critical_gate_mandatory_suites)." — presented as two alternatives.
   CORRECTION: Declaring a suite in the registry WITHOUT a matching token in every critical filter is a gate FAILURE, not an alternative satisfaction. The scanner loops over every critical filter and appends an issue when no token selects the declared identity; the registry is a reconciliation, so the two requirements are AND, not OR. (The synthesis's related claim that the `DoctorDevice` token matches the DIRECTORY segment is correct and I verified it — the identity is path-derived including directories, which incidentally contradicts config/ci_runner.php's own comment claiming `|DoctorDevice` cannot select DoctorPwaWebAuthnTest.)
   CITATION: app/Support/Cicd/SelfHostedRunnerScanner.php:481-500 (`$issues[] = "critical gate filter #{$index} does not select mandatory suite '{$path}'"`) and :520-538 (`testIdentity()` building `Tests\Feature\DoctorDeviceWebAuthn\DoctorPwaWebAuthnTest`); config/ci_runner.php:157-171.

2. CLAIM: "SESSION_DRIVER is `array` in phpunit.xml:29" (repeated three times, incl. in CURRENT_SESSION_DRIVER and top_traps).
   CORRECTION: phpunit.xml:29 is `QUEUE_CONNECTION=sync`; `SESSION_DRIVER=array` is line 30. The substance of the claim is right (array driver in phpunit and in both CI jobs, so the sessions table is empty in every test run) — only the line number is wrong.
   CITATION: phpunit.xml:29-30; .github/workflows/foundation-evidence-gates.yml:323 and :545 both do carry `SESSION_DRIVER: array` as claimed.

3. CLAIM: The partial-index rationale 'PostgreSQL and SQLite both support partial indexes, so the invariant is identical on the production driver and in the test suite' is cited at database/migrations/2026_08_29_100002_create_trx_branch_change_requests_table.php:87-89.
   CORRECTION: That sentence is in the file's docblock at line 26. Lines 87-94 are the index itself (`// At most one PENDING request per requester per clinical day.` then the `DB::statement('CREATE UNIQUE INDEX trx_branch_change_req_pending_uq ... WHERE status = \'pending\'')`). The claim is true and the precedent is real and unguarded — the citation just points at the wrong lines, which matters because a reviewer checking :87-89 will not find the sentence and may discard the whole argument.
   CITATION: database/migrations/2026_08_29_100002_create_trx_branch_change_requests_table.php:26 (rationale) vs :88-93 (the unguarded partial index).

4. CLAIM: "app/ contains no `Cache::add(` or `Cache::remember(` at all" — used to conclude "There is NO throttled-write precedent to copy."
   CORRECTION: The literal statement holds, but the inference does not: `Cache::lock()` (single-flight, exactly the concurrency shape a lease renewal needs) and TTL-bounded `Cache::put()` state are both live in app/. The contract should either adopt one of those precedents or say why it rejects them, rather than asserting no precedent exists.
   CITATION: app/Modules/Satusehat/Gateways/OAuthClientCredentialsSatusehatTokenProvider.php:38, :134; app/Modules/Satusehat/Support/SatusehatCircuitBreaker.php:61-64; app/Support/Health/HealthCheckService.php:170.


## I. OWNER DECISIONS — CANONICAL, OVERRIDE ANY CONFLICTING ANALYSIS ABOVE

### O1. Branch lock starts UNSET for every doctor. No backfill, ever.
Production has no authoritative per-doctor branch: all 15 doctors have `users.branch_id`
NULL and `mst_doctors.branch_id` NULL, and every one is pivoted to all four RME branches.
10 of 15 have no branch signal at all. The doctor code disagrees with reality for at least
one live clinician (drg Fiitri is DOC-TLK002 but her only online context and her authorised
tablet are both ATG3).

- Every doctor starts `locked_branch = UNSET`.
- NO backfill migration. Never infer branch from doctor code, device branch, last login,
  room, or history.
- A recent branch MAY be DISPLAYED as a non-authoritative suggestion. It must NEVER be
  auto-written as the locked branch.
- While UNSET: preserve current branch-selection and login behaviour EXACTLY. Do not lock
  the doctor out. This is the compatibility state, not missing data.
- `AMBIGUOUS_DOCTOR_COUNT` may remain > 0, but `DEPLOYMENT_BLOCKED_BY_AMBIGUITY=NO`.

Two explicit approval workflows set it, both approvable ONLY by Super Admin or Supervisor RME:
1. INITIAL BRANCH ASSIGNMENT: UNSET -> approved locked branch.
2. BRANCH TRANSFER: locked branch A -> approved locked branch B.

After the first approved assignment the branch is server-authoritative: login from any
trusted tablet preserves it, the doctor cannot self-switch, and any later change requires
the transfer workflow, whose approval invalidates the active session and forces fresh login.

GO must prove all five: UNSET doctors preserve legacy behaviour; LOCKED doctors cannot
self-switch; initial assignment works; transfer works; no doctor is ever assigned a branch
without explicit approval.

### O2. The lock DOES narrow operational lists — but only once SET.
- If UNSET: preserve current legacy operational-list behaviour. Do not narrow. Do not infer.
- If SET: Daftar Kunjungan, patient queue, room worklist and other branch-operational
  doctor lists must resolve through the LOCKED doctor branch.
- The physical tablet's branch must NEVER determine list scope.

Mandatory regression, all three cases:
- UNSET -> legacy visibility preserved.
- LOCKED SPN4 -> SPN4 operational list only.
- Same doctor logging in on the LDK2 tablet -> STILL SPN4 list only.

Note: `RmeWorkingBranchScope` currently excludes Doctor deliberately. That exclusion is now
conditional rather than absolute: narrow for LOCKED doctors, preserve for UNSET doctors.

### O3. The lock does NOT narrow legacy archive reads.
`DoctorClinicalBranchResolver` stays untouched. A doctor at one branch must still be able to
read a patient's archive from another branch. Its docblock rationale stands.

### O4. The bulk device-authorization tool SHIPS in this sprint.
Canonical requirement: every eligible doctor must be able to authenticate from every ACTIVE,
cryptographically_verified, approved clinic tablet. `DoctorDeviceAuthorization` is PRESERVED
as the explicit audit boundary — MODEL A, not fleet-wide implicit trust.

Idempotent bulk authorization command/workflow with:
- mandatory dry-run by DEFAULT;
- exact doctor list, exact eligible device list, exact proposed row additions;
- zero duplicate rows;
- ACTIVE doctors only;
- ACTIVE + cryptographically_verified clinic devices only;
- terminally revoked devices excluded; pending/inactive devices excluded;
- NO WebAuthn credential creation;
- NO branch-lock mutation; NO pilot/global scope mutation; NO feature-flag mutation;
- transactional apply; post-write reconciliation; audit trail.

Before APPLY it MUST STOP and ask operator approval showing the exact row delta:
    DOCTORS=15
    TRUSTED_DEVICES=3
    EXISTING_AUTHORIZATIONS=<actual>
    PROPOSED_NEW_AUTHORIZATIONS=<actual>
    FINAL_EXPECTED_AUTHORIZATIONS=<actual>
Only after explicit approval may APPLY run. After APPLY, verify every eligible doctor x every
eligible trusted clinic device has exactly ONE active authorization.

Do NOT revoke old authorization rows as part of synchronisation unless there is an
independent, explicit lifecycle reason.

### O5. Sprint boundary (unchanged)
Global doctor WebAuthn enforcement stays OFF. Do not perform
DOCTOR-PWA-GLOBAL-ACTIVATION-1 and do not create doctor-pwa-global-rollout-readiness-1-go.
The bounded pilot cohort [9,15,18] is preserved.

## J. Verified production facts (read-only, 2026-09-10)
- 15 doctors, 3 READY, 12 NOT_READY, all 12 blocked by exactly one reason: no_device_authorization.
- Devices: 5 total, 3 ACTIVE (id 3 = SPN4, id 5 = LDK2, id 6 = ATG3), 2 revoked (id 1, 4 at SPN4).
  All five are identity_state=cryptographically_verified, enrollment_status=verified.
- Live WebAuthn credentials: 3 (cred 2 on device 3, cred 3 on device 5, cred 5 on device 6),
  all user_verified=t, backup_eligible=f, backup_state=f, device_bound_verdict=device_bound.
- `trx_doctor_device_webauthn_credentials` binds to `doctor_device_id` ONLY. There is NO doctor
  column. A credential proves the DEVICE, not the clinician. This is why all-tablet access needs
  authorization rows and no new credentials.
- Existing authorizations: 5 rows, 3 active (doctor 21 -> device 3, doctor 17 -> device 5,
  doctor 20 -> device 6), 1 revoked (21 -> 1), 1 rejected (21 -> 4).
- `mst_doctor_device_authorizations` has NO deleted_at, and UNIQUE(doctor_id, doctor_device_id)
  named mst_dd_authorizations_pair_unique. A revoked or rejected row permanently occupies the slot.
- `sessions` table columns: id, user_id, ip_address, user_agent, payload, last_activity, with
  sessions_user_id_index on user_id.
- Branches: 1=TLK1 Telkomas, 2=LDK2 Landak, 3=ATG3 Antang, 4=MAIN (is_rme_enabled=false),
  5=SPN4 Sunu, 6=SYN4A (inactive synthetic).
- Doctor online contexts exist for users 9(LDK2,online), 12(TLK1,inactive), 14(TLK1,offline),
  15(ATG3,online), 18(SPN4,online). Users 19-28 have NO online context row at all.
- `trx_daily_branch_contexts` is UNIQUE(user_id, clinical_date) — a per-DAY store, wrong
  cardinality for a permanent lock.
- Production PostgreSQL is 16.15.

## K. EMPIRICAL PROBE — partial unique index on the engine local tests use (run 2026-09-10)

Executed against sqlite 3.46.1, the engine phpunit.xml selects. Script: scratchpad/probe_partial_index.php.

    CREATE UNIQUE INDEX leases_active_uq ON leases (user_id) WHERE released_at IS NULL

Results, all observed not assumed:
- sqlite ACCEPTS the partial unique index.
- A second row with the same user_id and released_at NULL is REJECTED, SQLSTATE=23000, driver code 19
  (SQLITE_CONSTRAINT), message "UNIQUE constraint failed: leases.user_id".
- After setting released_at on the first row, a new active lease for the same user SUCCEEDS and the
  released row is retained, so the audit history survives.
- A different user's active lease is unaffected.
- MULTIPLE released rows for the same user are permitted.
- `ALTER TABLE leases ADD COLUMN branch_id INTEGER NULL REFERENCES branches(id)` did NOT rebuild the
  table and did NOT drop the WHERE clause: the index survived verbatim.

Consequences:
1. The partial-unique-index design is sound on BOTH engines. It is the recommended cardinality-1
   mechanism, and it is genuinely exercised by the local suite, not only by CI PostgreSQL.
2. The received SATUSEHAT-4D trap, that adding a constrained FK column silently flattens a partial
   index, is NOT reproducible at the raw SQL level on this sqlite version. It may still occur via
   Laravel's schema grammar if that grammar rebuilds the table rather than issuing a plain ADD COLUMN.
   The safe rule stands either way: CREATE THE PARTIAL INDEX IN THE SAME MIGRATION AS THE TABLE, and
   assert the index still has its WHERE clause in a test.
3. Unique-violation detection must be portable: sqlite reports SQLSTATE 23000, PostgreSQL reports
   23505. Any catch that discriminates a unique violation must handle both, and must sit OUTSIDE the
   transaction on PostgreSQL because a failed statement aborts the whole transaction there.

## L. WORKTREE STATE (ready for implementation)
- vendor/ installed by a real `composer install` (exit 0). Never symlinked.
- The environment file was written from scratch rather than copied, because copying one is blocked by a deny rule, and the application key was generated into it.
- Laravel Framework 12.61.0.
- Smoke: `php artisan test tests/Feature/Foundation/FeatureFlagFoundationTest.php` -> 12 passed, 359 assertions, 1.26s.
- phpunit.xml <php> block confirmed: APP_ENV=testing, CACHE_STORE=array, DB_CONNECTION=sqlite,
  DB_DATABASE=:memory:, QUEUE_CONNECTION=sync, SESSION_DRIVER=array. NONE carry force="true", so an
  exported CI DB_CONNECTION=pgsql does override them.

## M. EMPIRICAL PROBE — PostgreSQL 16 (production major version), run 2026-09-10

Executed in a disposable `postgres:16` container, removed afterwards. Script: scratchpad/probe_pg16.sql.
Same partial unique index as section K.

Observed, not assumed:
- The partial unique index is ACCEPTED and ENFORCED. A second active lease for the same user fails with
  `ERROR: duplicate key value violates unique constraint "leases_active_uq" / DETAIL: Key (user_id)=(7)
  already exists.`
- Release then re-claim SUCCEEDS and the released row is retained (2 rows for user 7).
- Multiple released rows for the same user are permitted.

**THE TRAP IS REAL AND CONFIRMED.** Catching the unique violation INSIDE the transaction and then
running any further statement fails:

    BEGIN
    INSERT ... -> ok
    INSERT ... -> ERROR: duplicate key value violates unique constraint
    SELECT ...  -> ERROR: current transaction is aborted, commands ignored until end of transaction block

So a claim that inserts, catches the violation, and then SELECTs the incumbent lease to build the
denial message WILL BREAK ON PRODUCTION while appearing to work on sqlite. This is exactly the shape
the critique reported already exists three times in this codebase.

**THE SAVEPOINT RESCUE WORKS.** Wrapping the INSERT in a SAVEPOINT, which is precisely what a nested
Laravel `DB::transaction()` emits, recovers cleanly:

    BEGIN
    INSERT ... -> ok
    SAVEPOINT sp1
    INSERT ... -> ERROR: duplicate key value ...
    ROLLBACK TO SAVEPOINT sp1
    SELECT ... -> "SELECT after ROLLBACK TO SAVEPOINT works"
    COMMIT   -> succeeds, user_11_rows = 1

MANDATED CLAIM SHAPE, now evidence-backed on both engines:
1. Outer `DB::transaction`.
2. The lease INSERT inside a NESTED `DB::transaction` so Laravel emits a SAVEPOINT.
3. Catch the QueryException OUTSIDE the nested transaction, after Laravel has issued ROLLBACK TO
   SAVEPOINT.
4. Only then read the incumbent lease to build the denial, which is now safe on PostgreSQL.
5. Discriminate the unique violation portably: sqlite reports SQLSTATE 23000, PostgreSQL reports 23505.
   A check on either alone is wrong on the other engine.

## N. THE CANONICAL CLAIM TEMPLATE ALREADY EXISTS IN THIS CODEBASE

`DailyBranchContextService::assertSelectable()` (app/Modules/RmeOnlineContext/Services/DailyBranchContextService.php:147-232)
implements EXACTLY the shape the section M probe proves is required, and its own comment records the
same discovery: "Rolling back to a savepoint discards only the failed INSERT and leaves the enclosing
transaction usable. Found by CI on PostgreSQL; a SQLite-only run cannot see it."

Copy this shape for the lease claim:

    DB::transaction(function () {                    // outer
        ...
        try {
            DB::transaction(fn () => $repo->create([...]));   // NESTED -> emits SAVEPOINT
            return;
        } catch (QueryException $e) {                 // caught OUTSIDE the savepoint
            if (! $this->isUniqueViolation($e)) { throw $e; }
            $incumbent = $repo->lockForUser($id);     // safe: transaction still usable
            if ($incumbent === null) { throw $e; }
        }
        ... judge against the incumbent, then refuse with a ValidationException ...
    });

Portable detector, copied verbatim from :221-232 (the codebase duplicates this per service rather than
sharing it; follow that convention):

    private function isUniqueViolation(QueryException $exception): bool
    {
        if ($exception->getCode() === '23505') { return true; }        // PostgreSQL
        $message = strtolower($exception->getMessage());
        return str_contains($message, 'unique constraint')
            || str_contains($message, 'unique violation')
            || str_contains($message, 'duplicate key');
    }

A second correct instance is BranchChangeApprovalService.php:121 and :404-406.

The refusal message style to copy is `lockedMessage()` at :206-217: Indonesian, actionable, and it names
the branch the operator is already committed to, which is their own working context and not a leak.

### PRE-EXISTING DEFECT FOUND WHILE VERIFYING (out of scope, do NOT fix in this sprint)
Three sites use the BROKEN shape — catching a QueryException inside `DB::transaction` and then running
further queries, which the section M probe proves fails on PostgreSQL with 25P02 while passing on sqlite:
- app/Modules/RME/Services/PatientDoctorAssignmentService.php:48 (inside DB::transaction at :25), falls
  back to a SELECT that would throw 25P02 when the race it exists to handle actually happens.
- app/Modules/RME/Services/PatientDoctorAssignmentService.php:172 (inside DB::transaction at :137), same
  shape with firstOrFail().
- app/Modules/MedicalRecord/Services/DiagnosisRolloutService.php:127 (inside DB::transaction at :111),
  same shape and then also issues an UPDATE.
These only misbehave when their race actually fires, which is why the suite is green. Report to the
owner; do not widen this sprint to fix them.

## O. OWNER DECISION O6 — TIME-BOXED COVER THAT AUTO-REVERTS (2026-09-10)

A permanent lock would stop a covering doctor entirely, and production is configured for cross-branch
practice: all 15 doctors are pivoted to all four RME branches and 15 doctors share 3 tablets. The owner
chose a time-boxed cover assignment that auto-reverts, over "transfer out and back" and over deferring.

Design constraints this imposes:
- A doctor's lock has a HOME branch and, optionally, a COVER branch with an expiry.
- Effective branch = cover while it is unexpired, otherwise home.
- **Resolve the expiry LAZILY on every read, not by a scheduled job.** A job that flips the branch is a
  silent mid-shift move and depends on a scheduler whose status is not guaranteed. Lazy evaluation makes
  the revert deterministic, correct without infrastructure, and impossible to miss. A scheduled command
  may tidy and audit expired rows, but correctness must NEVER depend on it.
- The expiry is the END OF THE CLINICAL DAY in WITA via ClinicalClock, matching the existing daily branch
  context semantics, so a revert lands at a day boundary rather than mid-consultation.
- A revert does NOT evict the session. Only an approved permanent transfer does that. The branch simply
  resolves back to home on the next request.
- Cover is granted through the SAME approval authority as a transfer: Super Admin or Supervisor RME only.
- The lock is therefore no longer strictly permanent, and the tests must pin: cover active -> cover branch;
  cover expired -> home branch, with no job run; cover never outlives the clinical day; a doctor cannot
  self-grant or self-extend cover.

## P. RULINGS ON THE RED-TEAM FINDINGS (25 problems across 3 lenses)

These are decided. The revised plan MUST implement them.

### CRITICAL
1. **Visit creation must be covered.** A LOCKED doctor could POST rme.visits.store with a forged branch_id
   and create a visit, and in new-patient mode a patient, at any active RME branch. Narrowing the read
   scope alone does not stop a write. Assert the lock at the WRITE chokepoint
   `ClinicVisitService::resolveBranchId()` (app/Modules/ClinicVisit/Services/ClinicVisitService.php:395-421),
   where both branch_id and new_patient.branch_id converge and a ValidationException is already thrown.
2. **The lock must NEVER reach per-record authorization.** Owner decision O2 says LISTS: Daftar Kunjungan,
   patient queue, room worklist and other branch-operational doctor lists. It does NOT say per-record
   access. Hooking ClinicVisitPolicy would stop a doctor opening the Rekam Medis of a patient standing in
   front of them at their OWN locked branch whenever that patient's earliest RME visit happened elsewhere,
   because the patient-centric RM workspace anchors on the earliest visit. That is a patient-safety defect.
   Hook the LIST scope only and leave per-record authorization on the existing clinical-relationship gates.

### HIGH
3. **Never evict a live session, and never lock a doctor out after a normal handover.** Do NOT reclaim a
   lease on an idle timer, which is eviction by the back door and inverts requirement 1. Record the
   session id on the lease beside the token hash. In claimOrDeny, an incumbent whose `sessions` row no
   longer exists is DEAD and is reclaimed immediately; an incumbent whose session row is alive is DENIED
   however idle it is. This preserves "second login denied, first never evicted" strictly, and fixes the
   routine "finished on the ward tablet, walked to the office PC" case that would otherwise lock a doctor
   out for 30 minutes with a message telling them to do something impossible.
4. **The deny path must not cycle the shared remember token.** `DoctorDeviceSessionService::invalidate()`
   calls `Auth::guard('web')->logout()`, which cycles `users.remember_token`, and that token is SHARED
   across sessions. Refusing login #2 would therefore partially break session #1, which is exactly what
   requirement 1 forbids. On the deny path use `logoutCurrentDevice()` plus session invalidate and token
   regenerate. Pin it with a test asserting the incumbent session still works after a denial.
5. **Free the clinic room on eviction.** Call `UserOnlineContextService::markOffline($user)` in the lease
   middleware before tearing the session down, as the approval service already does for the transfer path,
   otherwise an evicted doctor's room stays occupied and blocks the next doctor. Correct the bootstrap
   ordering comment, which currently claims a benefit the ordering does not deliver.
6. **Refuse a lock for an unlinked doctor record.** A Doctor-role account with no `mst_doctors.user_id`
   link binds nobody. Refuse in request() and approve(), audit it, and pin it.

### MEDIUM
7. **Make the flag dependency real.** Nothing in the codebase reads a flag's `dependencies` array, so the
   declaration is decorative. `DoctorBranchLockResolver::enabled()` must require BOTH flags, so branch
   lock cannot be armed alone with session invalidation silently absent.
8. **Emit lease events under their own audit action**, not the device action
   `DOCTOR_SESSION_DEVICE_INVALIDATED`. A lease eviction is not a device invalidation.
9. **A locked branch that loses is_active or is_rme_enabled must degrade to a handled state, never a 500.**
   `BranchContext::requireId()` would throw a RuntimeException on every write path. Treat an unusable lock
   as UNSET-with-audited-warning so legacy behaviour resumes, rather than stopping the doctor dead.
10. **Load and re-assert the doctor inside the approve() transaction** under the same row lock, refusing a
    deactivated doctor at decision time, as request() already does.
11. **Show the approver the subject doctor's live presence** and require a second confirmation when they
    are ONLINE, warning that unsaved handwriting RM or odontogram input may be lost.
12. **Complete the RmeWorkingBranchScope consumer enumeration** in the sprint doc: source has at least 12
    consumers, the plan listed 7, and the class docblock calls itself the single canonical answer.
13. **Fix the compile defect**: `claimOrDeny()` audits `$leaseId`, which is never assigned because the
    create happens inside a nested closure whose return value is discarded.
14. **Correct two false manifest rationales**: the validator's frontend pattern does not match .blade.php,
    and the SupervisorRme repin matches no critical filter token. State both honestly.

### LOW
15. Claim the lease in RegisteredUserController::store too, or pin that registration cannot produce a
    Doctor-role account.
16. On the Android ticket path the one-time ticket is consumed before the claim, so a denial burns it.
    Pre-check lease availability before consumption, or document it. Dead today, live when enforcement is on.
17. Give the approver surface a lease-release action behind the same permission, so an on-site Supervisor
    RME can clear a colleague's stuck lease without an SSH session.
18. Number the cursor rule 153, not 93, which collides with two existing files.

## Q. OWNER DECISION O6 — CANONICAL COVER SEMANTICS. **SUPERSEDES SECTION O.**

Section O got two things WRONG and the owner corrected both:
- WRONG: "a revert does NOT evict the session". CORRECT: cover activation AND cover expiry BOTH
  invalidate the doctor's active session. Branch context must never switch silently inside an
  already-authenticated session.
- WRONG: "expiry is the end of the clinical day". CORRECT: cover carries explicit starts_at and ends_at.

What section O got RIGHT and the owner reaffirmed: authorization must be derivable from current
timestamps on EVERY protected request. A scheduler or command may do housekeeping but must never be the
sole security boundary, and an expired cover must never remain effective because cron or a queue worker
was delayed.

### Two distinct concepts, never conflated
1. **HOME_LOCKED_BRANCH** — permanent, authoritative. Unchanged by any cover. Changing it requires the
   permanent branch-transfer workflow.
2. **TEMPORARY_BRANCH_COVER** — explicit approved temporary operational authority, with
   target_branch_id, starts_at, ends_at, reason, approval status, approved_by, approved_at. Expires
   automatically.

### Effective branch
    if an APPROVED cover is currently active:  EFFECTIVE_CLINICAL_BRANCH = cover.target_branch_id
    else:                                      EFFECTIVE_CLINICAL_BRANCH = HOME_LOCKED_BRANCH

Operational lists AND WRITES follow EFFECTIVE_CLINICAL_BRANCH. Historical RME and odontogram archive
reads stay cross-branch per existing archive policy (owner decision O3 stands).

### Rules
- A doctor must NEVER self-create or self-approve cover. Approvers: Super Admin or Supervisor RME only.
- Cover activation MUST invalidate the doctor's active session. Cover expiry MUST also invalidate it.
- Cover expiry MUST NOT change HOME_LOCKED_BRANCH, and MUST NOT revoke device, DoctorDeviceAuthorization
  or WebAuthn credential.
- After cover start, a fresh login lands on the cover branch. After expiry, a fresh login lands on home.
- Overlapping active covers for the same doctor are forbidden.
- Periods are deterministic and timezone-safe in the clinic's canonical timezone (ClinicalClock / WITA).
- Statuses: PENDING, APPROVED, REJECTED, CANCELLED, ACTIVE, EXPIRED — but ACTIVE and EXPIRED are DERIVED
  from approval plus timestamps. Do not persist redundant state that a delayed job would have to maintain.
- A permanent transfer MUST NOT create ambiguous effective-branch state while an active cover exists.
  Rule to implement and test: block permanent transfer APPROVAL while a cover is active, until it ends or
  is cancelled, unless an atomic alternative is proven safer.
- Cover does NOT relax single-session enforcement. MAX_ACTIVE_DOCTOR_SESSIONS stays 1.
- GLOBAL_ENFORCEMENT_ACTIVE stays false.

### THE MECHANISM that satisfies "invalidate on expiry" without a scheduler
Bind the session to the effective branch AT CLAIM TIME: record effective_branch_id on the lease when the
lease is claimed. On every protected request the middleware recomputes EFFECTIVE_CLINICAL_BRANCH purely
from current timestamps and compares it with the value the session was established under. If they differ
— because a cover just started, just expired, or a transfer was approved — the session is invalidated and
a fresh login is required. This makes activation, expiry and transfer all behave identically, needs no
cron for correctness, and structurally prevents a silent mid-session branch switch.

### Mandatory concurrency tests
- cover approval versus doctor login
- cover expiry versus a protected request
- simultaneous overlapping approvals for the same doctor
- permanent transfer while a temporary cover exists

## R. FRAMEWORK FACTS VERIFIED IN VENDOR (Laravel 12.61)
File: vendor/laravel/framework/src/Illuminate/Auth/SessionGuard.php

- `fireLoginEvent()` is called at :573 from the ordinary `login()`, AND at :202 on the REMEMBER-ME
  RECALLER path (`userFromRecaller()` at :197, `updateSession()` at :200, then
  `fireLoginEvent($this->user, true)` at :202). So `Illuminate\Auth\Events\Login` fires for a session
  resurrected by a recaller cookie, which is the path a login-controller-only claim would miss entirely.
- `setUser()` (:996-1005) fires `fireAuthenticatedEvent`, NOT `Login`. `actingAs` goes through `setUser`.
  Therefore a listener on `Login` covers 100% of production authentication entries and 0% of the ~3564
  `actingAs` test call sites. This is what makes the claim implementable without breaking the suite.
- `logout()` (:650) calls `cycleRememberToken($user)` (:657). `logoutCurrentDevice()` (:680) does NOT.
  `users.remember_token` is a single shared column, so cycling it invalidates the recaller for EVERY
  session of that account. The deny path must therefore use `logoutCurrentDevice()`, never `logout()`,
  or refusing login number two would partially evict session number one, which requirement 1 forbids.
  `DoctorDeviceSessionService::invalidate()` calls `Auth::guard('web')->logout()`, so it MUST NOT be
  reused on the deny path.

CONSEQUENCE: claim the lease in a listener on `Illuminate\Auth\Events\Login`, not in login controllers.
