# SLICE: Flags, manifest, seeders, permission grouping, governance/CI registration, docs, cursor rule, CLAUDE.md and deploy note for DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1. All citations verified in /home/fikri/Projects/doctor-access-single-session-branch-lock-1 @ 215d277d (read-only; no file created, modified or deleted; no artisan command run).

## Decisions
- FLAG KEYS AND SHAPE. Two NEW entries in config/feature_flags.php, inserted between line 421 (the close of `doctor.pwa_webauthn_device_login`) and line 423 (the FIX-04b comment): `doctor.single_active_session` (env_key FEATURE_DOCTOR_SINGLE_ACTIVE_SESSION, dependencies []) and `doctor.branch_lock` (env_key FEATURE_DOCTOR_BRANCH_LOCK, dependencies ['doctor.single_active_session']). Both default false, risk_level critical, owner rme, rollout_status implemented, review_target DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1, plus a multi-sentence rollback_action. Nine declared keys; the tenth required key, `enabled`, is synthesised by FeatureFlagService::hydrate (app/Services/Foundation/FeatureFlagService.php:207). No `env_value` is declared, so the config-BUILD capture loop at config/feature_flags.php:452-458 injects it and FLAG-ENV-CAPTURE (:139-148) passes under config:cache.
- THE DEPENDENCY IS ENFORCED IN CODE, NOT DECLARED. `DoctorBranchLockResolver::enabled()` returns `flags->enabled('doctor.single_active_session') && flags->enabled('doctor.branch_lock')`. Verified that nothing reads a flag's dependencies array: it appears at FeatureFlagService.php:25 (required metadata) and :202 (hydration), and the only other repo hits are package.json scans and one non-emptiness assertion (tests/Feature/Foundation/PgBouncerReadinessGovernanceTest.php:49). Direction chosen per ruling P7: branch lock without the lease is a branch that can change underneath an already-authenticated session, which owner decision O6/Q forbids outright.
- NEITHER FLAG ENTRY CONTAINS THE LITERAL `doctor.trusted_device_enforcement`, and the reason is stated honestly. The exact-reader pin at tests/Feature/DoctorDevice/DoctorDeviceEnforcementGateTest.php:497-518 scans base_path('app'), base_path('routes') and base_path('bootstrap') — NOT config/, which already carries the literal at :397 and :419. The real reasons are that gating on it would make the new rules dead code (it defaults false at config/feature_flags.php:400, and DoctorAppLoginGate::denySessionReason narrows again to the pilot cohort at :231-233), and that a dependencies entry naming it would be decorative for the reason above.
- MODULE NAMESPACE IS `App\Modules\DoctorAccess`, NOT `App\Modules\DoctorDevice`. Forced by two critical-gated contracts: DoctorDeviceApiAndNoEnforcementTest.php:246-278 requires any registered-route middleware whose FQCN contains `DoctorDevice` to be exactly EnsureDoctorDeviceSession, and DoctorDeviceAccessTest.php:210-244 / DoctorDeviceApiAndNoEnforcementTest.php:199-236 allow only four `DoctorDevice*` identifiers in app/Http/Controllers/Auth/*, app/Http/Middleware/*, app/Services/Auth/*, LoginRequest.php and bootstrap/app.php — which is where the new global middleware must be registered. The collision is on the namespace segment, so renaming the class alone does not save it.
- SPRINT TYPE IS SECURITY_FIX. It is the only audit-level-3 type that allows migrations (config/sprint_profiles.php:174-184, migration_allowed true) and carries max_modules 99, which this sprint needs: it touches DoctorAccess, Branch, ClinicVisit, RmeOnlineContext and DoctorDevice, and HOTFIX/RUNTIME_FIX/MODULE_SPRINT cap modules at 2/3/3 (config/sprint_profiles.php:49, :66, :83), which SprintScopeAuditor.php:49-51 enforces. MIGRATION_HEAVY would also validate but misnames the dominant risk.
- MANIFEST BOOLEANS. runtime_change true, schema_change true (two additive migrations), frontend_change FALSE with an explicit caveat, security_impact true, branch_isolation_impact true, ledger_impact false, deploy_required true, browser_required true. schema_change and security_impact are also forced by the diff check: SprintManifestValidator.php:139-148 matches `#^database/migrations/#`, and both `#Seeder\.php$#` and `#(RoleSeeder|PermissionSeeder)#`.
- THE FRONTEND CLAIM IS STATED AS A PROMISE THE VALIDATOR CANNOT CHECK. SprintManifestValidator.php:141-144 matches only `\.(js|jsx|ts|tsx|vue|css|scss)$` and vite.config/tailwind.config/package.json/package-lock.json — `.blade.php` matches NOTHING, so a Blade-only change neither forces nor is caught by `frontend_change`. The manifest declares false and says so in a comment, and states that it must be flipped to true the moment resources/js or resources/css is touched (the doctor online-context selector seeds an Alpine component from `$doctorAllowedBranches` at resources/views/rme/online-context/select.blade.php:41, so a UI slice may well need to).
- `full_required` IS DELIBERATELY OMITTED FROM test_profiles. It is a real identifier (config/sprint_profiles.php:220) and SprintTestPlanner.php:105-108 escalates the full suite when a manifest declares it — which .cursor/rules/107-global-temporary-full-suite-policy.mdc (ACTIVE, alwaysApply) forbids for an individual sprint. The manifest declares focused, security_regression, schema_regression and explains the omission in a comment. The outgoing manifest's `- security` matches no identifier at all; that is corrected rather than copied.
- THE INHERITED BLOCK IS CARRIED THROUGH VERBATIM — all six lines: inherits_closure LEGACY-RME-PROGRAM-CLOSURE-1, inherits_hold FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1/FIX-02, inherits_phase feature-doctor-trusted-android-device-lock-1-phase-3-5-readiness-go, inherits_parent_go doctor-pwa-webauthn-1-go, inherits_capability_go doctor-pwa-multi-doctor-pilot-1-capability-go, inherits_pilot_go doctor-pwa-multi-doctor-pilot-1-go. The closure name is a hard requirement: tests/Feature/LegacyRme/LegacyRmeProgramClosureContractTest.php:145-156 does a raw File::get on `.sprint/current.yml` and asserts toContain('LEGACY-RME-PROGRAM-CLOSURE-1'), and that suite is critical-gated by the `LegacyRme` token.
- GO TAG IS `doctor-access-single-session-branch-lock-1-go`. Matches config/devflow.php:65 `/^[a-z0-9]+(?:-[a-z0-9]+)*-go$/`. Not created by any command — sprint:release-check creates no tag.
- FOUR NEW PERMISSIONS: view_doctor_branch_locks, manage_doctor_branch_locks, approve_doctor_branch_locks, release_doctor_session_leases. All four are added to PermissionSeeder::PERMISSIONS (mandatory: three tests compare the grouped set to that constant with an exact whole-set toBe — RoleManagementTest.php:247, Sprint55…:173, Sprint53…:176 — and RoleSeeder.php:551 syncs Super Admin against it, so an unlisted name also throws in the seeder).
- SEPARATION OF DUTIES: Supervisor RME gets view + approve + release, and NOT `manage_doctor_branch_locks`. The filing permission is granted to no role, so only Super Admin reaches it via the single global Gate::before — the same posture as view_doctor_devices and view_developer_console, and the same maker/checker split RoleSeeder.php:470-484 already documents for the legacy-RME approver. Granting the filing permission to Admin Klinik is the obvious next step and is flagged as an OWNER DECISION deliberately not taken here.
- APPROVAL IS A NEW PERMISSION, NEVER A WIDENED GATE. tests/Feature/AccessControl/DailyBranchContextBypassTest.php:347-362 asserts by name that Supervisor RME (and every other non-super-admin role) must NOT hold `branch-change-request.approve`, and :364-372 pins the approver menu out of a non-super-admin sidebar. That suite is critical-gated by the `DailyBranchContext` token, so widening the existing gate reddens CI immediately.
- PERMISSION GROUPING: all four go into the existing `access_control` group (app/Modules/AccessControl/Services/PermissionGroupingService.php:28-37, insert after line 35). Chosen over leaving them unclassified — which is what the sibling doctor-device family does, and which the tests also tolerate via the Other fallback — because who may hold a session is an access-control fact and the direction of travel in this codebase is classification. The existing doctor-device family is deliberately NOT reclassified.
- CI REGISTRATION IS AN AND, NOT AN OR. One new token `DoctorAccess` inserted after `|DoctorDevice` in BOTH critical filters (.github/workflows/foundation-evidence-gates.yml:422 and :690 — they must stay byte-identical per DedicatedSelfHostedRunnerTest.php:173-192), AND seven suites declared in config/ci_runner.php critical_gate_mandatory_suites before line 625. SelfHostedRunnerScanner.php:481-500 emits an issue for a declared suite that no filter selects, and :477-479 for a declared file that does not exist.
- NEW TESTS LIVE IN tests/Feature/DoctorAccess/. The critical filter matches a path-derived identity (SelfHostedRunnerScanner::testIdentity at :520-541 builds `Tests\Feature\DoctorAccess\<Class>`, matched case-insensitively at :487), so the DIRECTORY segment is what the new token selects. This matters concretely: the authoritative critical gate exports DB_CONNECTION: pgsql at job level while phpunit.xml's env elements carry no force="true", so the partial-unique-index proof only ever runs on PostgreSQL if the token selects the suite.
- THE DAILY BRANCH LOCK IS LEFT ALONE. Doctor is NOT added to DailyBranchContextService::LOCKED_ROLE_CONTEXTS, and no doctor row is written to trx_daily_branch_contexts. DailyBranchContextLockTest.php:219-227 pins that constant with toEqualCanonicalizing plus an explicit isLockedRoleContext(ROLE_DOCTOR) false, and it stays GREEN as the proof the daily lock was untouched. What is reused is the PATTERN (transaction + row lock + savepoint + fail-closed re-validation), not the table.
- CURSOR RULE IS NUMBERED 153. Verified 93 already collides twice (93-doctor-pwa-webauthn.mdc, 93-legacy-rme-void-clinical-read.mdc), the highest existing number is 152, and no file starts with 153. No test scans rule numbering, so this is discipline rather than a gate.
- THE RUNBOOK IS NOT REGISTERED in config/enterprise_documentation.mandatory_runbooks, and the doc says so. EnterpriseDocumentationScanner.php:46-48 reads only that registry, so an unregistered runbook is neither scanned nor required; registering it would import the required-sections and forbidden-destructive-pattern contract for no benefit this sprint.
- NO GOVERNANCE SECTION IS PUBLISHED into architecture:foundation-governance-summary by this sprint, so config/release_evidence.php:142-154 (forbidden_patterns including the environment-file literal, and forbidden_regex /\d{16}/) constrains nothing this slice writes. It is recorded as a constraint on the runtime slice should it ever add one.

## Depends on
- RUNTIME SLICE owns app/Modules/DoctorAccess/Services/DoctorBranchLockResolver.php beyond enabled(). This slice specifies only the two flag constants and the enabled() body that enforces the dependency. If the runtime slice names the class differently, config/feature_flags.php's rollback_action prose for doctor.branch_lock names `DoctorBranchLockResolver::enabled()` explicitly and must be updated with it.
- RUNTIME SLICE must keep the module namespace free of the substring `DoctorDevice` and every new file free of the literal 'doctor.trusted_device_enforcement'. Both are pinned by critical-gated source scans (DoctorDeviceAccessTest.php:210-244 incl. bootstrap/app.php; DoctorDeviceApiAndNoEnforcementTest.php:246-278; DoctorDeviceEnforcementGateTest.php:497-518).
- TEST SLICE must place every new suite under tests/Feature/DoctorAccess/ and must author exactly the seven files declared in config/ci_runner.php: DoctorAccessFeatureFlagContractTest, DoctorSessionLeaseCardinalityTest, DoctorSessionLeaseDenyNotEvictTest, DoctorBranchLockResolutionTest, DoctorBranchCoverLifecycleTest, DoctorBranchLockApprovalAuthorityTest, DoctorBulkDeviceAuthorizationTest. A declared file that does not exist FAILS the gate (SelfHostedRunnerScanner.php:477-479), so any rename must be mirrored in config/ci_runner.php in the same commit.
- TEST SLICE must repin the four tests this sprint's semantics reverse, and REWRITE rather than delete them: DailyBranchContextLockTest.php:197, and DoctorRmeBranchSourceTest.php:272, :279, :298, :311. The latter file matches no critical-filter token, so its breakage surfaces only in a full-suite run and must be repinned deliberately rather than discovered later.
- UI SLICE decides the manifest's frontend_change boolean. If it edits resources/js or resources/css (the doctor online-context selector seeds an Alpine component from $doctorAllowedBranches at resources/views/rme/online-context/select.blade.php:41), flip frontend_change to true — the validator will not catch it either way.
- MIGRATION SLICE owns the two additive migrations. The partial unique index MUST be created by DB::statement in the SAME migration that creates trx_doctor_session_leases, unguarded (the precedent is database/migrations/2026_08_29_100002_create_trx_branch_change_requests_table.php:26 for the rationale and :88-93 for the unguarded index itself).
- OWNER DECISION STILL OPEN, deliberately not taken here: whether manage_doctor_branch_locks (the FILING permission) should be granted to Admin Klinik. It is currently granted to no role, so only Super Admin can file a request. If the owner grants it, RoleSeeder changes and SupervisorRmeRolePermissionTest does NOT (the const only pins Supervisor RME).
- DEPLOY NOTE — RUN ON THE VPS, IN THIS ORDER, FROM /var/www/asia-dental-lab-v2, INSIDE the VPS (scripts/deploy-vps-runner.sh is designed to run ON the host per its usage header at scripts/deploy-vps-runner.sh:17-26): (1) `bash scripts/deploy-vps-runner.sh start` then `follow` then `status` — the script takes the pre-deploy pg_dump backup itself, verifies it with foundation:backup-verify, runs `php artisan migrate --force` (scripts/deploy-vps.sh:264), clears route+config cache BEFORE the route-dependent governance gates (:275-276, the ENT-8 ordering), runs the gates, then rebuilds cache as the PHP-FPM runtime user (:356-361) and runs `deploy:auth-landing-smoke --strict` (:369). GO only on exit=0. (2) POST-DEPLOY, as the runtime user, in this exact order because RoleSeeder syncs Super Admin against PermissionSeeder::PERMISSIONS (RoleSeeder.php:551) and Spatie throws on an unknown name: `php artisan db:seed --class=PermissionSeeder --force`, then `php artisan db:seed --class=RoleSeeder --force`, then `php artisan permission:cache-reset`. (3) VERIFY THE FLAGS ARE STILL OFF: `php artisan foundation:feature-flags --json` and confirm doctor.single_active_session and doctor.branch_lock both report enabled=false and env_captured=true, and that the governance decision is GO. (4) VERIFY THE STANDING POSTURE IS UNMOVED: `php artisan android:release-readiness` and `php artisan doctor:rollout-readiness --json`; enforcement stage must still be off, global_permitted false, cohort [9,15,18]. (5) OPTIONALLY run the bulk device-authorization command in DRY-RUN and read the printed delta; APPLY only after explicit operator approval of the exact row counts. WHAT MUST NOT RUN: never `migrate:fresh`, never `db:wipe`, never `migrate:reset`, never `schema:drop`; never `php artisan tinker` or any REPL — it is refused at CommandStarting (config/release_safety.php:137-139) and also pins the monitoring log signal to WATCH for 24h; never set FEATURE_DOCTOR_SINGLE_ACTIVE_SESSION or FEATURE_DOCTOR_BRANCH_LOCK during the deploy window (arming is a separate supervised activation with the owner present); never hand-edit a lease, lock or cover row; never create doctor-pwa-global-rollout-readiness-1-go; never touch config/android_release.php.
- WHAT AUTOMATED TESTS CANNOT PROVE — MUST BE PROVEN BY THE REAL-DEVICE CEREMONY. (a) THAT A SECOND LOGIN IS DENIED WHILE THE FIRST SESSION KEEPS WORKING. The whole suite runs SESSION_DRIVER=array in one process (phpunit.xml:30, workflow :323 and :545), and the existing 'two browsers' tests fake the second party by snapshotting session()->all(), flushing and replacing it (DoctorPwaWebAuthnCredentialRevocationTest.php:165-190, :217-229) — a helper that logs the subject out, so it can only prove containment it caused. Two genuinely separate clients on separate hardware are required: log in on the tablet, attempt the office PC, observe the refusal, then perform a real protected request on the TABLET and confirm it still works. (b) THAT THE DENY PATH DOES NOT BREAK THE FIRST SESSION'S REMEMBER-ME. `users.remember_token` is shared; only a real recaller cookie surviving a real denial proves logoutCurrentDevice() was used and not logout(). (c) DEAD-SESSION RECLAMATION AFTER A NORMAL HANDOVER. The `sessions` table is empty in every test run, so the branch that distinguishes a dead incumbent from a live one has no production-shaped fixture. Finish on the ward tablet, walk to the office PC, log in, and confirm no lockout and no 30-minute wait. (d) THAT COVER EXPIRY ENDS THE SESSION WITH NO SCHEDULER RUNNING. A test can travel time; only a real shift crossing a real ends_at with the queue worker deliberately stopped proves correctness does not depend on cron. (e) THAT AN UNSET DOCTOR IS UNCHANGED IN THE LIVE CLINIC. UNSET is the deploy-day state for all 15 doctors, and the assertion that matters is a real clinician completing a real visit with no new refusal — a green suite cannot say that. (f) THAT A LOCKED DOCTOR SEES ONLY THEIR BRANCH FROM ANOTHER BRANCH'S TABLET. Requires the physical cross-branch tablet: lock the doctor to SPN4, log in on the LDK2 tablet, confirm the SPN4 list and confirm a forged branch write is refused. (g) THAT THE BULK AUTHORIZATION DELTA IS THE FLEET THE OPERATOR EXPECTS. The command can prove idempotency and zero duplicates; only a human comparing DOCTORS=15 / TRUSTED_DEVICES=3 / the proposed row count against the real estate can confirm the numbers describe reality — the same limit that made the 'three of fifteen ready' finding a human one. (h) THAT A DOCTOR WAS ACTUALLY PRESENT. A shared tablet's biometric attests that the DEVICE was unlocked, not which clinician stood in front of it; the server cannot distinguish a browser assertion from an installed-app one (byte-identical user agents), so per-device browser and app attestation stays operator-attested. (i) THAT THE PARTIAL UNIQUE INDEX KEPT ITS PREDICATE ON PRODUCTION. Schema::getIndexes() does not expose a WHERE clause (SchemaFacts.php:55-64, :93-102), so the local proof is behavioural and the production proof is a real second login after a real release.

## Risks
- EVERY GOVERNANCE / ARCHITECTURE CONTRACT THIS SPRINT CAN REDDEN, and what satisfies each. (1) tests/Feature/Foundation/FeatureFlagFoundationTest.php:15-24 — ten metadata keys per flag; satisfied by declaring nine and letting hydrate() add `enabled`. (2) FeatureFlagService.php:126-132 FLAG-RISKY-DEFAULT-OFF + FeatureFlagFoundationTest.php:73-80 (`foundation:feature-flags` must return GO) — satisfied by default false on both critical flags. (3) FeatureFlagService.php:139-148 FLAG-ENV-CAPTURE — satisfied by declaring env_key and NOT declaring env_value. (4) FeatureFlagFoundationTest.php:83-90 counts flags against config; self-consistent, safe. (5) tests/Feature/LegacyRme/LegacyRmeProgramClosureContractTest.php:145-156 — the manifest must still contain LEGACY-RME-PROGRAM-CLOSURE-1. (6) app/Support/Devflow/SprintManifestValidator.php:139-155 manifest-vs-diff — schema_change and security_impact must be true. (7) SprintManifestValidator.php:71-74 go_tag regex, :118-121 SECURITY_FIX warning. (8) tests/Feature/Foundation/DevflowSprintToolingTest.php:221-224 — `sprint:manifest-check --no-diff-check` must exit 0, i.e. zero manifest errors. (9) tests/Feature/Auth/SupervisorRmeRolePermissionTest.php:16-95 const + :110 sorted whole-set toBe — repin +3. (10) RoleManagementTest.php:247, Sprint55…:173, Sprint53…:176 — new permissions MUST be in PermissionSeeder::PERMISSIONS. (11) DailyBranchContextBypassTest.php:347-362 — do not widen `branch-change-request.approve`. (12) DailyBranchContextLockTest.php:219-227 — do not add Doctor to LOCKED_ROLE_CONTEXTS. (13) DoctorDeviceEnforcementGateTest.php:497-518 — no new file under app/, routes/ or bootstrap/ may contain 'doctor.trusted_device_enforcement'. (14) DoctorDeviceAccessTest.php:210-244 and DoctorDeviceApiAndNoEnforcementTest.php:199-236 — the four-symbol allowlist over the auth surfaces INCLUDING bootstrap/app.php. (15) DoctorDeviceApiAndNoEnforcementTest.php:246-278 — the route middleware contract. (16) DedicatedSelfHostedRunnerTest.php:173-192 — both critical filters byte-identical; :694-701 — every critical filter contains `Cicd`. (17) SelfHostedRunnerScanner.php:477-500 — declared-suite existence and selection. (18) config/ci_runner.php:657 — expected_warning_count is 0; new tests must emit no PHPUnit warnings. (19) FullSuiteBaselineContractTest.php:69-110/:112-130/:133-176 — no expected-failure token, no `content())->toContain($var)`, and e() around the dynamic half of an unescaped assertSee. (20) PwaServiceWorkerCachePolicyTest.php:33-50 — new routes must stay non-cacheable; the allowlist is deny-by-default so they do, provided CACHE_ALLOWLIST is not widened. (21) AdminLabLabOnlyAccessTest.php:175-176 — grant nothing new to Admin Lab. (22) RolePermissionHardeningTest.php:62-68 — grant nothing new to Doctor. (23) DoctorDeviceAndroidReleaseGovernanceTest.php:631-636 and DoctorDeviceAndroidRuntimeIdentityReadinessTest.php:482-485 — `toContain` on critical_gate_mandatory_suites, so appending is safe. (24) DoctorDeviceAndroidRuntimeIdentityReadinessTest.php:447, :478-480 — `doctor.trusted_device_enforcement` default false, android_release enforcement.current_stage 'off', owner_signoff.pilot_activated false; leave config/android_release.php untouched. (25) RepositoryArtifactHygieneTest.php:53, :90 — no SQLite artifact committed.
- WHAT I COULD NOT FIND, STATED AS ABSENT. There is NO global registry of permitted audit action strings — `DOCTOR_SESSION_DEVICE_INVALIDATED` is a bare literal at app/Modules/DoctorDevice/Services/DoctorDeviceSessionService.php:194 and the only AuditLogService in app/ is app/Modules/LabOrder/Services/AuditLogService.php, which carries no ACTION allowlist. So ruling P8 (emit lease events under their own action) is a discipline enforced only by the new tests, not by an existing gate. Also ABSENT: any test that pins the flag COUNT or the exact flag key list (searched tests/ for toHaveCount and count(config('feature_flags…))); any scanner over .cursor/rules numbering (only the ENT suites and LegacyRmeProgramClosureContractTest read specific rule paths by name); and tests/Feature/Auth/RolePermissionHardeningTest.php contains no exact-list role pin — the ONLY exact whole-role pin in the suite is SupervisorRmeRolePermissionTest.
- THE SUPERVISOR RME REPIN IS NOT COVERED BY THE CRITICAL GATE. Its identity `Tests\Feature\Auth\SupervisorRmeRolePermissionTest` matches no token in either critical filter (I read the full token list at .github/workflows/foundation-evidence-gates.yml:422 — there is no Permission, Auth or SupervisorRme alternative). It IS selected by the CICD-CTRL Selective Module Gate at :900 (`--filter='Permission|AccessControl'`), which runs because scripts/ci/resolve-gates.sh:210 classifies database/seeders/*Permission*|*Role* as permissions_security and :301 forces RUN_PERMISSION_TESTS=true. So coverage exists but is conditional on a seeder being in the diff. Do not claim critical-gate coverage for it. Promoting it into critical_gate_mandatory_suites would also require a new filter token for a suite this sprint did not author, which is why it is not done here.
- MANIFEST FRONTEND FLAG IS THE ONE BOOLEAN THE VALIDATOR CANNOT POLICE. `.blade.php` matches neither the frontend pattern nor the security pattern in SprintManifestValidator::checkDiffContradictions (:139-155). If a sibling slice edits resources/js/app.js for the doctor online-context Alpine component, `frontend_change` must be flipped to true by hand — CI will not catch the lie in either direction.
- THE PARTIAL UNIQUE INDEX IS NOT DETECTABLE BY SCHEMA INSPECTION. tests/Support/Database/SchemaFacts.php:55-64 and :93-102 expose name/unique/columns from Schema::getIndexes() but NOT the WHERE predicate, so a flattened index (UNIQUE(user_id) with the predicate silently dropped) reads as present. It must be proven BEHAVIOURALLY — release a lease and claim again — and the failure mode is the dangerous direction: a flattened index makes the lease permanently unreleasable, so a doctor could never log in a second time, and only in the local suite. Create the index in the SAME migration as the table.
- EVERY TEST RUNS INSIDE AN OPEN TRANSACTION. tests/Pest.php:23 applies RefreshDatabase to the whole suite, so no cross-connection race is expressible: lockForUpdate is untestable, the unique index can only be proven by inserting a conflicting row directly, and the nested-transaction savepoint runs at depth 2/3 in tests versus 1/2 in production. The 25P02 recovery path is therefore exercised at a different nesting level than it will run at.
- SESSION_DRIVER IS `array` IN EVERY TEST RUN — phpunit.xml:30 (note: :29 is QUEUE_CONNECTION, an off-by-one in the earlier brief) and both CI jobs at .github/workflows/foundation-evidence-gates.yml:323 and :545 — while config/session.php:21 defaults to `database` and production runs `database`. The `sessions` table is therefore EMPTY in every test on both drivers, so the dead-session reclamation branch (ruling P3, the routine ward-tablet-to-office-PC handover) cannot be proven in CI at all. It must be either faked by writing a `sessions` row directly in a test and asserting the reclamation decision, or listed as a real-device ceremony item — preferably both.
- IF THE RUNTIME SLICE ADDS A GOVERNANCE SECTION, its rule text must contain neither the environment-file literal nor any 16-digit run (config/release_evidence.php:147 and :152-154), or the NSF-10 release evidence gate fails the moment the rules array is captured into foundation-governance-summary.json. This slice publishes no such section.
- A PRE-EXISTING DEFECT FOUND WHILE VERIFYING, OUT OF SCOPE, REPORT ONLY: three sites catch a QueryException inside DB::transaction and then run further queries — app/Modules/RME/Services/PatientDoctorAssignmentService.php:48 (inside the transaction opened at :25) and :172 (inside :137, then firstOrFail()), and app/Modules/MedicalRecord/Services/DiagnosisRolloutService.php:127 (inside :111, which then also issues an UPDATE). On PostgreSQL the transaction is already aborted and the follow-up returns 25P02; on SQLite it appears to work. They only misbehave when the race they exist to handle actually fires, which is why the suite is green. Do NOT widen this sprint to fix them.

## Files

### EDIT config/feature_flags.php
PURPOSE: Register the two NEW critical flags with the full ten-key metadata the registry contract demands, both default false, and declare the ONE dependency that is actually enforced in code.

ANCHOR: the `doctor.pwa_webauthn_device_login` definition opens at config/feature_flags.php:410 and its closing `],` is line 421; line 422 is blank; line 423 is `// --- FIX-04b — legacy ODONTOGRAM chart archive (runtime shipped, stays OFF) ---`. Insert the block below between line 421 and line 423.

WHY EACH KEY IS PRESENT: FeatureFlagService::REQUIRED_METADATA (app/Services/Foundation/FeatureFlagService.php:17-26) and tests/Feature/Foundation/FeatureFlagFoundationTest.php:20 both demand name, description, default, env_key, owner, risk_level, rollout_status, dependencies, rollback_action, enabled. `enabled` is NOT declared — hydrate() synthesises it at :207. `review_target` is optional (:200) and is declared to match the two sibling doctor flags. `env_value` is NOT declared: the config-BUILD capture loop at config/feature_flags.php:452-458 injects it for any definition carrying a non-empty `env_key` and no explicit `env_value`, which is what makes FLAG-ENV-CAPTURE (FeatureFlagService.php:139-148) pass under `config:cache`. Declaring `env_value` by hand would opt out of that capture. `risk_level => 'critical'` with `default => false` is mandatory: FLAG-RISKY-DEFAULT-OFF (:126-132) FAILs the whole registry on a risky default of true, and `foundation:feature-flags` must return GO (FeatureFlagFoundationTest.php:73-80).

NEITHER ENTRY CONTAINS THE LITERAL `doctor.trusted_device_enforcement`. Note honestly WHY, because the obvious reason is wrong: the exact-reader pin at tests/Feature/DoctorDevice/DoctorDeviceEnforcementGateTest.php:497-518 walks base_path('app'), base_path('routes') and base_path('bootstrap') ONLY — config/ is not scanned, and config/feature_flags.php already carries that literal at :397 and :419. The real reasons are (a) gating on it would make the new rules dead code, since it defaults false at :400 and `denySessionReason` narrows again to the pilot cohort (DoctorAppLoginGate.php:231-233), and (b) a `dependencies` entry naming it would be decorative — see the enforcement note below.

BLOCK TO INSERT (verbatim; note the prose deliberately contains no apostrophes inside the single-quoted PHP strings, no environment-file-name substring — config/release_evidence.php:147 bans that literal from captured governance text — and no 16-digit run, banned by config/release_evidence.php:152-154):

        // --- DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 —
        //     one authenticated doctor session, and a permanent clinical branch
        //     with approved temporary cover. Both capabilities ship OFF. ---

        'doctor.single_active_session' => [
            'name' => 'Doctor Single Active Session',
            'description' => 'ENFORCEMENT switch for one authenticated session per doctor. A lease is claimed on the framework Login event rather than inside a login controller, because a remember-me recaller cookie re-authenticates a browser through no controller at all, and it is re-validated on every protected request. A SECOND login is DENIED and the FIRST is NEVER evicted, so the clinician already seeing patients keeps working and the person arriving second is told where the live session is. Eviction happens for exactly one reason: an approved permanent branch transfer, which releases the lease inside the same transaction that moves the branch. An incumbent whose server-side session row no longer exists is DEAD and is reclaimed at once, so a doctor who finished on the ward tablet and walked to the office PC is never locked out; an incumbent whose session is alive is refused however idle it is, because reclaiming on an idle timer is eviction by the back door and would invert the whole rule. WHILE THIS FLAG IS OFF NOTHING IS CLAIMED AND NOTHING IS DENIED: the claim listener returns before its first query and the per-request check returns on its first line, so a doctor holds as many sessions as they could before this sprint. Turning it on can refuse a login, so it belongs to a supervised activation window on real hardware, never to a deploy.',
            'default' => false,
            'env_key' => 'FEATURE_DOCTOR_SINGLE_ACTIVE_SESSION',
            'owner' => 'rme',
            'risk_level' => 'critical',
            'rollout_status' => 'implemented',
            'review_target' => 'DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1',
            'dependencies' => [],
            'rollback_action' => 'Set the environment override to false and clear the config cache. The claim listener and the per-request revalidation both return immediately, so no login is refused and no session is torn down: a doctor denied a second session a moment ago can open one now, and every session that is already open stays open. NO DATA IS TOUCHED. Existing lease rows stay exactly as they are, and the partial unique index still permits at most one unreleased lease per user, which is harmless while nothing claims one. Re-enabling therefore needs no cleanup: a stale lease whose server-side session row is gone is reclaimed by the next claim, and the escape hatch for a lease genuinely stuck behind a live session is the audited release action on the approver surface, gated by release_doctor_session_leases, never a manual UPDATE. This flag never writes a branch, a device, an authorization or a credential, so nothing has to be migrated back.',
        ],

        'doctor.branch_lock' => [
            'name' => 'Doctor Home Branch Lock and Approved Temporary Cover',
            'description' => 'ENFORCEMENT switch for the doctor clinical branch: a permanent HOME_LOCKED_BRANCH, an optional APPROVED TEMPORARY COVER carrying explicit starts_at and ends_at, and an EFFECTIVE_CLINICAL_BRANCH that is the active cover when there is one and the home lock otherwise. Every doctor starts UNSET and there is NO BACKFILL, because production carries no authoritative per-doctor branch and an inferred one would be a guess recorded as a fact. While a doctor is UNSET, branch selection, operational lists and writes behave exactly as they did before this sprint: UNSET is the compatibility state, not missing data. Once SET the doctor cannot self-switch, the operational lists and the visit-creation write path both resolve through the effective branch, and the physical tablet branch decides nothing. Historical archive reads stay cross-branch, so a doctor keeps reading the history a patient made at another branch. Expiry is resolved from current timestamps on every read and never by a scheduled job, so a delayed worker can never leave an expired cover effective. THIS FLAG ARMS NO DEVICE ENFORCEMENT and cannot refuse a login by itself. Turning it on changes what a SET doctor may see and write, so it belongs to a supervised window after at least one lock has been approved through the canonical screen.',
            'default' => false,
            'env_key' => 'FEATURE_DOCTOR_BRANCH_LOCK',
            'owner' => 'rme',
            'risk_level' => 'critical',
            'rollout_status' => 'implemented',
            'review_target' => 'DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1',
            'dependencies' => ['doctor.single_active_session'],
            'rollback_action' => 'Set the environment override to false and clear the config cache. The branch resolver stops consulting the lock, so every doctor resolves a working branch exactly as they did before this sprint and every operational list widens back to the legacy behaviour at once, including for doctors whose lock is SET. The write-path assertion stops firing with it, so a visit may again be created wherever the pre-sprint rules allowed. NO DATA IS TOUCHED: home locks, approved covers, pending requests and the entire approval history stay exactly as they are, so re-enabling needs no re-approval and no re-entry. It logs nobody out by itself, because session invalidation on cover start, on cover expiry and on permanent transfer is the lease mechanism, which is a separate flag. That is exactly why this capability refuses to arm unless the lease is armed too, and why the refusal lives in DoctorBranchLockResolver::enabled() instead of only in the dependencies array above: nothing in the registry reads a dependency, so a declaration on its own would be decorative. Nothing has to be migrated back, because the flag only ever gated a decision - it never wrote one.',
        ],

THE DEPENDENCY IS ENFORCED, NOT DECLARED. Verified: `dependencies` is read in exactly two places, FeatureFlagService.php:25 (the required-metadata list) and :202 (hydration). A repo-wide search for a consumer of the hydrated value found none in app/ — the only other hits are package.json dependency scans (app/Console/Commands/ArchitectureUiGovernanceCheckCommand.php:1242, tests/Feature/Ui/PerformanceAssetWeightUixTest.php:36) and one presence assertion (tests/Feature/Foundation/PgBouncerReadinessGovernanceTest.php:49 asserts a flag dependencies array is non-empty, nothing more). So the array is documentation; the code in DoctorBranchLockResolver::enabled() is the contract.

### NEW app/Modules/DoctorAccess/Services/DoctorBranchLockResolver.php
PURPOSE: The single reader of both flags, and the place the declared dependency is actually enforced. Shared with the runtime slice; this slice specifies only enabled() and the flag constants.

NAMESPACE IS LOAD-BEARING AND MUST NOT BE `App\Modules\DoctorDevice`. Two critical-gated contracts forbid it: (1) tests/Feature/DoctorDevice/DoctorDeviceAccessTest.php:210-244 and tests/Feature/DoctorDevice/DoctorDeviceApiAndNoEnforcementTest.php:199-236 glob app/Http/Controllers/Auth/*.php, app/Http/Middleware/*.php, app/Services/Auth/*.php, bootstrap/app.php and app/Http/Requests/Auth/LoginRequest.php, run preg_match_all('/[A-Za-z_]*DoctorDevice[A-Za-z_]*/') and require every hit to be in ['DoctorDevice','DoctorAppLoginGate','DoctorDeviceSessionService','EnsureDoctorDeviceSession'] — bootstrap/app.php is where the new global middleware is registered; (2) DoctorDeviceApiAndNoEnforcementTest.php:246-278 iterates every registered route and asserts any gathered middleware entry containing the substring 'DoctorDevice' is exactly EnsureDoctorDeviceSession::class. `DoctorAccess` does not match `[A-Za-z_]*DoctorDevice[A-Za-z_]*`, so it is safe in both. Both suites are selected by the `DoctorDevice` token in the critical filter (.github/workflows/foundation-evidence-gates.yml:422 and :690).

