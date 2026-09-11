# SLICE: Single-active-session lease engine (DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1, requirement 1 + the eviction primitive requirements 3/4 consume)

## Decisions
- CLAIM SITE = a listener on Illuminate\Auth\Events\Login, registered explicitly via Event::listen in AppServiceProvider::boot(). Verified in vendor: fireLoginEvent() is called from login() at SessionGuard.php:573 AND from the remember-me recaller path at :202 (userFromRecaller :197 -> updateSession :200 -> fireLoginEvent :202). setUser() at :996-1005 fires fireAuthenticatedEvent instead, and actingAs goes through setUser. So a Login listener covers 100% of production authentication entries — password (via Auth::attempt in LoginRequest.php:45), Android ticket redemption (DoctorDeviceSessionService.php:113), browser WebAuthn (DoctorDeviceWebAuthnLoginService.php:348), registration (RegisteredUserController.php:48, ruling P15 satisfied for free) and the recaller cookie that passes through no controller at all — and 0% of the ~3554 actingAs() call sites. Explicit registration is mandatory: bootstrap/app.php never calls ->withEvents() (the only thing that registers EventServiceProvider and enables discovery, ApplicationBuilder.php:98-114), bootstrap/providers.php lists three providers, and app/Listeners is ABSENT — an auto-discovered listener would silently never run.
- HOW THE LISTENER DENIES A LOGIN THAT ALREADY HAPPENED. At fireLoginEvent time, updateSession() (:559) has already put the auth id into the session and called session->regenerate(true) (:592), which destroyed the previous sessions row immediately (Store.php:634 -> DatabaseSessionHandler::destroy :268) and minted a new id (:639); a recaller cookie may already be QUEUED (:566-568); and setUser() (:575) has NOT run. Nothing is persisted — StartSession writes the sessions row and AddQueuedCookiesToResponse attaches the cookie, both on the response. So the denial tears down before the response exists: (a) Auth::guard('web')->logoutCurrentDevice() (:680) -> clearUserDataFromStorage() (:704-715) removes the auth key (:706), unqueues the recaller (:708) and queues a forget-cookie when one arrived (:710-713); (b) $request->session()->invalidate() (Store.php:605-610) flushes ALL session data, which is what kills the just-minted lease token, and migrates the id again; (c) regenerateToken() (Store.php:755) so the redirect-back has a usable CSRF token; (d) throw ValidationException. Because it throws, login() never reaches setUser() at :575, so the guard stays loggedOut for the rest of the request. Returning false CANNOT deny — Dispatcher only halts propagation and fireLoginEvent (:820-823) discards the return — and Dispatcher::invokeListeners() has no try/catch around $listener($event, $payload) (Dispatcher.php:318), so the throw propagates.
- DENY PATH USES logoutCurrentDevice(), NEVER logout(). SessionGuard::logout() at :650 calls cycleRememberToken($user) at :657 (-> :723-728, writes a fresh Str::random(60) into users.remember_token). That column is a single shared column on the users row (create_users_table.php:20), so cycling it invalidates the recaller for EVERY session of the account — refusing login #2 would partially evict session #1, which requirement 1 forbids. logoutCurrentDevice() at :680 is byte-identical except it omits that call. This is also why DoctorDeviceSessionService::invalidate() (:188-207) is NOT reusable here: it calls logout() at :201. The regression is pinned by a test that snapshots remember_token across a denial.
- THE CLAIM TRANSACTION IS DailyBranchContextService::assertSelectable() (DailyBranchContextService.php:137-204) COPIED, WITH ONE DELIBERATE DIVERGENCE. Same shape: outer DB::transaction; lockActiveForUser() first; the INSERT inside a NESTED DB::transaction so Laravel emits a SAVEPOINT (createTransaction -> createSavepoint at ManagesTransactions.php:158-159); the QueryException caught OUTSIDE that nested transaction, after Laravel has issued ROLLBACK TO SAVEPOINT; only then re-read the incumbent under lock. Section M proved on PostgreSQL 16 that catching inside and then SELECTing returns 25P02 while sqlite happily continues. isUniqueViolation() is copied verbatim from :221-233 (23505 for PostgreSQL, message substrings for sqlite's 23000) — the codebase duplicates this per service rather than sharing it and this follows that convention. THE DIVERGENCE: assertSelectable() throws inside the transaction because it has nothing to persist on refusal; this service returns a VERDICT and throws after commit, because the denial audit row must survive and because the session teardown issues a DELETE on `sessions` through the same connection (Store.php:609 -> DatabaseSessionHandler::destroy :268) that a rollback would undo. Both nested creates assign the return value — DB::transaction returns $callbackResult (ManagesTransactions.php:73) — which fixes the compile defect ruling P13 names and which the exemplar at :165-172 actually has.
- CARDINALITY 1 IS AN UNGUARDED PARTIAL UNIQUE INDEX, CREATED IN THE TABLE'S OWN MIGRATION: CREATE UNIQUE INDEX trx_doctor_session_leases_active_uq ON trx_doctor_session_leases (user_id) WHERE released_at IS NULL. Section K proved sqlite 3.46.1 accepts and enforces it, permits many released rows for one user, and (at raw SQL level) does not drop the predicate on ADD COLUMN; section M proved the same on PostgreSQL 16. Precedent: database/migrations/2026_08_29_100002_create_trx_branch_change_requests_table.php:88-93 (unguarded, with the both-engines rationale at :26). Same-migration creation is non-negotiable (BRIEF F6): a later constrained() column add can rebuild the table on sqlite and flatten it to a plain UNIQUE(user_id), which for a lease is not a weaker constraint but a permanently unreleasable one — a doctor could never log in twice, and only locally. SchemaFacts::hasUniqueIndexOn() (tests/Support/Database/SchemaFacts.php:55-64, :93-102) cannot see the predicate, so the guarantee is pinned behaviourally: release then re-claim, and assert exactly one unreleased row survives.
- THE LEASE IS BOUND TO THE SESSION BY A RANDOM TOKEN IN SESSION DATA, STORED ONLY AS ITS sha256 — bin2hex(random_bytes(32)) + hash('sha256', ...), copied from DoctorDeviceWebAuthnChallengeService.php:162-183 whose rationale at :133-160 is the same problem: the session id rotates for benign reasons and binding to it produces a doctor who cannot reach their patients. Session DATA survives regenerate() (Store::migrate keeps $attributes, :631-642) and dies with invalidate()/flush() (:595, :605-610), which is exactly the distinction wanted. The session_id COLUMN on the lease is a HINT for the operator surface, refreshed by the middleware when it drifts; it is never the identity. Key on users.id with doctor_id as a nullable audit column only (architect D2): DoctorIdentityResolver::resolveForUser() (app/Modules/Doctor/Services/DoctorIdentityResolver.php:35-40) returns null for an unlinked Doctor account, so keying on doctor_id would silently exempt every unlinked doctor from the rule.
- LIVENESS IS READ FROM THE sessions TABLE BY user_id, NOT BY SESSION ID AND NOT FROM THE SESSION DRIVER. sessions.user_id is written by DatabaseSessionHandler::addUserInformation() (:204-211) and indexed (create_users_table.php:32), and it does not rotate; the id does, three times on the WebAuthn path. The window mirrors DatabaseSessionHandler::expired() (:121-122) — last_activity >= now - config('session.lifetime') — so 'live' means what the driver itself means. The claimant's own current id is excluded defensively even though regenerate(true) has already destroyed its row before the listener runs.
- DEAD-INCUMBENT RECLAIM IS THE ONLY RECLAMATION. An incumbent with no live sessions row is DEAD and is reclaimed immediately inside the claim transaction (release with reason 'dead_incumbent', then a fresh nested INSERT, and a unique violation there means someone else won the race -> DENY). A live incumbent is DENIED however idle, and there is NO TTL and NO idle timer — ruling P3 explicitly reverses proposed insertion point IP5, because reclaiming on idleness is eviction by the back door and inverts requirement 1. Be honest about the operational consequence: a doctor who walks away WITHOUT logging out keeps a live sessions row for up to SESSION_LIFETIME (config/session.php:35, default 120) with expire_on_close=false (:37), and is therefore denied on the office PC until they log out or an approver releases the lease. That is the rule working, not a defect, and it is why ruling P17's operator release path ships with the engine and is named in the denial copy.
- TESTABILITY OF LIVENESS DESPITE SESSION_DRIVER=array. The sessions TABLE is created by database/migrations/0001_01_01_000000_create_users_table.php:30-37 and migrated by RefreshDatabase (tests/Pest.php:23) in EVERY run, regardless of driver — only the DRIVER is array (phpunit.xml:30, and .github/workflows/foundation-evidence-gates.yml:323, :545). So a test builds an incumbent by inserting one row directly (id, user_id, payload, last_activity = now) and proves DENY; omits it, or backdates last_activity past config('session.lifetime'), and proves DEAD. No driver swap, no second browser, no injected fake. The one extra step is config()->set('session.driver','database') so IncumbentSessionProbe::observable() returns true.
- THE ENGINE REFUSES TO ARM ON A DRIVER IT CANNOT OBSERVE, rather than failing open. enabled() = flag AND probe->observable(), where observable() = config('session.driver') === 'database' && Schema::hasTable('sessions'). On file/redis/array the table is empty, every incumbent would read DEAD, and the single-session rule would silently degrade to 'newest login wins' — a fail-OPEN that would look green in every test. Disarming is the honest failure. Production is 'database' (config/session.php:21 default; the table and its columns are confirmed on the VPS in BRIEF section J). This also means the flag can never be armed by accident in the suite.
- MIDDLEWARE NAMESPACE = App\Modules\DoctorAccess\Middleware\EnsureDoctorSessionLease — a NEW module. It cannot be App\Modules\DoctorDevice\...: tests/Feature/DoctorDevice/DoctorDeviceApiAndNoEnforcementTest.php:246-278 walks every registered route's gatherMiddleware() and asserts any entry containing 'DoctorDevice' (or 'device.proof' or 'trusted.device') equals EnsureDoctorDeviceSession::class, and the collision is on the NAMESPACE segment, so renaming the class does not save it (gap G1). It also should not be App\Http\Middleware\...: DoctorDeviceAccessTest.php:210-244 and DoctorDeviceApiAndNoEnforcementTest.php:199-236 glob app/Http/Middleware/*.php and reject every /[A-Za-z_]*DoctorDevice[A-Za-z_]*/ identifier outside a four-item allowlist (gap G2). bootstrap/app.php IS inside that second scan (DoctorDeviceAccessTest.php:214), so the use line it gains must introduce no DoctorDevice symbol — App\Modules\DoctorAccess\... introduces none. The FQCN contains no device token in any form.
- MIDDLEWARE POSITION = FIRST element of the existing $middleware->web(append: [...]) list at bootstrap/app.php:59-70, i.e. before TouchOnlineContextLastSeen::class at :60. prepend: (:55-57) is impossible — Middleware::getMiddlewareGroups() (vendor .../Configuration/Middleware.php:484-493) puts StartSession INSIDE the group and prepends land before EncryptCookies, so a prepended middleware has neither a session nor $request->user(). Ordering justification against TouchOnlineContextLastSeen: Touch does an unconditional $context->update(['last_seen_at' => now()]) for any authenticated user with an ONLINE context (TouchOnlineContextLastSeen.php:19-28 -> UserOnlineContextService.php:405-413), and assertRoomNotOccupiedByOtherDoctor() (:522-532) keys off exactly that row; appended last, the evicting request would refresh the doctor's presence a moment before killing their session, leaving a ghost occupying the clinic room for up to INACTIVITY_MINUTES = 30 (:18) — gap G15. Running first, the evicting request short-circuits before Touch executes. Against EnsureRmeOnlineContext (:61): it redirects a doctor with no live context to the selector, so appended after it an evicted doctor would be redirected and the lease check would never run — which is why IP3 needed selector-route exemptions; running first makes them unnecessary. EnsureDoctorDeviceSession (:69) stays last and untouched. No test pins this array's order (the four gatherMiddleware() suites assert only toContain / a per-entry name rule).
- THE MIDDLEWARE PASSES THROUGH ANY SESSION THAT CARRIES NO LEASE TOKEN. This single line is what keeps the migration cost at zero: 3554 actingAs() sites and the doctorWithOnlineContext/rmeMakeDoctorOnline helpers (tests/Pest.php:497, :512) mint sessions that never went through Login and hold no token, and the 'two browsers' harness at tests/Feature/DoctorDeviceWebAuthn/DoctorPwaWebAuthnCredentialRevocationTest.php:165-190 snapshots and restores session()->all(). Claim only at Login, renew conditionally, and never create a lease for a session that never had one (BRIEF E4, E5).
- markOffline BEFORE TEARDOWN, ALWAYS (ruling P5). The middleware calls UserOnlineContextService::markOffline($user) (:388-403) — which sets status offline and NULLS clinic_room_id for a ROLE_DOCTOR context — before tearDown(). Without it an evicted doctor's room stays occupied and blocks the doctor taking over. This mirrors AuthenticatedSessionController::destroy(), which already markOffline (:113-115) before logout() at :117, and it is the reason ruling P5 also asks that the bootstrap ordering comment be corrected: the current comment claims a benefit the ordering does not deliver.
- AUDIT UNDER LEASE-SPECIFIC ACTIONS, NEVER THE DEVICE ACTION (ruling P8). Six new constants — DOCTOR_SESSION_LEASE_CLAIMED / _RECLAIMED / _DENIED / _RELEASED / _EVICTED / _REVOKED — written through the shared App\Modules\LabOrder\Services\AuditLogService::log() (:28-49) with entity_type 'trx_doctor_session_leases' and the lease id, falling back to entity_type 'users' with the user id on a denial that created no row. A lease eviction is not a device invalidation, so DOCTOR_SESSION_DEVICE_INVALIDATED (DoctorDeviceSessionService.php:194) is never reused. Payload carries reason, outcome, incumbent_lease_id and effective_branch_id only — never the token, never its hash, never the session id, never the user agent.
- INDONESIAN DENIAL COPY, IN THE SHAPE PROVEN TO RENDER IN THE ANDROID WEBVIEW. With enforcement OFF the tablet loads the ordinary /login inside the WebView (gap G3), so the copy must arrive where the login Blade actually prints it: resources/views/auth/login.blade.php:12 renders only <x-input-error :messages="$errors->get('email')"> (and :24 for 'password'). Two carriers, both already shipped: ValidationException::withMessages(['email' => $msg]) from the login path — the exact shape AuthenticatedSessionController.php:91-93 uses — and redirect()->route('login')->withErrors(['email' => $msg]) from the middleware, plus response()->json(['message' => $msg], 403) when expectsJson(), copied from EnsureDoctorDeviceSession.php:64-70. A ValidationException keyed 'login' (the DoctorDeviceSessionService::deny() shape at :217-219) would render NOTHING on that page. Wording, in the style of DailyBranchContextService::lockedMessage() (:203-217) — actionable, and naming the doctor's own other session is their own working context, not a leak: active_session_elsewhere and lost_claim_race share one message, 'Akun Anda masih aktif di sesi lain sejak pukul {HH:mm} WITA. Keluar dari sesi tersebut lebih dulu, lalu masuk kembali di sini. Jika sesi itu tidak dapat diakses, hubungi Super Admin atau Supervisor RME untuk melepaskannya.'; lease_missing and lease_user_mismatch share 'Sesi Anda sudah tidak berlaku. Silakan masuk kembali.'; branch_context_changed is 'Cabang kerja Anda telah berubah. Silakan masuk kembali untuk melanjutkan.' Deliberately NO device name, IP or user-agent in any message — the same estate-disclosure reasoning DoctorAppLoginGate::denialMessage() records at :408-414 for its three identically-worded proof failures. The time is rendered in the clinic's canonical timezone via app/Support/Clinical/ClinicalClock.php.
- RELEASE ON LOGOUT IS EXPLICIT; EVERY OTHER TEARDOWN SELF-HEALS. Explicit releaseCurrent() calls go in AuthenticatedSessionController::destroy() before the logout at :117, in AuthenticatedSessionController::store() before the device-gate invalidate at :83 (the WebAuthn-ceremony handoff), and in ProfileController::destroy() before Auth::logout() at :55. DoctorDeviceSessionService::invalidate() (:188-207) is deliberately NOT edited: doing so would make the DoctorDevice module depend on DoctorAccess for a property the probe already guarantees, because invalidate() calls session()->invalidate() which destroys the sessions row, after which the abandoned lease reads DEAD and is reclaimed on the next login. The trade-off is one RECLAIMED audit row instead of a RELEASED one, and it is stated rather than hidden.
- OPERATOR RELEASE IS DATA, NOT AN IN-PROCESS LOGOUT (ruling P17, insertion point IP6 shape). releaseFor(int $userId, string $reason, ?User $actor) marks the row released inside one DB::transaction under lockForUpdate and audits DOCTOR_SESSION_LEASE_REVOKED; the victim's session is torn down by the middleware on their NEXT request. No global logout primitive exists or is invented — DoctorDeviceSessionService's docblock at :181-187 explicitly refuses one, and DELETE FROM sessions is untestable as configured (SESSION_DRIVER array everywhere) and defeatable by a recaller cookie. This is the same property BranchChangeApprovalService relies on ('the operator's existing sessions resolve to the new branch on their very next request', :186-192). Say plainly in the sprint doc that this is next-request eviction, not instantaneous. This slice ships only the service method and the audit action; the route, controller and permission for the approver surface belong to the approval slice, which reuses the same reason codes (branch_transfer_approved, cover_activated, cover_expired).
- THE FLAG DEPENDS ON NOTHING, ON PURPOSE. doctor.single_active_session declares dependencies => [] because nothing in the codebase reads that array — FeatureFlagService::enabled() (:52-57) reads only the hydrated 'enabled' — so any declaration would be decorative (ruling P7's finding). Ruling P7's remedy applies to DoctorBranchLockResolver::enabled() requiring BOTH flags, which belongs to the branch-lock slice. The single-session engine must work while doctor.trusted_device_enforcement stays false (config/feature_flags.php:400, owner decision O5), and it must never read that literal in any file — even in a comment — because tests/Feature/DoctorDevice/DoctorDeviceEnforcementGateTest.php:498-518 asserts the reader list is EXACTLY ['app/Modules/DoctorDevice/Services/DoctorAppLoginGate.php'].
- THE ENGINE IS INERT FOR EVERY DOCTOR UNTIL THE FLAG IS ARMED, AND THE FLAG DEFAULTS FALSE, so this slice ships with a migration cost of zero: the listener and the middleware both return on their first line. That is required (FLAG-RISKY-DEFAULT-OFF, FeatureFlagService.php:127-132, risk_level critical) and it is also what lets the slice merge ahead of the branch-lock and approval slices.

## Depends on
- BRANCH-LOCK SLICE — App\Modules\Doctor(Access)\Services\DoctorBranchLockResolver is ABSENT today (grepped app/, config/, database/ for DoctorBranchLockResolver / EFFECTIVE_CLINICAL_BRANCH / effective_branch: only unrelated LabOrder hits). This slice consumes exactly one method and defines no more: effectiveBranchIdFor(User $user): ?int, which must recompute EFFECTIVE_CLINICAL_BRANCH purely from CURRENT timestamps on every call (owner decision Q: active approved cover -> cover.target_branch_id, else HOME_LOCKED_BRANCH), and must return null for an UNSET doctor and for a lock whose branch has lost is_active or is_rme_enabled (ruling P9 — an unusable lock degrades to UNSET-with-audited-warning, never a 500 out of BranchContext::requireId()). Until it lands, the listener and middleware pass null and the branch comparison is skipped — the single-session rule is fully functional without it.
- BRANCH-LOCK SLICE — the effective_branch_id column on trx_doctor_session_leases is written by THIS slice at claim time and compared by THIS slice's middleware, but its MEANING is owned there. Owner decision Q's mechanism (cover activation, cover expiry and permanent transfer all invalidate the session, with no cron in the correctness path) is implemented by the DENY_BRANCH_CONTEXT_CHANGED branch of revalidate(); the resolver is what makes it true.
- APPROVAL SLICE — the route, controller, FormRequest, policy/Gate and Blade for the approver's lease-release action (ruling P17). This slice ships DoctorSessionLeaseService::releaseFor() and the DOCTOR_SESSION_LEASE_REVOKED action; the approval slice must call it inside its own DB::transaction (the BranchChangeApprovalService::approve() shape at :147-215) with reason branch_transfer_approved / cover_activated / cover_expired, and must NOT invent a second release path. Note the approval-authority question is already half-closed by a critical-gated test: tests/Feature/AccessControl/DailyBranchContextBypassTest.php:347 denies the existing branch-change-request.approve Gate (RepositoryServiceProvider.php:593) to every non-Super-Admin role, so widening that Gate breaks it (gap G8).
- BULK DEVICE-AUTHORIZATION SLICE (owner decision O4) — independent; the lease engine reads no device or authorization row and adds no branch predicate to any login path.
- SPRINT-MANIFEST / GOVERNANCE SLICE — .sprint/current.yml must keep naming LEGACY-RME-PROGRAM-CLOSURE-1 (tests/Feature/LegacyRme/LegacyRmeProgramClosureContractTest.php:146-157, selected by the LegacyRme token) and must declare schema_change=true and security_impact=true for this slice's migration and middleware, or SprintManifestValidator (app/Support/Devflow/SprintManifestValidator.php:148-155) hard-errors. This slice declares frontend_change=false honestly: it touches no Blade, JS, CSS or Vite file.

## Risks
- THE PROBE'S user_id PREDICATE ASSUMES THE CLAIMANT HAS NO sessions ROW AT LISTENER TIME. That holds because updateSession() calls regenerate(true) (SessionGuard:592 -> Store::migrate :633-635 -> DatabaseSessionHandler::destroy :268, an immediate DELETE) before fireLoginEvent, and the new row is not written until StartSession saves on the response. It is defended by also excluding the current session id. But if a future Laravel version defers that destroy, or a deployment swaps to a handler that does not delete on migrate, a doctor re-authenticating in an already-authenticated browser would be denied by their own session. Pin it: a test that logs in twice in the same browser (no incumbent elsewhere) and asserts the second login succeeds.
- A SECOND DENIAL SHAPE THE SUITE WILL NOT SEE BY DEFAULT. With the flag off (its default) nothing in this slice executes, so a regression can only be caught by the tests that arm it. Every new behaviour therefore needs its own case, and the file must live in tests/Feature/DoctorDevice/ or the PostgreSQL half of the proof never runs anywhere (CI critical job sets DB_CONNECTION: pgsql at foundation-evidence-gates.yml:315, :524, and phpunit.xml's <env> carry no force="true").
- RefreshDatabase (tests/Pest.php:23) wraps every test in an open transaction, so the claim's outer DB::transaction is already a SAVEPOINT and the nested INSERT is a savepoint at depth 2/3, versus 1/2 in production (gap G12). The 25P02-recovery path is therefore exercised at a different nesting level than it will run at, and no cross-connection race is expressible — lockForUpdate is effectively untestable and the unique index can only be proven by inserting a conflicting row directly. State this in the test comments rather than claiming parity.
- THE THROW HAPPENS INSIDE Auth::attempt(), SO LoginRequest::authenticate() REACHES NEITHER RateLimiter::hit() (:46) NOR RateLimiter::clear() (:53). A denied doctor is neither pushed toward the 5-attempt lockout (:63) nor credited with a clear. This is the behaviour we want but it is a genuine deviation from the failed-password path; if it is ever considered wrong, the fix is in the listener, not in LoginRequest.
- THE ANDROID WEBVIEW KEEPS A PERSISTENT COOKIE JAR AND, WITH ENFORCEMENT OFF, EVERY APP LAUNCH IS AN ORDINARY PASSWORD LOGIN AT /login (gap G3: DoctorLoginState.kt:72-80 APPROVED_ENFORCEMENT_OFF, MainActivity.kt:266 vs :271, :401-410). Under 'deny the second login' a doctor whose browser session is still live will be refused on the tablet with a message they can act on — which is correct — but the operational reality is that the tablet is the surface where this will be hit most, and the denial copy naming the operator release path is the only escape hatch that does not require the other device. Do not soften this into 'newest wins'; owner requirement 1 forbids it.
- NEXT-REQUEST EVICTION IS NOT INSTANTANEOUS. releaseFor() commits data; the victim keeps working until their browser makes another request. For an idle tablet that could be minutes. There is no in-process cross-session logout primitive in this codebase and this slice does not invent one (DoctorDeviceSessionService.php:181-187 refuses it in writing; DELETE FROM sessions is untestable as configured and defeatable by a recaller cookie). Say so in the sprint doc; do not let a reader infer atomicity from the word 'invalidate'.
- A LEASE ORPHANED BY DoctorDeviceSessionService::invalidate() OR BY THE per-request DEVICE DENIAL (EnsureDoctorDeviceSession.php:62) SELF-HEALS ONLY BECAUSE invalidate() DESTROYS THE sessions ROW. That inference chain is four steps long. If someone later changes invalidate() to stop calling session()->invalidate(), an orphaned lease would block that doctor's next login until SESSION_LIFETIME elapsed. Guard it with a test that asserts a device-denied doctor can log in again immediately.
- claim_user_agent AND claim_ip ARE STORED. They are not KTP/NIK-class PII and sys_audit_logs already records both (AuditLogService.php:46-47), but they must NEVER be rendered in a denial message (estate disclosure) and should be shown only on the operator release surface behind the approver permission. The audit payload deliberately excludes them.
- THREE PRE-EXISTING SITES USE THE BROKEN CATCH-INSIDE-TRANSACTION SHAPE THIS SLICE COPIES CORRECTLY — app/Modules/RME/Services/PatientDoctorAssignmentService.php:48 and :172, app/Modules/MedicalRecord/Services/DiagnosisRolloutService.php:127. Section M proves each fails with 25P02 on PostgreSQL when its race actually fires. OUT OF SCOPE for this sprint; report to the owner, do not widen.
- THE BOOTSTRAP APPEND ARRAY IS BEING REORDERED. No test pins the order today (verified: the four gatherMiddleware() suites assert only toContain or a per-entry name rule, and nothing greps for 'web(append'), but this is a global middleware stack — the change must be called out in the sprint doc and in the manifest's security_impact declaration rather than slipped in.

## Files

### NEW database/migrations/2026_09_10_100001_create_trx_doctor_session_leases_table.php
PURPOSE: The lease store and, in the SAME migration, the unguarded partial unique index that is the cardinality-1 invariant.

Schema::create('trx_doctor_session_leases', ...): id(); foreignId('user_id')->constrained()->cascadeOnDelete() — cascade is required because ProfileController.php:59 force-deletes the account (`$user->forceDelete()`), and a RESTRICT there would 500 on self-service deletion; unsignedBigInteger('doctor_id')->nullable()->index() with NO foreign key (audit denormalisation only, per architect decision D2 — an FK would import cascade semantics we do not want and mst_doctors soft-deletes); string('session_token_hash', 64)->index(); string('session_id')->nullable() (a HINT, never the identity — see decisions); unsignedBigInteger('effective_branch_id')->nullable()->index() with NO foreign key (a branch may be deactivated and must never cascade a lease away); timestamp('claimed_at'); timestamp('last_seen_at')->nullable(); timestamp('released_at')->nullable()->index(); string('released_reason', 64)->nullable(); unsignedBigInteger('released_by_user_id')->nullable(); string('claim_ip', 45)->nullable(); text('claim_user_agent')->nullable(); timestamps(). Then, OUTSIDE the closure and in this same migration file:

  DB::statement(
      'CREATE UNIQUE INDEX trx_doctor_session_leases_active_uq '
      .'ON trx_doctor_session_leases (user_id) WHERE released_at IS NULL'
  );

Unguarded (no driver check), copying the shipped precedent at database/migrations/2026_08_29_100002_create_trx_branch_change_requests_table.php:88-93 and its rationale at :26. It MUST be in this migration and not a later one (BRIEF F6, K): a later `constrained()` FK column add can rebuild the table on SQLite and silently drop the WHERE clause, which for a lease does not weaken the constraint — it makes it permanently unreleasable, so a doctor could never log in twice, and only in the local suite. Section K proved the index is accepted and enforced on sqlite 3.46.1 and section M proved the same on PostgreSQL 16. Requires `use Illuminate\Support\Facades\DB;`. down(): Schema::dropIfExists('trx_doctor_session_leases').

### NEW app/Modules/DoctorAccess/Models/DoctorSessionLease.php
PURPOSE: Eloquent model + the stable reason/action vocabularies.

namespace App\Modules\DoctorAccess\Models; protected $table = 'trx_doctor_session_leases'; protected $fillable = [] with the docblock rationale copied from app/Modules/DoctorDevice/Models/DoctorDeviceAuthorization.php:63-69 ('Nothing is fillable. Every column here is a lifecycle decision') — writes go through the repository with forceFill()->save(), the convention at app/Modules/DoctorDevice/Repositories/DoctorDeviceAuthorizationRepository.php:60-74. casts(): user_id/doctor_id/effective_branch_id/released_by_user_id => 'integer'; claimed_at/last_seen_at/released_at => 'datetime'.

Release reasons (const): REASON_LOGOUT='logout'; REASON_ACCOUNT_DELETED='account_deleted'; REASON_DEAD_INCUMBENT='dead_incumbent'; REASON_DEVICE_CEREMONY_HANDOFF='device_ceremony_handoff'; REASON_EVICTED='evicted'; REASON_OPERATOR_RELEASE='operator_release'; REASON_BRANCH_TRANSFER_APPROVED='branch_transfer_approved'; REASON_COVER_ACTIVATED='cover_activated'; REASON_COVER_EXPIRED='cover_expired'.

Audit actions (const), deliberately NOT the device action DOCTOR_SESSION_DEVICE_INVALIDATED used at app/Modules/DoctorDevice/Services/DoctorDeviceSessionService.php:194 (ruling P8): ACTION_CLAIMED='DOCTOR_SESSION_LEASE_CLAIMED'; ACTION_RECLAIMED='DOCTOR_SESSION_LEASE_RECLAIMED'; ACTION_DENIED='DOCTOR_SESSION_LEASE_DENIED'; ACTION_RELEASED='DOCTOR_SESSION_LEASE_RELEASED'; ACTION_EVICTED='DOCTOR_SESSION_LEASE_EVICTED'; ACTION_REVOKED='DOCTOR_SESSION_LEASE_REVOKED'.

Method: public function isActive(): bool { return $this->released_at === null; }

No newFactory() override is needed unless a factory is added; if one is, it must be declared explicitly (factories live under Database\Factories\, not Database\Factories\Modules\...).

### NEW app/Modules/DoctorAccess/Interfaces/DoctorSessionLeaseRepositoryInterface.php
PURPOSE: The repository boundary the enterprise architecture baseline (ENT-1) requires between service and model.

namespace App\Modules\DoctorAccess\Interfaces;

public function create(array $attributes): DoctorSessionLease;
public function lockActiveForUser(int $userId): ?DoctorSessionLease;   // SELECT ... FOR UPDATE
public function activeForUser(int $userId): ?DoctorSessionLease;       // no lock, read path
public function activeByTokenHash(string $hash): ?DoctorSessionLease;  // middleware lookup
public function release(DoctorSessionLease $lease, string $reason, ?int $releasedByUserId = null): DoctorSessionLease;
public function renew(DoctorSessionLease $lease, ?string $sessionId): void;

### NEW app/Modules/DoctorAccess/Repositories/DoctorSessionLeaseRepository.php
PURPOSE: Concrete repository.

Copies the lock shape of app/Modules/RmeOnlineContext/Repositories/DailyBranchContextRepository.php:20-26 verbatim in structure:

lockActiveForUser: DoctorSessionLease::query()->where('user_id', $userId)->whereNull('released_at')->lockForUpdate()->first();
activeByTokenHash: ->where('session_token_hash', $hash)->whereNull('released_at')->first();
create: $lease = new DoctorSessionLease; $lease->forceFill($attributes)->save(); return $lease;  // forceFill because $fillable is empty
release: $lease->forceFill(['released_at' => now(), 'released_reason' => $reason, 'released_by_user_id' => $releasedByUserId])->save(); return $lease;
renew: $lease->forceFill(array_filter(['last_seen_at' => now(), 'session_id' => $sessionId], fn ($v) => $v !== null))->save();

### NEW app/Modules/DoctorAccess/Support/IncumbentSessionProbe.php
PURPOSE: The ONLY place that answers 'is the incumbent's session still alive?', and the place that refuses to guess when it cannot observe.

namespace App\Modules\DoctorAccess\Support;

public function observable(): bool
    — returns config('session.driver') === 'database' && Schema::hasTable('sessions').
    RATIONALE (this is the fail-closed-by-disarming decision, see decisions): nothing in app/ reads the sessions table today, and the driver is resolved from SESSION_DRIVER at config/session.php:21 defaulting to 'database'. On any other driver the table is empty, so every incumbent would read DEAD and the single-session rule would silently degrade to 'newest login wins' — a fail-OPEN. The engine therefore refuses to arm rather than pretend (see DoctorSessionLeaseService::enabled()).

public function isLive(DoctorSessionLease $lease, ?string $excludeSessionId): bool
    return DB::table('sessions')
        ->where('user_id', (int) $lease->user_id)
        ->when($excludeSessionId !== null, fn ($q) => $q->where('id', '!=', $excludeSessionId))
        ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime', 120))->getTimestamp())
        ->exists();

WHY user_id AND NOT the recorded session id. Illuminate\Session\Store::migrate() (vendor/laravel/framework/src/Illuminate/Session/Store.php:631-642) mints a NEW id at :639, and SessionGuard::updateSession() (:592) calls regenerate(true) BEFORE fireLoginEvent, and every login path then regenerates AGAIN (AuthenticatedSessionController.php:34, DoctorDeviceSessionService.php:117, DoctorDeviceWebAuthnLoginService.php:353). So the id recorded at claim time is stale within the same request. `sessions.user_id` is written by DatabaseSessionHandler::addUserInformation() (:204-211) and indexed (database/migrations/0001_01_01_000000_create_users_table.php:32), and it does not rotate.

WHY EXCLUDING THE CLAIMANT'S OWN ID IS SAFE AND STILL NECESSARY. At the moment the Login listener runs, regenerate(true) has already called handler->destroy($oldId) (Store.php:634 -> DatabaseSessionHandler::destroy() at :266-271, an immediate DELETE), and the new row is not written until StartSession saves on the response — so the claimant currently has NO row. The exclusion is belt-and-braces: it can never wrongly mark a foreign session dead, and it keeps the predicate correct if that destroy timing ever changes.

The lifetime window mirrors DatabaseSessionHandler::expired() (:121-122) exactly, so 'live' here means the same thing the session driver itself means. `last_activity` is an integer unix timestamp (create_users_table.php:36), hence ->getTimestamp().

### NEW app/Modules/DoctorAccess/Services/DoctorSessionLeaseService.php
PURPOSE: The claim/deny/release engine. All lease business logic lives here and nowhere else.

namespace App\Modules\DoctorAccess\Services;
Constructor: DoctorSessionLeaseRepositoryInterface $leases, IncumbentSessionProbe $probe, FeatureFlagService $flags, AuditLogService $auditLogs (App\Modules\LabOrder\Services\AuditLogService — the shared trail, signature log(string $entityType, ?int $entityId, string $action, ?array $old, ?array $new, ?User $actor) at :28-49).

CONSTANTS
  public const FLAG = 'doctor.single_active_session';
  public const SESSION_LEASE_TOKEN = 'doctor_access.session_lease_token';
    — NOT under the 'doctor_device.' prefix, so it can never collide with the six device session keys declared at DoctorAppLoginGate.php:51-69 and can never be mistaken for a device binding.
  Denial codes: DENY_ACTIVE_SESSION_ELSEWHERE='active_session_elsewhere'; DENY_LOST_CLAIM_RACE='lost_claim_race'; DENY_LEASE_MISSING='lease_missing'; DENY_LEASE_MISMATCH='lease_user_mismatch'; DENY_BRANCH_CONTEXT_CHANGED='branch_context_changed'.
  private const RENEW_INTERVAL_MINUTES = 5;

public function enabled(): bool
  return $this->flags->enabled(self::FLAG) && $this->probe->observable();
  Read through FeatureFlagService::enabled() (app/Services/Foundation/FeatureFlagService.php:52-57) only — never config('feature_flags.flags.doctor.single_active_session'), because flag KEYS contain dots and dot-notation builds a nested array the service never reads (documented at app/Modules/... FeatureFlagService.php:74-84 and in the test helper at tests/Feature/DoctorDevice/DoctorDeviceEnforcementGateTest.php:36-54).

public function subjectTo(User $user): bool { return $user->hasRole('Doctor'); }
  Same predicate as DoctorAppLoginGate::appliesTo() (:122-125) but declared HERE and not delegated, so the single-session rule is not entangled with device enforcement — it must work while doctor.trusted_device_enforcement stays false (config/feature_flags.php:400, owner decision O5).

public function claimOrDeny(User $user, Request $request, ?int $effectiveBranchId): void
  THE TRANSACTION, copied from DailyBranchContextService::assertSelectable() (app/Modules/RmeOnlineContext/Services/DailyBranchContextService.php:137-204) with ONE deliberate change stated below.

    $token = bin2hex(random_bytes(32));            // precedent: DoctorDeviceWebAuthnChallengeService.php:177-179
    $hash  = hash('sha256', $token);
    $currentSessionId = $request->hasSession() ? $request->session()->getId() : null;

    $verdict = DB::transaction(function () use (...) {
        $incumbent = $this->leases->lockActiveForUser($userId);

        if ($incumbent === null) {
            try {
                $lease = DB::transaction(fn () => $this->leases->create($attrs));   // NESTED -> SAVEPOINT
                return Verdict::granted($lease);
            } catch (QueryException $e) {                       // CAUGHT OUTSIDE THE SAVEPOINT
                if (! $this->isUniqueViolation($e)) { throw $e; }
                $incumbent = $this->leases->lockActiveForUser($userId);   // safe now: ROLLBACK TO SAVEPOINT ran
                if ($incumbent === null) { throw $e; }
            }
        }

        if (! $this->probe->isLive($incumbent, $currentSessionId)) {
            $this->leases->release($incumbent, DoctorSessionLease::REASON_DEAD_INCUMBENT, null);
            try {
                $lease = DB::transaction(fn () => $this->leases->create($attrs));   // NESTED again
                return Verdict::reclaimed($lease, $incumbent);
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e)) { throw $e; }
                return Verdict::denied(self::DENY_LOST_CLAIM_RACE, null);
            }
        }

        return Verdict::denied(self::DENY_ACTIVE_SESSION_ELSEWHERE, $incumbent);
    });

  $attrs = ['user_id'=>$userId, 'doctor_id'=>$doctorId, 'session_token_hash'=>$hash, 'session_id'=>$currentSessionId, 'effective_branch_id'=>$effectiveBranchId, 'claimed_at'=>now(), 'last_seen_at'=>now(), 'claim_ip'=>$request->ip(), 'claim_user_agent'=>Str::limit((string) $request->userAgent(), 500, '')].

  Assign the nested DB::transaction's return value — it returns $callbackResult (vendor/.../Database/Concerns/ManagesTransactions.php:73). Discarding it is the compile defect ruling P13 names, and the exemplar at DailyBranchContextService.php:165-172 discards it.

  THE ONE DELIBERATE DIVERGENCE FROM THE EXEMPLAR: assertSelectable() throws its ValidationException INSIDE the transaction (:200-202) because it has nothing to persist on the refusal path. This service DOES — the denial audit row and the session teardown — so the transaction RETURNS a verdict and the throw happens after commit:
    - on GRANTED/RECLAIMED: $request->session()->put(self::SESSION_LEASE_TOKEN, $token); audit ACTION_CLAIMED / ACTION_RECLAIMED.
    - on DENIED: audit ACTION_DENIED (so the refusal survives), then tearDown($request, $user, $reason), then throw ValidationException::withMessages(['email' => $this->denialMessage($reason, $incumbent)]).
  Keeping the teardown outside the transaction also matters mechanically: session()->invalidate() issues a DELETE on `sessions` through the same connection (Store.php:609 -> :634 -> DatabaseSessionHandler::destroy() :268); inside the transaction a later rollback would undo it.

public function tearDown(Request $request, ?User $user, string $reason): void
  Auth::guard('web')->logoutCurrentDevice();     // vendor SessionGuard.php:680
  if ($request->hasSession()) { $request->session()->invalidate(); $request->session()->regenerateToken(); }
  NEVER Auth::guard('web')->logout() (:650), which calls cycleRememberToken($user) at :657; users.remember_token is one shared column (0001_01_01_000000_create_users_table.php:20) so cycling it kills the recaller for EVERY session of that account — refusing login #2 would partially evict session #1, which requirement 1 forbids (ruling P4, section R). This is exactly why DoctorDeviceSessionService::invalidate() (:188-207) CANNOT be reused here: it calls logout() at :201.

public function currentLease(Request $request): ?DoctorSessionLease  — resolve the session-DATA token, hash it, activeByTokenHash().

public function revalidate(Request $request, User $user, ?int $effectiveBranchIdNow): ?string
  1. token absent/empty -> return null (PASS THROUGH — see the middleware entry).
  2. activeByTokenHash() null -> DENY_LEASE_MISSING.
  3. (int) $lease->user_id !== (int) $user->id -> DENY_LEASE_MISMATCH.
  4. $effectiveBranchIdNow !== null && $lease->effective_branch_id !== null && $effectiveBranchIdNow !== (int) $lease->effective_branch_id -> DENY_BRANCH_CONTEXT_CHANGED. Both-null and either-null are NOT a denial, so an UNSET doctor (owner decision O1's compatibility state) is never evicted by a branch the resolver declines to answer.
  5. $this->renewIfDue($lease, $request); return null.

private function renewIfDue(DoctorSessionLease $lease, Request $request): void
  Write only when $lease->last_seen_at === null || $lease->last_seen_at->lt(now()->subMinutes(self::RENEW_INTERVAL_MINUTES)) || $lease->session_id !== $request->session()->getId(). The session_id clause is what corrects the post-login id drift exactly once. A plain conditional, NOT Cache::lock(): CACHE_STORE is array in phpunit.xml:24 and in both CI jobs (.github/workflows/foundation-evidence-gates.yml:320, :541), so a cache throttle is per-request under test and therefore untestable — that is why the Cache::lock() precedent at app/Modules/Satusehat/Gateways/OAuthClientCredentialsSatusehatTokenProvider.php:38 is rejected here.
  last_seen_at IS NOT A TTL. It is diagnostics and operator display only. There is deliberately NO idle reclamation (ruling P3 reverses proposed insertion point IP5); say so in the docblock so a later reader does not reintroduce eviction by the back door.

public function releaseCurrent(Request $request, ?User $user, string $reason): void
  Resolve the current lease from the session token, release it, forget the session key, audit ACTION_RELEASED. No-op when there is no token.

public function releaseFor(int $userId, string $reason, ?User $actor): ?DoctorSessionLease
  One DB::transaction: lockActiveForUser($userId), release with $reason and released_by_user_id = $actor?->id, audit ACTION_REVOKED. DATA ONLY — the victim's session is torn down by the middleware on their NEXT request. Same shape and same honesty as BranchChangeApprovalService.php:186-192 ('the operator's existing sessions resolve to the new branch on their very next request'). This is next-request eviction, not instantaneous, and it is bounded by how often the victim's browser talks to the server; the sprint doc must say that plainly.

private function isUniqueViolation(QueryException $e): bool
  Copied VERBATIM from DailyBranchContextService.php:221-233 (the codebase duplicates this per service rather than sharing it; follow that convention): '23505' -> true, else lowercase message contains 'unique constraint' | 'unique violation' | 'duplicate key'. sqlite reports SQLSTATE 23000 and PostgreSQL 23505, so a check on either alone is wrong on the other engine (sections K and M).

public function denialMessage(string $reason, ?DoctorSessionLease $incumbent = null): string  — see the Indonesian copy in decisions.

### NEW app/Modules/DoctorAccess/Listeners/ClaimDoctorSessionLease.php
PURPOSE: Claims the lease on EVERY production authentication entry, including the remember-me recaller path that no login controller can see.

namespace App\Modules\DoctorAccess\Listeners; final class ClaimDoctorSessionLease { public function handle(Login $event): void }

Guards, in order, each an early return:
  1. $event->guard !== 'web'  — the device channel is a stateless api group (bootstrap/app.php:29-30) and must never mint a lease.
  2. ! $this->leases->enabled().
  3. ! $event->user instanceof \App\Models\User.
  4. ! $this->leases->subjectTo($user).
  5. ! request()->hasSession()  — covers Auth::login() from a console command or seeder.
Then: $branchId = $this->branchLock->effectiveBranchIdFor($user);  // sibling slice, tolerate null
      $this->leases->claimOrDeny($user, request(), $branchId);

WHY A Login LISTENER AND NOT LOGIN CONTROLLERS. Illuminate\Auth\Events\Login is fired at vendor/laravel/framework/src/Illuminate/Auth/SessionGuard.php:573 from login(), AND at :202 on the recaller path (userFromRecaller() at :197, updateSession() at :200, fireLoginEvent($this->user, true) at :202). A remember-me cookie therefore mints a fresh authenticated session through NO controller at all — remember-me is live end to end (checkbox at resources/views/auth/login.blade.php:29-32 -> Auth::attempt($credentials, $this->boolean('remember')) at app/Http/Requests/Auth/LoginRequest.php:45), so a claim placed only at login call sites is bypassable by design. setUser() (:996-1005) fires fireAuthenticatedEvent, NOT Login, and actingAs() goes through setUser — so this listener covers 100% of production entries and 0% of the ~3554 actingAs() call sites, which is what makes it implementable without rewriting the suite. It also covers RegisteredUserController.php:48 (Auth::login) for free, satisfying ruling P15 without touching that controller.

HOW A LISTENER DENIES A LOGIN THAT HAS ALREADY HAPPENED. At fireLoginEvent time three things have happened and one has not: updateSession() (:559) has put the auth id into the session and called $this->session->regenerate(true) (:592), destroying the old sessions row immediately (Store.php:634 -> DatabaseSessionHandler::destroy() :268) and minting a new id (:639); if remember was set, ensureRememberTokenIsSet() (:566) and queueRecallerCookie() (:568) have QUEUED a recaller cookie; and setUser() (:575) has NOT run, so $this->user is still null. Nothing is persisted yet — the sessions row is written by StartSession on the response and the cookie is attached by AddQueuedCookiesToResponse on the response. The denial therefore works by making the response carry a dead session and no recaller cookie, and by throwing so no controller can emit a success response:
  a. Auth::guard('web')->logoutCurrentDevice() (:680) -> clearUserDataFromStorage() (:684 -> :704-715) removes the auth key from the session (:706), UNQUEUES the recaller cookie (:708) and queues a forget-cookie when one arrived on the request (:710-713), then sets user=null, loggedOut=true.
  b. $request->session()->invalidate() (Store.php:605-610) flushes ALL session attributes and migrates(true) — this is what makes the refusal durable, because the lease token lives in session DATA and DATA survives regenerate() but dies with invalidate()/flush().
  c. $request->session()->regenerateToken() (Store.php:755-758) so the redirect-back carries a usable CSRF token.
  d. throw ValidationException::withMessages(['email' => ...]).
Because the listener throws, login() never reaches setUser() at :575, so the guard stays loggedOut for the remainder of the request and a later $request->user() cannot resurrect the denied identity. The dispatcher does not swallow it: Dispatcher::invokeListeners() calls $listener($event, $payload) with no try/catch (vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php:318). Returning false only halts propagation and is discarded by fireLoginEvent (:820-823), so a return value CANNOT deny — it must be a throw.

TWO CONSEQUENCES TO PIN, NOT HIDE:
  - The throw unwinds through Auth::attempt() inside LoginRequest::authenticate() (:45), so neither RateLimiter::hit() (:46) nor RateLimiter::clear() (:53) runs. A denied doctor is neither pushed toward the 5-attempt lockout (:63) nor credited with a clear. That is the behaviour we want — a doctor whose other tablet is still open must not also be locked out of the form — but it is a real deviation from the failed-password path and needs its own test.
  - On the recaller path the event fires from inside Auth::user(), which can be called from anywhere (the Authenticate middleware, a view). ValidationException is handled globally, so an HTML request gets a redirect-back-with-errors and an expectsJson() request gets a 422 JSON body. The forget-cookie queued at SessionGuard:710-713 is what stops a remember-me browser re-entering userFromRecaller() and being denied in a loop on every subsequent request.

### NEW app/Modules/DoctorAccess/Middleware/EnsureDoctorSessionLease.php
PURPOSE: Per-request lease revalidation and the next-request eviction primitive.

namespace App\Modules\DoctorAccess\Middleware; class EnsureDoctorSessionLease.
Constructor: DoctorSessionLeaseService $leases, UserOnlineContextService $onlineContexts, DoctorBranchLockResolver $branchLock (sibling slice).

handle(Request $request, Closure $next): Response
  if (! $this->leases->enabled()) return $next($request);              // one flag read, then out — the EnsureDoctorDeviceSession.php:38-41 shape
  $user = $request->user();
  if ($user === null || ! $this->leases->subjectTo($user)) return $next($request);
  if ($request->routeIs('logout', 'login')) return $next($request);     // never block the way out; note POST /login is UNNAMED (routes/auth.php:23) while GET login is named (:20-21), and logout is named at :57-58
  if (! $request->hasSession()) return $next($request);
  $token = $request->session()->get(DoctorSessionLeaseService::SESSION_LEASE_TOKEN);
  if (! is_string($token) || $token === '') return $next($request);     // NO LEASE AT ALL -> PASS THROUGH
  $reason = $this->leases->revalidate($request, $user, $this->branchLock->effectiveBranchIdFor($user));
  if ($reason === null) return $next($request);
  $this->onlineContexts->markOffline($user);                            // BEFORE teardown
  $this->leases->tearDown($request, $user, $reason);                    // audits ACTION_EVICTED
  $message = $this->leases->denialMessage($reason);
  return $request->expectsJson()
      ? response()->json(['message' => $message], 403)
      : redirect()->route('login')->withErrors(['email' => $message]);

THE PASS-THROUGH LINE IS THE WHOLE SUITE-COMPATIBILITY STORY. 3554 actingAs() call sites and the doctorWithOnlineContext/rmeMakeDoctorOnline helpers (tests/Pest.php:497, :512) produce sessions that never went through Login and therefore carry no lease token. Denying 'no lease at all' would make the migration cost the entire suite (BRIEF E5), and it would also break the 'two browsers' harness in tests/Feature/DoctorDeviceWebAuthn/DoctorPwaWebAuthnCredentialRevocationTest.php:165-190 which snapshots and restores session()->all() (BRIEF E4).

markOffline BEFORE teardown (ruling P5): app/Modules/RmeOnlineContext/Services/UserOnlineContextService.php:388-403 sets status offline and NULLS clinic_room_id for a ROLE_DOCTOR context. Without it an evicted doctor's room stays occupied and assertRoomNotOccupiedByOtherDoctor() (:522-532) blocks the doctor taking over for up to INACTIVITY_MINUTES = 30 (:18). This mirrors AuthenticatedSessionController::destroy(), which also calls markOffline (:113-115) before logging out at :117.

The response shape is copied from the shipped, WebView-proven denial at app/Modules/DoctorDevice/Middleware/EnsureDoctorDeviceSession.php:64-70.

### EDIT bootstrap/app.php
PURPOSE: Register the middleware FIRST in the existing web append list.

ANCHOR: the array literal opened at line 59, `$middleware->web(append: [`, whose first element is `TouchOnlineContextLastSeen::class,` at line 60. Insert `EnsureDoctorSessionLease::class,` as the new FIRST element, i.e. immediately after line 59 and before line 60, and add `use App\Modules\DoctorAccess\Middleware\EnsureDoctorSessionLease;` to the use block (alphabetically it lands between the ClinicVisit import at line 10 and the DoctorDevice import at line 11).

WHY NOT prepend: (:55-57). Middleware::getMiddlewareGroups() (vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php:484-493) puts EncryptCookies, AddQueuedCookiesToResponse, StartSession, ShareErrorsFromSession, ValidateCsrfToken, SubstituteBindings INSIDE the web group, and group prepends land before EncryptCookies — so a prepended middleware runs before StartSession and has neither a session nor $request->user(). That is why AttachRequestCorrelationContext can be prepended (it needs neither) and this cannot.

WHY FIRST IN THE APPEND LIST rather than last, which is what proposed insertion point IP3 assumed:
  - vs TouchOnlineContextLastSeen (:60): Touch does an unconditional $context->update(['last_seen_at' => now()]) for any authenticated user with an ONLINE context (TouchOnlineContextLastSeen.php:19-28 -> UserOnlineContextService.php:405-413). Appended last, the very request that evicts a doctor would first have refreshed their presence, and assertRoomNotOccupiedByOtherDoctor() (:522-532) keys off exactly that row. Running first means the evicting request short-circuits before Touch executes at all. This is gap G15, and it also corrects the existing bootstrap ordering comment, which claims a benefit the ordering does not deliver.
  - vs EnsureRmeOnlineContext (:61): that middleware redirects a doctor with no live online context to the selector. Appended after it, an evicted doctor would be redirected to the selector and the lease check would never run on that request — which is precisely why IP3 had to propose selector-route exemptions. Running first makes those exemptions unnecessary.
  - vs EnsureDoctorDeviceSession (:69): unchanged and still last. A dead lease and a revoked device are independent reasons with independent audit actions; whichever fires first tears the session down.
No test pins the order of this array: the four suites that call gatherMiddleware() assert only ->toContain / a per-entry name rule (tests/Feature/LegacyImportHub/LegacyImportHubSurfaceTest.php:55-63, tests/Feature/MasterData/DoctorAccountLinkTest.php:569-570, tests/Feature/DoctorDevice/DoctorDeviceApiAndNoEnforcementTest.php:246-278, tests/Feature/LegacyRme/LegacyRmeImportLifecycleParityTest.php).

### EDIT app/Providers/AppServiceProvider.php
PURPOSE: Register the Login listener explicitly, because this application has NO event auto-discovery.

ANCHOR: boot() at line 119-123, whose body is currently exactly `$this->registerDoctorAppLoginRateLimiters(); $this->refuseForbiddenConsoleCommands();`. Add a third call `$this->registerDoctorSessionLeaseListener();` and a private method beside refuseForbiddenConsoleCommands() (which begins at :138) doing:

  Event::listen(Login::class, ClaimDoctorSessionLease::class);

Event::listen in boot() is the established pattern here — the CommandStarting listener at :140 uses it. NOTE `use Illuminate\Support\Facades\Event;` already exists at :28.

WHY EXPLICIT REGISTRATION IS MANDATORY: bootstrap/app.php never calls ->withEvents(). withEvents() (vendor/laravel/framework/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php:98-114) is the only thing that registers Illuminate\Foundation\Support\Providers\EventServiceProvider and turns on discovery, and bootstrap/providers.php lists only AppServiceProvider, RepositoryServiceProvider and SatusehatServiceProvider. app/Listeners does not exist (verified ABSENT). A listener dropped into app/Listeners expecting auto-discovery would silently never run — the same class of trap as the `protected $policies` one documented at BRIEF F13.

This file is NOT inside either DoctorDevice source-scan glob (those cover app/Http/Controllers/Auth/*.php, app/Http/Middleware/*.php, app/Services/Auth/*.php, bootstrap/app.php and app/Http/Requests/Auth/LoginRequest.php — tests/Feature/DoctorDevice/DoctorDeviceAccessTest.php:210-215 and tests/Feature/DoctorDevice/DoctorDeviceApiAndNoEnforcementTest.php:199-204), so it is free to name DoctorAccess symbols.

### EDIT app/Providers/RepositoryServiceProvider.php
PURPOSE: Bind the lease repository interface to its implementation.

ANCHOR: the `private array $repositories = [` block that opens at line 286; add `DoctorSessionLeaseRepositoryInterface::class => DoctorSessionLeaseRepository::class,` beside the four DoctorDevice bindings at lines 293-296, plus the two `use` imports next to the DoctorDevice imports at lines 39-50. The array is bound by the loop at :515 (`$this->app->bind($interface, $concrete);`). NOTE the array is `private`, not `protected` — copying stock Laravel registers nothing (same hazard as the `$policies` array bound at :566 via Gate::policy). This slice adds NO policy and NO Gate.

### EDIT config/feature_flags.php
PURPOSE: Register doctor.single_active_session, default OFF.

ANCHOR: insert a new entry immediately after the closing `],` of the `doctor.pwa_webauthn_device_login` definition, which spans lines 410-421 (its `rollback_action` is the last key), and before the `// --- FIX-04b — legacy ODONTOGRAM chart archive` comment.

All TEN keys are mandatory — tests/Feature/Foundation/FeatureFlagFoundationTest.php:15-24 iterates every flag asserting name, description, default, env_key, owner, risk_level, rollout_status, dependencies, rollback_action, enabled — and that suite is selected by the `FeatureFlag` token in the critical filter (.github/workflows/foundation-evidence-gates.yml:422). Add `review_target` too, as both sibling doctor flags do.

  'doctor.single_active_session' => [
    'name' => 'Doctor Single Active Session',
    'description' => ... state that it is an ENFORCEMENT switch; that with it off the Login listener and the per-request middleware both return on their first line so a doctor may hold as many concurrent sessions as they do today; that turning it on DENIES a second login rather than evicting the first; and that it refuses to arm at all unless the session driver is `database`, because on any other driver the sessions table is empty and liveness cannot be observed.
    'default' => false,
    'env_key' => 'FEATURE_DOCTOR_SINGLE_ACTIVE_SESSION',
    'owner' => 'rme',
    'risk_level' => 'critical',
    'rollout_status' => 'implemented',
    'review_target' => 'DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1',
    'dependencies' => [],
    'rollback_action' => 'Set the environment override to false and clear the config cache. The Login listener returns on its first line and the per-request middleware returns on its first line, so a second doctor login is admitted again immediately and no session is ever torn down for a lease reason. Rows in trx_doctor_session_leases are NOT deleted and NOT bulk-released: they are left exactly as they are, which is safe because nothing reads them while the flag is off, and correct because re-arming must not silently admit a session the audit trail says was already refused. On re-arming, an unreleased lease whose session no longer exists is reclaimed on that doctor next login through the ordinary dead-incumbent path, so there is no manual cleanup and nothing to migrate back. The flag only ever gated a decision; the one thing it wrote is inert without it.',
  ],

default => false is not optional: FLAG-RISKY-DEFAULT-OFF fails for any high/critical flag defaulting true (app/Services/Foundation/FeatureFlagService.php:127-132). env_key must be declared so the config-BUILD-time capture loop at config/feature_flags.php:452-458 injects env_value and the flag survives config:cache.

dependencies is deliberately EMPTY. Nothing in the codebase reads it — FeatureFlagService::enabled() (:52-57) reads only the hydrated `enabled` — so a declaration here would be decorative (ruling P7). Ruling P7's 'make the flag dependency real' applies to DoctorBranchLockResolver::enabled() requiring BOTH flags, which is the branch-lock slice. The single-session engine deliberately depends on nothing, because it must work while doctor.trusted_device_enforcement stays false (config/feature_flags.php:400, owner decision O5).

### EDIT app/Http/Controllers/Auth/AuthenticatedSessionController.php
PURPOSE: Release the lease on logout, and hand it off cleanly when a denied browser is sent to the WebAuthn ceremony.

TWO ANCHORS.

(1) destroy(): the exact anchor line is `Auth::guard('web')->logout();` at line 117. Insert immediately BEFORE it (i.e. after the markOffline block that closes at line 115):
      app(DoctorSessionLeaseService::class)->releaseCurrent($request, $user, DoctorSessionLease::REASON_LOGOUT);
  It must precede logout() because $request->user() and the session token are both still readable there, and it must follow markOffline (:114) so the two teardowns stay in the order the middleware also uses.

(2) store(): the exact anchor line is `$sessions->invalidate($request, $user, $denial);` at line 83. Insert immediately BEFORE it:
      app(DoctorSessionLeaseService::class)->releaseCurrent($request, $user, DoctorSessionLease::REASON_DEVICE_CEREMONY_HANDOFF);
  WHY. The Login listener already claimed during $request->authenticate() at :32; the device gate then denies at :51 and either parks the doctor for a WebAuthn assertion (:85-89) or throws (:91-93). Without this line the doctor walks away holding an unreleased lease whose session was just invalidated. It would self-heal — the ceremony's own session is written to `sessions` with user_id NULL (DatabaseSessionHandler::addUserInformation() at :204-211 writes the guard's id, which is null during the unauthenticated ceremony), so the probe sees no live row and the WebAuthn login at DoctorDeviceWebAuthnLoginService.php:348 reclaims a dead incumbent. Releasing explicitly turns a load-bearing four-step inference into one line and one honest RELEASED audit row instead of a RECLAIMED one.

BOTH EDITS ARE INSIDE THE DoctorDevice SOURCE SCAN (tests/Feature/DoctorDevice/DoctorDeviceAccessTest.php:210-215 and DoctorDeviceApiAndNoEnforcementTest.php:199-204 glob app/Http/Controllers/Auth/*.php and reject any /[A-Za-z_]*DoctorDevice[A-Za-z_]*/ identifier outside the four-item allowlist, and also forbid the literals 'DoctorDeviceProofService', 'DoctorDeviceAuthorization::' and "'doctor.trusted_device_enforcement'"). `DoctorSessionLeaseService` and `DoctorSessionLease` contain none of those substrings, so both edits pass. Do NOT name the new class DoctorDeviceSessionLeaseService.

### EDIT app/Http/Controllers/ProfileController.php
PURPOSE: Release the lease before an account is force-deleted, so the audit trail records why it ended.

ANCHOR: the line `Auth::logout();` at line 55, immediately after the markOffline block that closes at line 53. Insert before it:
      app(DoctorSessionLeaseService::class)->releaseCurrent($request, $user, DoctorSessionLease::REASON_ACCOUNT_DELETED);
The FK is cascadeOnDelete, so `$user->forceDelete()` at :59 would remove the row anyway — but silently. Releasing first writes the audit row while the lease still exists. This file is NOT in either DoctorDevice scan glob (they cover app/Http/Controllers/Auth/*.php only).

### NEW tests/Feature/DoctorDevice/DoctorSessionLeaseTest.php
PURPOSE: The proof. Placed in tests/Feature/DoctorDevice/ so the critical gate actually runs it on PostgreSQL.

PLACEMENT IS LOAD-BEARING. The critical filter selects by a path-derived test identity (app/Support/Cicd/SelfHostedRunnerScanner.php:520-541), and the `DoctorDevice` token at .github/workflows/foundation-evidence-gates.yml:422 matches the DIRECTORY segment, so `Tests\Feature\DoctorDevice\DoctorSessionLeaseTest` is selected. That matters because the critical job sets job-level DB_CONNECTION: pgsql (:315, :524) and phpunit.xml's <env> elements carry no force="true" (:20-32), so the exported value wins — the partial-index and savepoint proofs only ever execute there. Declaring the file in config/ci_runner.php:172 INSTEAD would FAIL the gate, not satisfy it: the scanner appends an issue for a declared suite that no token selects and checks every critical variant (SelfHostedRunnerScanner.php:481-500; corrected claim H1). Declaring it in ADDITION is optional and safe.

HELPERS the file needs:
  armLease(bool $on): rewrite the WHOLE feature_flags.flags array setting BOTH ['default'] and ['env_value'] on 'doctor.single_active_session' — copy tests/Feature/DoctorDevice/DoctorDeviceEnforcementGateTest.php:47-54 verbatim, because the flag KEY contains dots and config()->set('feature_flags.flags.doctor.single_active_session', true) builds a nested structure FeatureFlagService never reads.
  observableSessions(): config()->set('session.driver', 'database'). The sessions TABLE exists in every run because database/migrations/0001_01_01_000000_create_users_table.php:30-37 creates it and RefreshDatabase (tests/Pest.php:23) migrates it; only the DRIVER is array (phpunit.xml:30).
  liveIncumbentSessionRow(User $u): DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $u->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => '', 'last_activity' => now()->getTimestamp()]).

THIS IS THE ANSWER TO 'SESSION_DRIVER IS array IN TESTS'. Liveness is read from the sessions TABLE, not from the session driver, so a test builds an incumbent by inserting one row. No driver swap, no second browser, no fake needed.

CASES:
 1. behavioural proof the partial index still carries its WHERE clause — claim, release, claim again; assert two rows for the user and exactly one with released_at NULL. Schema::getIndexes() exposes name/unique/columns but not the predicate, so tests/Support/Database/SchemaFacts.php:55-64, :93-102 cannot see a flattened index; only behaviour can (BRIEF F6).
 2. a live incumbent denies a second POST /login: assertSessionHasErrors('email'), assertGuest(), and the incumbent lease row is byte-for-byte unchanged (released_at still null).
 3. THE remember_token TEST: snapshot users.remember_token before the denied login and assert it is identical after. This is the only thing that proves logoutCurrentDevice() (SessionGuard:680) and not logout() (:650 -> cycleRememberToken :657) — ruling P4.
 4. dead incumbent (no sessions row) is reclaimed: second login succeeds, lease 1 released with reason 'dead_incumbent', exactly one active lease, audit action DOCTOR_SESSION_LEASE_RECLAIMED.
 5. a live incumbent whose lease last_seen_at is hours old is STILL denied — pins ruling P3's 'no idle reclamation', so nobody reintroduces a TTL.
 6. an incumbent whose sessions.last_activity is older than config('session.lifetime') is DEAD and reclaimed — pins that the predicate matches DatabaseSessionHandler::expired() (:121-122).
 7. the middleware passes through an actingAs() doctor session with the flag ARMED and no lease token — the whole-suite mitigation (E5).
 8. actingAs() mints no lease at all (setUser at :996 fires Authenticated, not Login) — assert zero rows.
 9. middleware eviction after an out-of-band releaseFor(): next request redirects to route('login') with an 'email' error, and trx_user_online_contexts for that doctor is status offline with clinic_room_id NULL — proves markOffline ran BEFORE teardown (ruling P5).
10. expectsJson() eviction returns 403 JSON, not a redirect.
11. remember-me recaller: a request carrying only a valid recaller cookie while a live incumbent exists is denied AND the response queues a forget-cookie for the recaller name, so it cannot loop.
12. logout releases with reason 'logout' and the next login succeeds with a CLAIMED, not a RECLAIMED, audit row.
13. the engine is inert when config('session.driver') !== 'database' even with the flag armed — pins the refuse-to-arm decision rather than letting it fail open.
14. every audit row uses a DOCTOR_SESSION_LEASE_* action; assert none is 'DOCTOR_SESSION_DEVICE_INVALIDATED' (ruling P8).
15. a non-Doctor account is never leased.
16. THE POSTGRESQL SAVEPOINT PROOF: bind a DoctorSessionLeaseRepositoryInterface decorator whose first lockActiveForUser() returns null while a real active row exists in the table. The INSERT then raises 23505, the catch runs OUTSIDE the nested transaction after Laravel issued ROLLBACK TO SAVEPOINT, and the re-read succeeds instead of returning 25P02. Section M proved that without the savepoint this SELECT fails on PostgreSQL while passing on sqlite; this case is the only thing that keeps it honest. Note RefreshDatabase already holds an open transaction (tests/Pest.php:23), so the shape runs at savepoint depth 2/3 in tests versus 1/2 in production (gap G12) — state that in the test's comment rather than claiming parity.
17. the denied login neither hits nor clears the throttle: RateLimiter attempt count for the throttleKey is unchanged after a denial (LoginRequest.php:46, :53 are both skipped).