FILE CONTENT (the part this slice owns):

<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Services;

use App\Services\Foundation\FeatureFlagService;

final class DoctorBranchLockResolver
{
    public const FLAG_SINGLE_ACTIVE_SESSION = 'doctor.single_active_session';

    public const FLAG_BRANCH_LOCK = 'doctor.branch_lock';

    public function __construct(private readonly FeatureFlagService $flags) {}

    /**
     * The dependency declared on doctor.branch_lock, ENFORCED.
     *
     * Nothing in the registry reads a flag dependencies array: it is required
     * metadata (FeatureFlagService.php:25) and hydrated (:202), and no caller
     * consumes it. A declaration alone is therefore decorative. Branch lock
     * without the lease is a branch that can change underneath an already
     * authenticated session, which is the one outcome the cover design forbids,
     * so the two arm together or not at all.
     */
    public function enabled(): bool
    {
        return $this->flags->enabled(self::FLAG_SINGLE_ACTIVE_SESSION)
            && $this->flags->enabled(self::FLAG_BRANCH_LOCK);
    }
}

CONSTRAINT FOR THE RUNTIME SLICE: no file under app/Modules/DoctorAccess/ may contain the literal 'doctor.trusted_device_enforcement' — DoctorDeviceEnforcementGateTest.php:517 pins the reader list to exactly ['app/Modules/DoctorDevice/Services/DoctorAppLoginGate.php'] and the scan covers all of app/. If the new code ever needs enforcement state it must ask DoctorAppLoginGate::enforcementEnabled() (DoctorAppLoginGate.php:109-112).

### NEW tests/Feature/DoctorAccess/DoctorAccessFeatureFlagContractTest.php
PURPOSE: Pins the flag registry contract for this sprint and proves the dependency is enforced rather than declared. Lives in tests/Feature/DoctorAccess/ so the NEW `DoctorAccess` critical-filter token selects it by namespace segment.

Identity is path-derived (app/Support/Cicd/SelfHostedRunnerScanner.php:520-541 builds `Tests\Feature\DoctorAccess\DoctorAccessFeatureFlagContractTest`), so the directory segment `DoctorAccess` is what the token matches.

HELPER — arm a flag by rewriting the WHOLE flags array. Copy the shape and the rationale from tests/Feature/DoctorDevice/DoctorDeviceEnforcementGateTest.php:36-53: the flag KEY contains a dot, so config()->set('feature_flags.flags.doctor.branch_lock', ...) silently builds a nested doctor => branch_lock structure FeatureFlagService never reads and the test passes with the flag OFF. Set BOTH `default` and `env_value`:

function armDoctorAccessFlag(string $key, bool $on): void
{
    $flags = config('feature_flags.flags', []);
    $flags[$key]['default'] = $on;
    $flags[$key]['env_value'] = $on;
    config()->set('feature_flags.flags', $flags);
}

CASES:
1. 'both flags are registered, critical, and default false' — assert config('feature_flags.flags')['doctor.single_active_session']['default'] is false and ['risk_level'] is 'critical'; same for 'doctor.branch_lock'. Guards FLAG-RISKY-DEFAULT-OFF.
2. 'both flags capture their override at config build time' — app(FeatureFlagService::class)->get($key)['env_captured'] is true for both. Guards FLAG-ENV-CAPTURE (FeatureFlagService.php:139-148), which FAILs the registry for a declared env_key with no build-time capture.
3. 'the branch lock declares its dependency on the lease' — expect(app(FeatureFlagService::class)->get('doctor.branch_lock')['dependencies'])->toBe(['doctor.single_active_session']).
4. 'branch lock cannot arm without the lease' — arm ONLY 'doctor.branch_lock'; expect(app(DoctorBranchLockResolver::class)->enabled())->toBeFalse(). This is the case that would pass on a declaration-only dependency and fail on nothing else.
5. 'branch lock arms when both are armed' — arm both; enabled() is true.
6. 'lease alone does not arm the branch lock' — arm only the lease; enabled() is false.
7. 'no DoctorAccess source reads the device enforcement flag' — RecursiveIteratorIterator over base_path('app/Modules/DoctorAccess'), assert no .php file contains the string "'doctor.trusted_device_enforcement'". Mirrors DoctorDeviceEnforcementGateTest.php:497-518 locally so the module cannot drift into a second enforcement authority.
8. 'the flag registry still returns GO' — expect(app(FeatureFlagService::class)->validateGovernance()['summary']['decision'])->toBe('GO'). Duplicates FeatureFlagFoundationTest.php:73-80 deliberately, so a malformed entry fails inside this sprint suite rather than only in a sibling one.

FULL-SUITE BASELINE SHAPES TO AVOID in this and every new test file (tests/Feature/Cicd/FullSuiteBaselineContractTest.php, itself critical-gated by `Cicd`): no token from the forbidden list at :77-84 ('expected_failures', 'known_failures', ...) anywhere in the file (:69-110); never `expect($response->content())->toContain($var)` (:112-130); any `assertSee($model->name, false)` must wrap the value in e() (:133-176).

### EDIT .sprint/current.yml
PURPOSE: Rewrite the ROLLING manifest for this sprint with honest booleans, carrying every inherited block through verbatim.

COMPLETE REPLACEMENT FILE:

### Active sprint manifest — DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1
### Validated by: php artisan sprint:manifest-check

id: DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1
type: SECURITY_FIX
module: DoctorAccess
title: "A doctor may authenticate from any approved active clinic tablet whatever branch owns it, holds at most one authenticated session at a time with the second login denied and the first never evicted, and carries a permanent home clinical branch that starts UNSET plus optional approved temporary cover with explicit start and end times; operational lists and writes follow the effective branch while archive reads stay cross-branch"
base_branch: feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report

runtime_change: true            # a new module (DoctorAccess), a Login-event lease
                                # claim, a globally appended per-request middleware,
                                # a first link in the branch resolver, an approval
                                # service, and a write-path assertion in
                                # ClinicVisitService::resolveBranchId()
schema_change: true             # two additive migrations: trx_doctor_session_leases
                                # (with its partial unique index created in the SAME
                                # migration as the table) and the doctor branch lock
                                # plus temporary cover tables. No backfill of any kind.
frontend_change: false          # no JS, no CSS, no Vite/build file. Blade changes ARE
                                # expected (the doctor online-context selector), and the
                                # validator's frontend pattern does NOT match .blade.php
                                # (SprintManifestValidator.php:141-144), so this boolean
                                # is a promise the diff check cannot verify either way.
                                # It must be flipped to true the moment resources/js or
                                # resources/css is touched.
security_impact: true           # it decides who may hold a session, which branch a
                                # clinician may write in, and it adds permissions;
                                # the diff check would force this anyway, because
                                # PermissionSeeder and RoleSeeder both match
                                # SprintManifestValidator.php:145-148
branch_isolation_impact: true   # this is the sprint that makes a doctor's operational
                                # branch server-authoritative rather than self-selected
ledger_impact: false            # no inventory ledger / billing / payment behaviour

deploy_required: true           # the capability only exists on the deployment that
                                # carries it, and both flags stay OFF on arrival
browser_required: true          # the load-bearing guarantees are only observable on
                                # real hardware: a second login denied while the first
                                # session keeps working, and a cover boundary crossed
                                # by a real doctor on a real tablet

go_tag: doctor-access-single-session-branch-lock-1-go

test_profiles:
  - focused
  - security_regression
  - schema_regression

# `full_required` is deliberately ABSENT. It is a real identifier
# (config/sprint_profiles.php:220) and SprintTestPlanner.php:105-108 escalates
# the full suite when a manifest declares it — which the ACTIVE global policy in
# .cursor/rules/107-global-temporary-full-suite-policy.mdc forbids for an
# individual sprint. Declaring it would be a claim this sprint may not honour.

# Carried forward. `.sprint/current.yml` is a ROLLING file, so these inherited
# facts have to be restated by every sprint after closure — dropping one is
# precisely the quiet contradiction LegacyRmeProgramClosureContractTest exists
# to catch.
inherits_closure: LEGACY-RME-PROGRAM-CLOSURE-1
inherits_hold: FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1/FIX-02
inherits_phase: feature-doctor-trusted-android-device-lock-1-phase-3-5-readiness-go
inherits_parent_go: doctor-pwa-webauthn-1-go
inherits_capability_go: doctor-pwa-multi-doctor-pilot-1-capability-go
inherits_pilot_go: doctor-pwa-multi-doctor-pilot-1-go

VALIDATION FACTS BEHIND EACH FIELD:
- required_fields (config/devflow.php:47-51): id, type, module, base_branch, runtime_change, schema_change, frontend_change, security_impact, deploy_required — all present.
- type SECURITY_FIX is a known type (config/sprint_profiles.php:174) with migration_allowed => true (:181) and max_modules => 99 (line 174 block). MODULE_SPRINT/RUNTIME_FIX/HOTFIX cap modules at 3/3/2 (config/sprint_profiles.php:49,66,83) and this sprint touches DoctorAccess, Branch, ClinicVisit, RmeOnlineContext and DoctorDevice, so those types would fail SprintScopeAuditor.php:49-51.
- SECURITY_FIX without security_impact=true is a WARNING (SprintManifestValidator.php:118-121), not an error; we declare it true so there is not even a WATCH from that clause.
- MIGRATION_HEAVY was rejected: it would be defensible (schema_change=true satisfies :125-127) but the dominant risk is an authorization boundary, and audit_level is 3 either way.
- go_tag matches config/devflow.php:65 `/^[a-z0-9]+(?:-[a-z0-9]+)*-go$/`.
- The file must be VALID YAML, not just parseable by the fallback parser: symfony/yaml IS installed (vendor/symfony/yaml present), so SprintManifest::parseYaml (app/Support/Devflow/SprintManifest.php:57-65) takes the Yaml::parse branch and never reaches parseSimpleYaml.
- The word LEGACY-RME-PROGRAM-CLOSURE-1 must survive: tests/Feature/LegacyRme/LegacyRmeProgramClosureContractTest.php:145-156 does a raw File::get on this path and asserts toContain('LEGACY-RME-PROGRAM-CLOSURE-1'). That suite is critical-gated by the `LegacyRme` token.
- tests/Feature/Foundation/DevflowSprintToolingTest.php:221-224 runs `sprint:manifest-check --manifest=.sprint/current.yml --no-diff-check` and asserts exit 0, so the manifest must produce ZERO errors (WATCH is tolerated; app/Console/Commands/SprintManifestCheckCommand.php:94-101 returns FAILURE only on !valid, or on WATCH under --strict). That suite is critical-gated by the `Devflow` token.

### EDIT database/seeders/PermissionSeeder.php
PURPOSE: Add the four NEW permission names. An unclassified permission is tolerated; an UNLISTED one is not.

ANCHOR: insert immediately after line 188 (`'manage_doctor_device_authorizations',`), which is the end of the existing doctor-access family, and before line 189 (`// SATUSEHAT-1 — Readiness foundation & controlled submission filter.`). Keeping the doctor family contiguous is the reason for this position rather than the tail of the array.

INSERT:

        // DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the doctor clinical
        // branch lock, its approved temporary cover, and the session lease.
        //
        // A doctor holds NONE of these. A clinician can never file, approve,
        // extend or clear their own branch authority or their own session,
        // which is the whole point of the workflow being an approval.
        'view_doctor_branch_locks',
        // FILE a request: an initial branch assignment, a permanent transfer, or
        // a temporary cover. Granted to NO role in RoleSeeder — only Super Admin
        // reaches it through the global Gate::before, exactly like
        // view_developer_console and view_doctor_devices. Withholding it from
        // the approver role is deliberate and follows the maker/checker split
        // already recorded in RoleSeeder.php:470-484: a role that could both
        // file and approve would satisfy the letter of the rule and defeat it.
        // Widening it to Admin Klinik is the obvious next step and is an OWNER
        // DECISION this sprint deliberately does not take.
        'manage_doctor_branch_locks',
        // DECIDE one. Super Admin or Supervisor RME only.
        'approve_doctor_branch_locks',
        // Clear a lease that is stuck behind a session that will never return,
        // so an on-site supervisor never needs a shell to unblock a clinician.
        'release_doctor_session_leases',

WHY THIS EDIT IS MANDATORY: three tests compare the grouped permission set against this constant with an exact whole-set `toBe` — tests/Feature/AccessControl/RoleManagementTest.php:247, tests/Feature/Sprint55/Sprint55PermissionGroupClassificationCleanupTest.php:173, tests/Feature/Sprint53/Sprint53PermissionPageModuleGroupingHotfixTest.php:176. A permission granted to a role but missing from PERMISSIONS also breaks RoleSeeder itself: RoleSeeder.php:551 syncs Super Admin against PermissionSeeder::PERMISSIONS and :553 syncs every other role against its list, and Spatie throws on an unknown permission name — which is why PermissionSeeder must always run BEFORE RoleSeeder.

SIDE EFFECT TO STATE: RoleSeeder.php:550-551 gives Super Admin '*', so all four are granted to Super Admin automatically on the next RoleSeeder run. No other role changes unless it is edited below.

### EDIT database/seeders/RoleSeeder.php
PURPOSE: Grant Supervisor RME the read, approve and lease-release authority — and deliberately NOT the filing authority.

ANCHOR: the 'Supervisor RME' block opens at RoleSeeder.php:395 and closes with `],` at line 498; line 497 is `'publish_legacy_odontogram_imports',`. Insert between 497 and 498.

INSERT:

            // DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the approver half of
            // the doctor branch-authority pair, plus the operational escape
            // hatch for a stuck session lease.
            //
            // Note what is deliberately ABSENT: manage_doctor_branch_locks. The
            // role that decides a doctor branch may not also file the request,
            // for the same reason this role holds review_legacy_rme_imports and
            // publish_legacy_rme_imports without manage_legacy_rme_migration_
            // operations. Separation of duties is enforced server-side inside
            // the approval transaction as well, because the single global
            // Gate::before (RepositoryServiceProvider.php:595-598) returns true
            // for Super Admin before any policy method runs, so a policy clause
            // alone would never execute for the one actor who could be both
            // parties.
            'view_doctor_branch_locks',
            'approve_doctor_branch_locks',
            'release_doctor_session_leases',

DO NOT: add any of these to 'Doctor' (RoleSeeder.php:255-288) — tests/Feature/Auth/RolePermissionHardeningTest.php:62-68 pins Doctor to clinical workflow, and a doctor granting themselves a branch is the exact defect the approval workflow exists to prevent. DO NOT add any to 'Admin Lab' — tests/Feature/AccessControl/AdminLabLabOnlyAccessTest.php:175-176 asserts role_extra_non_lab is empty. DO NOT widen the existing `branch-change-request.approve` Gate (app/Providers/RepositoryServiceProvider.php:593) to Supervisor RME: tests/Feature/AccessControl/DailyBranchContextBypassTest.php:347-362 asserts by name that Supervisor RME must NOT hold it, and that suite is critical-gated by the `DailyBranchContext` token. That test is precisely why this sprint needs its own permission rather than a reused gate.

### EDIT app/Modules/AccessControl/Services/PermissionGroupingService.php
PURPOSE: Classify the four new permissions so they do not land in the Other bucket.

ANCHOR: the access_control group definition is at lines 28-37; line 32 is `'permissions' => [`, lines 33-35 are 'manage users', 'manage roles', 'manage permissions', line 36 is `],`. Insert after line 35:

                // DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — who may hold a
                // doctor session, and which clinical branch a doctor is bound
                // to. An access-control fact rather than an RME workflow one.
                'view_doctor_branch_locks',
                'manage_doctor_branch_locks',
                'approve_doctor_branch_locks',
                'release_doctor_session_leases',

HONEST NOTE ON THE ALTERNATIVE: the sibling doctor-device family ('view_doctor_devices', 'manage_doctor_devices', 'view_doctor_device_authorizations', 'manage_doctor_device_authorizations') appears in NO group definition — a grep over GROUP_DEFINITIONS finds no doctor_device entry — so it falls into the Other bucket through the fallback (OTHER_DESCRIPTION at :236 and the classification path around :437-455). Both outcomes pass the tests: RoleManagementTest.php:247 and the two Sprint53/Sprint55 assertions compare the grouped names to PermissionSeeder::PERMISSIONS, and Other is a rendered group whose description is non-empty (Sprint55 :177-183 requires that). Classifying is chosen because the direction of travel in this codebase is classification (the suite is literally named PermissionGroupClassificationCleanup) and because a permission that decides who may hold a session belongs beside manage users/roles/permissions. Reclassifying the existing doctor-device family is deliberately NOT done here: it would change the admin permission page for an unrelated family and is outside this sprint.

### EDIT tests/Feature/Auth/SupervisorRmeRolePermissionTest.php
PURPOSE: Repin the only exact whole-role permission list in the suite. Without this, the three new grants make it red.

ANCHOR: the file-level const SUPERVISOR_RME_PERMISSIONS opens at line 16 and closes with `];` at line 95; lines 93-94 are 'view_rme_consents', 'manage_rme_consents'. Insert before line 95:

    // DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the approver half of the
    // doctor branch-authority pair plus the stuck-lease escape hatch. The
    // filing permission manage_doctor_branch_locks is deliberately NOT here:
    // the role that decides may not also file.
    'view_doctor_branch_locks',
    'approve_doctor_branch_locks',
    'release_doctor_session_leases',

The assertion that fails without this is line 110: expect($role->permissions->pluck('name')->sort()->values()->all())->toBe(collect(SUPERVISOR_RME_PERMISSIONS)->sort()->values()->all()) — a sorted whole-set toBe, and the only one of its kind in the suite.

CI COVERAGE OF THIS REPIN, STATED HONESTLY: the identity is `Tests\Feature\Auth\SupervisorRmeRolePermissionTest`, and it matches NO token in either critical filter — I checked the full token list at .github/workflows/foundation-evidence-gates.yml:422 and there is no `Permission`, `Auth` or `SupervisorRme` alternative. It IS selected by the CICD-CTRL Selective Module Gate at :900, `php artisan test --filter='Permission|AccessControl'`, which runs because scripts/ci/resolve-gates.sh:210 classifies `database/seeders/*Permission*|database/seeders/*Role*` as permissions_security and :301 then forces RUN_PERMISSION_TESTS=true. So the repin is covered by a required-but-conditional gate, not by the critical gate. Do not claim the critical gate covers it. If a future edit ever stops touching a seeder, this pin stops being selected — which is an argument for adding it to config/ci_runner.php critical_gate_mandatory_suites in a later governance sprint, deliberately not taken here because that would also require a new critical-filter token for a suite this sprint did not author.

### EDIT .github/workflows/foundation-evidence-gates.yml
PURPOSE: Add ONE new critical-filter token, `DoctorAccess`, to BOTH critical gate variants, so the new suites actually run.

TWO EDITS, and they must be byte-identical in effect:
- line 422, the GitHub-hosted `critical_test_gate` step named 'Run critical regression tests'
- line 690, the self-hosted `critical_test_gate_self_hosted` equivalent, which is the same filter behind `bash scripts/ci/self-hosted-php.sh`

In both, insert `|DoctorAccess` immediately after `|DoctorDevice` and before `|DoctorPwaWebAuthn`, so the doctor tokens stay adjacent. Change nothing else in the filter string.

WHY BOTH: tests/Feature/Cicd/DedicatedSelfHostedRunnerTest.php:173-192 extracts the `--filter='...'` argument out of the step named 'Run critical regression tests' in each job and asserts `expect($selfHosted)->toBe($hosted)` (CICDCTRL3-R009: the fallback is equivalent, never weaker). Adding a token to one variant only turns that suite red, and it is itself selected by the required `Cicd` token (config/ci_runner.php:139-141 declares 'Cicd' in critical_gate_required_filters, and DedicatedSelfHostedRunnerTest.php:694-701 asserts every critical filter contains it).

WHY A TOKEN IS NEEDED AT ALL, and why declaring the suites alone is NOT an alternative: app/Support/Cicd/SelfHostedRunnerScanner.php:481-500 loops over EVERY critical filter for EVERY declared mandatory suite and appends `critical gate filter #{index} does not select mandatory suite '{path}'` when no token matches. The registry is a reconciliation, so token-in-filter AND entry-in-registry are an AND, not an OR — config/ci_runner.php:157-163 states the same rule in prose ('A declared file that no token selects, or that no longer exists, FAILS the gate').

The token matches by path segment: SelfHostedRunnerScanner::testIdentity (:520-541) builds `Tests\Feature\DoctorAccess\<Class>` from the file path, and the match at :487 is a case-insensitive stripos, so every file under tests/Feature/DoctorAccess/ is selected. That is why every new suite for this sprint must live in that directory.

DO NOT add `paths-ignore` or a bare `runs-on: self-hosted` — both are in config/ci_runner.php:117-120 forbidden_workflow_markers.

### EDIT config/ci_runner.php
PURPOSE: Declare this sprint's suites as mandatory so their selection is a governance decision rather than an accident of naming.

ANCHOR: the `critical_gate_mandatory_suites` array ends at line 625 (`],`); line 624 is the last entry, 'tests/Feature/Pwa/PwaServiceWorkerCachePolicyTest.php'. Insert before line 625:

        // DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the doctor session
        // cardinality invariant and the clinical branch authority.
        //
        // Declared for the reason Phase 1 established and one sharper. The
        // core invariant is a PARTIAL unique index, and the local suite runs
        // SQLite while the authoritative critical gate runs PostgreSQL 16
        // (DB_CONNECTION: pgsql is set at job level in both critical variants,
        // and phpunit.xml carries no force="true" on its env elements, so the
        // exported value wins). If these suites are not selected, the only
        // proof that a second live lease is refused on the production driver
        // never executes anywhere. The failure mode is silent in both
        // directions: a flattened index locks a doctor out permanently, and a
        // missing one lets two sessions coexist while every test stays green.
        'tests/Feature/DoctorAccess/DoctorAccessFeatureFlagContractTest.php',
        'tests/Feature/DoctorAccess/DoctorSessionLeaseCardinalityTest.php',
        'tests/Feature/DoctorAccess/DoctorSessionLeaseDenyNotEvictTest.php',
        'tests/Feature/DoctorAccess/DoctorBranchLockResolutionTest.php',
        'tests/Feature/DoctorAccess/DoctorBranchCoverLifecycleTest.php',
        'tests/Feature/DoctorAccess/DoctorBranchLockApprovalAuthorityTest.php',
        'tests/Feature/DoctorAccess/DoctorBulkDeviceAuthorizationTest.php',

EVERY declared path must EXIST or the scanner emits `mandatory critical suite '{path}' does not exist — the registry is stale` (SelfHostedRunnerScanner.php:477-479). So this edit lands in the same commit as the suites, and any suite the sibling slices rename or drop must be removed here in the same change.

DO NOT touch `critical_gate_required_filters` (:139-141, ['Cicd']) or `critical_gate_warning_contract.expected_warning_count` (:657, 0). The warning baseline is a DECLARED baseline, not a tolerance: new tests must emit no PHPUnit warnings, and raising the number to absorb one is called out in that block as a governance violation.

DO NOT add these suites to `strict_pipeline_steps` (:715-747) — that list names workflow STEP names whose exit status must survive a pipe, not test files.

### NEW docs/sprints/doctor-access-single-session-branch-lock-1.md
PURPOSE: The sprint record, in the house style of docs/sprints/doctor-pwa-global-rollout-readiness-1.md.

STRUCTURE, matching the sibling file's shape (title, bold status paragraph, branch/base/contract/rules line, a standing-posture sentence, then numbered sections):

# DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1

**Status:** implemented and tested; both capabilities ship OFF. Branch `feature/doctor-access-single-session-branch-lock-1`, base `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `215d277d`. Durable contract `docs/architecture/doctor-access-single-session-branch-lock.md` (`DSB-1…DSB-n`). Rules: `DSB-R1…DSB-Rn` in `.cursor/rules/153-doctor-access-single-session-branch-lock.mdc`. Runbook `docs/runbooks/doctor-branch-lock-and-session-lease-operations.md`. Manifest `.sprint/current.yml`.

**Global doctor WebAuthn enforcement was false when this sprint started and is false now.** Nothing here can arm it: `doctor.trusted_device_enforcement` still defaults false (config/feature_flags.php:400), `android_release.enforcement.current_stage` is still `off` (config/android_release.php:1281), `scope.global_permitted` is still false (:1335), and the pilot cohort is unchanged.

1. **Authority** — base SHA, production HEAD, the five inherited GO tags carried in the manifest, and the owner decisions O1-O6 this sprint implements verbatim.
2. **What the five requirements actually needed.** Requirement 2 (any approved active tablet, whatever branch owns it) needed NO CODE: no predicate in any login path reads a device branch, `mst_doctor_device_authorizations` has no branch column at all (database/migrations/2026_09_03_110001:46-95), and the device branch is documented as a placement rather than a permission (app/Modules/DoctorDevice/Services/DoctorAppLoginService.php:485-490). Requirement 5 needed no code either. Requirements 1, 3 and 4 needed new runtime.
3. **The branch starts UNSET, and there is no backfill.** All 15 production doctors have `users.branch_id` NULL and `mst_doctors.branch_id` NULL, every one is pivoted to all four RME branches, 10 of 15 carry no branch signal at all, and the doctor code disagrees with reality for at least one live clinician (DOC-TLK002 whose only online context and whose authorised tablet are both ATG3). An inferred lock would be a guess written as a fact. UNSET is the compatibility state; `AMBIGUOUS_DOCTOR_COUNT` may stay above zero and `DEPLOYMENT_BLOCKED_BY_AMBIGUITY=NO`.
4. **Effective branch, and why expiry is lazy.** EFFECTIVE = active approved cover, else home lock. Resolved from current timestamps on every protected request. A scheduled job that flipped the branch would be a silent mid-shift move whose correctness depended on a worker nobody guarantees. A command may tidy expired rows; correctness never depends on it.
5. **Why the session is invalidated on cover START and on cover EXPIRY.** The lease records the effective branch at CLAIM time; the middleware recomputes it per request and compares. Activation, expiry and transfer then behave identically and a silent mid-session branch switch is structurally impossible.
6. **Deny, never evict — and never lock a doctor out after a normal handover.** An incumbent whose `sessions` row is gone is DEAD and reclaimed; a live incumbent is denied however idle. No idle-timer reclamation. The deny path uses `logoutCurrentDevice()` plus session invalidate and token regenerate, NEVER `Auth::guard('web')->logout()`: verified in vendor/laravel/framework/src/Illuminate/Auth/SessionGuard.php, `logout()` at :650 calls `cycleRememberToken()` at :657 while `logoutCurrentDevice()` at :680 does not, and `users.remember_token` is a single shared column — so refusing login two through `logout()` would partially evict session one. `DoctorDeviceSessionService::invalidate()` calls `logout()` (:188-207) and must not be reused on the deny path.
7. **Why the claim is a listener on `Illuminate\Auth\Events\Login`.** SessionGuard fires it at :573 from ordinary `login()` AND at :202 on the remember-me recaller path, so a controller-only claim would miss a resurrected session entirely. `setUser()` (:996-1005) fires `Authenticated`, not `Login`, and `actingAs` goes through `setUser` — which is exactly why a `Login` listener covers 100% of production authentication entries and 0% of the ~3554 `actingAs` call sites in tests/, and is what makes this implementable without rewriting the suite.
8. **Why the module is `DoctorAccess` and not `DoctorDevice`.** Two critical-gated contracts: the route middleware contract (DoctorDeviceApiAndNoEnforcementTest.php:246-278) and the auth-surface symbol allowlist that includes bootstrap/app.php (DoctorDeviceAccessTest.php:210-244).
9. **Bulk device authorization (owner decision O4).** MODEL A preserved. Dry-run by default; the exact row delta printed and explicitly approved before APPLY; ACTIVE doctors and ACTIVE cryptographically_verified devices only; terminally revoked, pending and inactive excluded; no WebAuthn credential created; no branch-lock, pilot-scope or feature-flag mutation; transactional apply with post-write reconciliation. It must go through `DoctorDeviceAuthorizationService::resolveOrRequest()` then `approve()` and never `firstOrCreate`, because the model sets `protected $fillable = []` deliberately (DoctorDeviceAuthorization.php:65-70).
10. **Migration cost, and the tests that were rewritten rather than deleted.** DailyBranchContextLockTest.php:197 ('leaves a doctor free to change branch and room') is the visible governance record of a reversal and is rewritten to hold for UNSET and to assert the narrowing for SET. DailyBranchContextLockTest.php:219-227 stays GREEN and is the proof the daily lock was left alone. DoctorRmeBranchSourceTest.php:272, :279, :298, :311 are repinned — and that file matches NO critical-filter token, so the breakage surfaces only in a full-suite run and had to be found deliberately.
11. **CI registration.** One new token `DoctorAccess` in BOTH critical variants; seven suites declared in config/ci_runner.php. Token-in-filter and entry-in-registry are an AND.
12. **What automated tests cannot prove** — reproduce the ceremony list from this slice's honest-limits statement verbatim.
13. **A pre-existing defect found while verifying, reported and NOT fixed here.** Three sites catch a QueryException INSIDE `DB::transaction` and then run further queries, which aborts the whole transaction with 25P02 on PostgreSQL while passing on SQLite: app/Modules/RME/Services/PatientDoctorAssignmentService.php:48 (inside the transaction opened at :25) and :172 (inside :137), and app/Modules/MedicalRecord/Services/DiagnosisRolloutService.php:127 (inside :111, which then also issues an UPDATE). They only misbehave when the race they exist to handle actually fires, which is why the suite is green. Out of scope.

### NEW docs/architecture/doctor-access-single-session-branch-lock.md
PURPOSE: The durable contract. Numbered rules DSB-1..DSB-22 that later sprints inherit.

A NEW file rather than a section appended to docs/architecture/doctor-pwa-webauthn.md, because that document is the device-credential contract and this is a session-cardinality and branch-authority contract; they arm independently and must be able to be rolled back independently.

DSB-1 A doctor may authenticate from ANY approved ACTIVE cryptographically_verified clinic device regardless of which branch owns it. No login predicate may read a device branch. `mst_doctor_devices.branch_id` is a placement, not a permission (config/doctor_device_enforcement.php:80-84).
DSB-2 MODEL A stands: the (doctor, device) authorization row is the audit boundary. Fleet-wide implicit trust is refused. Never widen `DoctorDeviceWebAuthnCredentialRepository::usableForDoctor()` (:45-48) and never relax the exact-pair assert at DoctorDeviceWebAuthnLoginService.php:297-306.
DSB-3 MAX_ACTIVE_DOCTOR_SESSIONS = 1, keyed on `users.id`, with `doctor_id` stored for audit only. Keying on `mst_doctors.id` would silently exempt every unlinked account.
DSB-4 The second login is DENIED. The first is NEVER evicted by a second login.
DSB-5 The only eviction is an approved permanent transfer, and it happens by committing `released_at` inside the transfer transaction, because no in-process cross-session logout exists in this codebase.
DSB-6 Cardinality is owned by the DATABASE: `CREATE UNIQUE INDEX ... (user_id) WHERE released_at IS NULL`, created in the SAME migration as the table. Never a separate later migration, and never a plain UNIQUE(user_id).
DSB-7 A unique-violation catch sits OUTSIDE the failing statement's transaction, or wraps only the INSERT in a nested transaction so the failure rolls back to a SAVEPOINT. The detector must accept both SQLSTATE 23505 and the SQLite message forms. Copy DailyBranchContextService::assertSelectable() (app/Modules/RmeOnlineContext/Services/DailyBranchContextService.php:147-232) and its `isUniqueViolation()` (:221-232).
DSB-8 The lease binds to a random token held in session DATA, persisted only as its sha256. Never to `session()->getId()`, which rotates three times on the WebAuthn path.
DSB-9 The claim is a listener on `Illuminate\Auth\Events\Login`, never a login controller.
DSB-10 The deny path uses `logoutCurrentDevice()` + session invalidate + token regenerate. `Auth::guard('web')->logout()` is forbidden on the deny path.
DSB-11 A session with NO lease is pass-through. Only a session whose token contradicts a LIVE lease is denied. Without this the migration cost is the whole suite.
DSB-12 Reclamation is by DEAD SESSION, never by idle timer.
DSB-13 Eviction frees the clinic room: call `UserOnlineContextService::markOffline($user)` before tearing the session down, as the transfer approval path already does.
DSB-14 HOME_LOCKED_BRANCH starts UNSET for every doctor. NO BACKFILL, ever. A recent branch may be DISPLAYED as a non-authoritative suggestion and must never be auto-written.
DSB-15 While UNSET, every pre-sprint behaviour is preserved exactly. Failing closed on a missing lock would lock out the whole fleet on deploy day.
DSB-16 TEMPORARY_BRANCH_COVER is a separate concept with target_branch_id, starts_at, ends_at, reason, approval status, approved_by, approved_at. It never changes HOME_LOCKED_BRANCH and never revokes a device, an authorization or a credential.
DSB-17 EFFECTIVE_CLINICAL_BRANCH = active approved cover, else home lock. Resolved from current timestamps on every protected request. ACTIVE and EXPIRED are DERIVED, never persisted.
DSB-18 Cover activation AND cover expiry BOTH invalidate the doctor's active session, by comparing the effective branch against the value recorded on the lease at claim time. Overlapping active covers for one doctor are forbidden. A permanent transfer approval is blocked while a cover is active.
DSB-19 The lock narrows LISTS and WRITES. It must NEVER reach per-record authorization: hooking a per-record policy would stop a doctor opening the record of a patient standing in front of them at their own locked branch whenever that patient's earliest RME visit happened elsewhere, because the patient-centric workspace anchors on the earliest visit. That is a patient-safety defect. Per-record access stays on the existing clinical-relationship gates.
DSB-20 The write chokepoint is `ClinicVisitService::resolveBranchId()` (app/Modules/ClinicVisit/Services/ClinicVisitService.php:395-421), where both `branch_id` and `new_patient.branch_id` converge and a ValidationException is already thrown. Narrowing a read scope does not stop a write.
DSB-21 Archive reads stay cross-branch. `DoctorClinicalBranchResolver` is untouched and its docblock rationale stands.
DSB-22 A lock that has lost `is_active` or `is_rme_enabled` degrades to UNSET-with-audited-warning, never a 500. `BranchContext::requireId()` would otherwise throw on every write path.
DSB-23 A Doctor-role account with no `mst_doctors.user_id` link binds nobody: refuse in request() and approve(), audit the refusal.
DSB-24 Approval authority is Super Admin or Supervisor RME, through the NEW `approve_doctor_branch_locks` permission — never by widening `branch-change-request.approve`, which a critical-gated test pins closed to every non-super-admin role. Requester-is-not-approver is enforced INSIDE the transaction under the row lock, because the single global `Gate::before` (RepositoryServiceProvider.php:595-598) returns true for Super Admin before any policy method runs.
DSB-25 Both capabilities are OFF by default and arm together: `DoctorBranchLockResolver::enabled()` requires BOTH flags. Nothing reads a flag `dependencies` array, so the dependency must live in code.
DSB-26 GLOBAL_ENFORCEMENT_ACTIVE stays false. This sprint does not perform DOCTOR-PWA-GLOBAL-ACTIVATION-1 and does not create `doctor-pwa-global-rollout-readiness-1-go`. The bounded pilot cohort [9,15,18] is preserved.
DSB-27 Lease events are audited under their OWN action, not the device action `DOCTOR_SESSION_DEVICE_INVALIDATED` (app/Modules/DoctorDevice/Services/DoctorDeviceSessionService.php:194). A lease eviction is not a device invalidation. There is no global audit-action allowlist in this codebase, so the discipline is the contract.

### NEW docs/runbooks/doctor-branch-lock-and-session-lease-operations.md
PURPOSE: The operator runbook: how to assign, transfer, cover, release and roll back — and what never to do.

NOT registered in config/enterprise_documentation.mandatory_runbooks, and it does not need to be: app/Support/Documentation/EnterpriseDocumentationScanner.php:46-48 reads only that registry, so an unregistered runbook is neither scanned nor required. Registering it would import the required_sections and forbidden-destructive-pattern contract for no benefit this sprint; state that choice in the doc so it reads as a decision.

SECTIONS:
1. Purpose and standing posture — both flags OFF on arrival; UNSET doctors behave exactly as before; nothing here can arm device enforcement.
2. Pre-flight — confirm `doctor.single_active_session` and `doctor.branch_lock` are both false; confirm `SESSION_DRIVER` resolves to `database` on the host (config/session.php:21 defaults to database, and dead-session reclamation depends on that row existing).
3. Assign an initial branch — the approver screen, the filer, the fields, and the audit rows to expect. A doctor is never assigned a branch without an explicit approval.
4. Transfer a branch — states plainly that approval invalidates the doctor's active session and they must log in again; and that a transfer is REFUSED while a cover is active, which must be ended or cancelled first.
5. Grant a cover — target branch, starts_at, ends_at, reason. States that cover START and cover EXPIRY both end the session, that expiry needs no job, and that a doctor can neither self-grant nor self-extend.
6. Clear a stuck lease — the audited release action behind `release_doctor_session_leases`. Names the two legitimate cases (a device that will never return; a session on hardware that has been wiped) and states that a live incumbent must be asked to log out rather than released around.
7. Bulk device authorization — dry-run first, always; read the printed delta (DOCTORS / TRUSTED_DEVICES / EXISTING_AUTHORIZATIONS / PROPOSED_NEW_AUTHORIZATIONS / FINAL_EXPECTED_AUTHORIZATIONS) aloud against the fleet you expect; only then approve APPLY; verify afterwards that every eligible doctor x eligible device pair has exactly ONE active authorization. Never revoke old rows as part of a sync.
8. Rollback — flip either flag false and clear the config cache; quote the two `rollback_action` strings verbatim so an operator at 2am reads the same words the registry carries.
9. NEVER — never run an interactive REPL on production (blocked at CommandStarting by config/release_safety.php:137-139 and by the pattern scan at :113-120; a REPL also pins the monitoring log signal to WATCH for 24h); never `migrate:fresh`; never `db:wipe`; never hand-edit a lease or lock row; never infer a branch from a doctor code, a device branch, a room, a last login or history.

### NEW .cursor/rules/153-doctor-access-single-session-branch-lock.mdc
PURPOSE: The durable AI-assistant mirror of the architecture contract.

NUMBER 153, NOT 93. Verified: `93-doctor-pwa-webauthn.mdc` and `93-legacy-rme-void-clinical-read.mdc` both already exist, so 93 is a triple collision. The highest number currently present is 152 (`152-doctor-global-rollout-readiness.mdc`) and 153 is free (a listing of .cursor/rules matching ^153 returns zero files). No test scans rule numbering — only the ENT suites and LegacyRmeProgramClosureContractTest read specific rule paths by name — so the number is a discipline, not a gate.

FRONTMATTER (same shape as .cursor/rules/105-legacy-rme-program-closure.mdc:1-10):

---
description: DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — one authenticated doctor session at a time with the second login denied and the first never evicted; a permanent home clinical branch that starts UNSET plus approved temporary cover; effective branch drives lists and writes while archive reads stay cross-branch. Both capabilities ship OFF and arm together.
globs:
  - "app/Modules/DoctorAccess/**"
  - "app/Modules/DoctorDevice/**"
  - "app/Modules/Branch/Services/BranchContext.php"
  - "app/Modules/ClinicVisit/Services/ClinicVisitService.php"
  - "app/Modules/RmeOnlineContext/**"
  - "app/Http/Controllers/Auth/**"
  - "bootstrap/app.php"
  - "config/feature_flags.php"
alwaysApply: false
---

BODY: a fenced posture block first, in the style of rule 105:

```
SINGLE_ACTIVE_DOCTOR_SESSION=1
SECOND_LOGIN=DENIED
FIRST_SESSION=NEVER_EVICTED_BY_A_SECOND_LOGIN
HOME_LOCKED_BRANCH=UNSET_BY_DEFAULT
BACKFILL=FORBIDDEN
EFFECTIVE_BRANCH=ACTIVE_COVER_ELSE_HOME
COVER_EXPIRY=LAZY_FROM_TIMESTAMPS
ARCHIVE_READS=CROSS_BRANCH
CAPABILITY=OFF
GLOBAL_ENFORCEMENT_ACTIVE=false
```

Then DSB-R1…DSB-R27 mirroring DSB-1…DSB-27 one for one, each one sentence. Then a short TRAPS block naming the six that would look correct and enforce nothing: the `DoctorDevice` namespace collision on both the route-middleware contract and the bootstrap/app.php symbol allowlist; putting the check inside `EnsureDoctorDeviceSession` or `DoctorAppLoginGate::denySessionReason()` where both return on their first line while the device flag is off; setting a flag with dot notation because the KEY contains dots; catching the unique violation inside the transaction; creating the partial index in a later migration; and using `logout()` on the deny path.

### EDIT CLAUDE.md
PURPOSE: Append this sprint's section in the established house style.

ANCHOR: append after the final line of the file (the file is 2624 lines; the last section heading is `## DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1 …` at line 2604).

SECTION TO APPEND:

## DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — one session per doctor, and a clinical branch the doctor cannot choose (2026-09-10)

Branch `feature/doctor-access-single-session-branch-lock-1` (base `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `215d277d`; do NOT target main). Durable contract `docs/architecture/doctor-access-single-session-branch-lock.md` (`DSB-1…DSB-27`); sprint doc `docs/sprints/doctor-access-single-session-branch-lock-1.md`; runbook `docs/runbooks/doctor-branch-lock-and-session-lease-operations.md`; rule mirror `.cursor/rules/153-doctor-access-single-session-branch-lock.mdc` (`DSB-R1…DSB-R27`, numbered 153 because 93 already collides with two files); manifest `.sprint/current.yml`. **Two additive migrations, four new permissions, one new module `App\Modules\DoctorAccess`, and TWO NEW CRITICAL FEATURE FLAGS THAT BOTH SHIP OFF. Global doctor WebAuthn enforcement was false when this started and is false now — `doctor.trusted_device_enforcement` still defaults false, `android_release.enforcement.current_stage` is still `off`, `scope.global_permitted` is still false, and the bounded pilot cohort `[9,15,18]` is unchanged.**

**Requirement 2 needed no code, and proving that was the finding.** "A doctor may use any approved active tablet whatever branch owns it" already holds: no predicate in any login path reads a device branch, `mst_doctor_device_authorizations` has no branch column at all, and the device branch is written FROM the doctor and documented as a placement rather than a permission. The tempting shortcuts — widening `usableForDoctor()`, relaxing the exact-pair assert — would have deleted the only per-doctor device predicate and are pinned against. What all-tablet access actually needs is authorization ROWS, and no new credentials: `trx_doctor_device_webauthn_credentials` binds to `doctor_device_id` only, so a credential proves the DEVICE, never the clinician.

**Deny, never evict — and never lock a doctor out after a normal handover.** A lease keyed on `users.id` (never `mst_doctors.id`, which fails open for an unlinked account and is a different number — the recorded Phase-4A hazard where the pilot target was user 18 while the doctor record was 21) holds cardinality 1 through an UNGUARDED partial unique index created in the SAME migration as its table. A second login is refused; the first is untouched. **The refusal may not use `Auth::guard('web')->logout()`**: verified in vendor, `logout()` cycles `users.remember_token` and `logoutCurrentDevice()` does not, and that column is shared across every session of the account — so refusing login two through `logout()` would partially evict session one, which is the one thing requirement 1 forbids. `DoctorDeviceSessionService::invalidate()` calls `logout()`, so it is deliberately NOT reused on the deny path. **Reclamation is by DEAD SESSION, never by idle timer**: an incumbent whose `sessions` row is gone is reclaimed at once, an incumbent whose session is alive is denied however idle. An idle timer would be eviction by the back door and would lock out the doctor who finished on the ward tablet and walked to the office PC.

**The claim is a listener on `Illuminate\Auth\Events\Login`, not a login controller.** SessionGuard fires it from the ordinary login AND from the remember-me recaller path, which is the path a controller-only claim would miss entirely; `setUser()` fires `Authenticated` instead, and `actingAs` goes through `setUser`, so a `Login` listener covers 100% of production authentication entries and 0% of the ~3554 `actingAs` call sites — which is what makes this shippable without rewriting the suite. A session with NO lease is pass-through; only a session whose token contradicts a LIVE lease is denied.

**The branch starts UNSET, and nothing backfills it.** All 15 production doctors carry a NULL branch on both `users` and `mst_doctors`, every one is pivoted to all four RME branches, 10 of 15 have no branch signal at all, and one live clinician's doctor code names a branch her only online context and her authorised tablet both contradict. So there is no algorithmically correct answer and none is invented. UNSET preserves pre-sprint behaviour exactly — failing closed on a missing lock would have locked out the entire fleet on deploy day. Once SET the doctor cannot self-switch, and **the lock narrows LISTS AND WRITES but NEVER per-record authorization**: hooking a per-record policy would stop a doctor opening the record of a patient standing in front of them at their own locked branch whenever that patient's earliest RME visit happened elsewhere, because the patient-centric workspace anchors on the earliest visit. The write chokepoint is `ClinicVisitService::resolveBranchId()`, where `branch_id` and `new_patient.branch_id` converge — narrowing a read scope does not stop a POST. Archive reads stay cross-branch and `DoctorClinicalBranchResolver` is untouched.

**Cover is time-boxed, and expiry needs no scheduler.** A cover carries explicit `starts_at`/`ends_at` and an approval; ACTIVE and EXPIRED are DERIVED from timestamps and never persisted, so a delayed worker can never leave an expired cover effective. **Cover activation AND cover expiry both invalidate the session**, by recording the effective branch on the lease at claim time and recomputing it per request — activation, expiry and permanent transfer then behave identically and a silent mid-session branch switch is structurally impossible. Overlapping covers are forbidden, and a permanent transfer is blocked while a cover is active.

**The module is `DoctorAccess`, and the name is load-bearing.** Two critical-gated contracts forbid `DoctorDevice`: every registered route's middleware containing that substring must be exactly `EnsureDoctorDeviceSession`, and an auth-surface symbol allowlist that includes `bootstrap/app.php` — where the new global middleware is registered — permits only four `DoctorDevice*` identifiers. Renaming the class would not have saved it; the collision is on the namespace segment. Separately, no new file may contain the literal `doctor.trusted_device_enforcement`: a source scan pins its reader list to exactly one file, and gating on it would have made the new rules dead code (it defaults false) or silently narrowed them to the pilot cohort.

**Two flags, and the dependency is enforced rather than declared.** `doctor.single_active_session` and `doctor.branch_lock`, both `default => false`, both `risk_level => 'critical'`, each with its own `env_key` so the config-build capture loop keeps the override alive under `config:cache`. **Nothing in this codebase reads a flag's `dependencies` array** — it is required metadata and hydrated, and no caller consumes it — so `DoctorBranchLockResolver::enabled()` requires BOTH flags in code. Branch lock armed alone would be a branch that changes underneath an already-authenticated session with the invalidation mechanism absent, which is exactly what the cover design forbids. A test arms the branch flag alone and asserts the resolver still says no.

**Approval authority is a NEW permission, not a widened gate.** `view_doctor_branch_locks`, `manage_doctor_branch_locks`, `approve_doctor_branch_locks` and `release_doctor_session_leases` join `PermissionSeeder::PERMISSIONS` and are classified into the `access_control` group. Supervisor RME gets view + approve + release; **`manage_doctor_branch_locks` is granted to NO role** — Super Admin reaches it through the global `Gate::before`, exactly like `view_doctor_devices` — so the role that decides cannot also file, following the maker/checker split already written into the legacy-RME grants. Widening `branch-change-request.approve` was refused: a critical-gated test asserts by name that Supervisor RME must not hold it. Requester-is-not-approver is enforced INSIDE the transaction under the row lock, because the single global `Gate::before` returns true for Super Admin before any policy method runs. `SUPERVISOR_RME_PERMISSIONS` — the only exact whole-role pin in the suite — is repinned +3.

**CI trap recorded.** New suites live in `tests/Feature/DoctorAccess/` and a single new token `DoctorAccess` was added to BOTH critical filter variants, because the two must stay byte-identical and because declaring a suite in `config/ci_runner.php` that no token selects is a gate FAILURE rather than an alternative satisfaction — the reconciliation is an AND. This matters more than usual here: the authoritative critical gate runs PostgreSQL 16 while the local suite runs SQLite, so the proof that a second live lease is refused on the production driver executes only if the token selects it. Honest limit: `Tests\Feature\Auth\SupervisorRmeRolePermissionTest` matches no critical token and is selected only by the Selective Module Gate, which runs because a seeder changed.

**Not done, deliberately:** no doctor assigned a branch; no lock, cover or transfer created; no device, authorization or credential created or revoked; no cohort change; no flag armed anywhere; `doctor-pwa-global-rollout-readiness-1-go` not created. **A pre-existing defect was found while verifying and is reported, not fixed:** `PatientDoctorAssignmentService.php:48` and `:172` and `DiagnosisRolloutService.php:127` all catch a `QueryException` inside `DB::transaction` and then run further queries, which returns 25P02 on PostgreSQL and passes on SQLite. They misbehave only when the race they exist to handle actually fires, which is why the suite is green.
