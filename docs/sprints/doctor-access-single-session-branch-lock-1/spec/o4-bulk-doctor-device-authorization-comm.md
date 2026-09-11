# SLICE: O4 — bulk doctor↔device authorization command (`doctor:device-bulk-authorize`): dry-run by default, explicit plan-token approval before APPLY, transactional grant through the existing `DoctorDeviceAuthorizationService` lifecycle, in-transaction reconciliation, never revokes, never resurrects a REJECTED or REVOKED pair.

## Decisions
- THE UNIQUE-SLOT ANSWER (the critical detail asked for). mst_doctor_device_authorizations has UNIQUE(doctor_id, doctor_device_id) named mst_dd_authorizations_pair_unique (migration 2026_09_03_110001:92) and no soft delete (its docblock says so at :29), so exactly one row per pair exists forever. The command therefore classifies rather than writes, in five buckets, and writes in only one of them: NO ROW -> create+approve; ACTIVE -> skip, idempotent no-op; PENDING -> skip by default; REJECTED -> skip, always; REVOKED -> skip, always. It never mutates an existing row's status except via approve() on a PENDING row, and only when --include-pending was explicitly asked for.
- REJECTED IS NEVER TOUCHED, NOT EVEN WITH A LIVE RE-REQUEST ALLOWANCE. This is the trap. DoctorDeviceAuthorizationService::resolveOrRequest() sends EVERY existing row through reopenIfPermitted() (:69-71 -> :123-150), which for a REJECTED row whose re_request_allowed_for_rejected_at still equals its rejected_at (DoctorDeviceAuthorization::isReRequestAllowed() :162-175) flips it back to PENDING and audits it. Two consequences, both structural. (a) The PLAN phase must never call resolveOrRequest — it would make a dry run a write. It classifies with findPair() (repository :37-43) only. (b) The APPLY phase calls resolveOrRequest ONLY for pairs that have no row at all, so reopenIfPermitted is unreachable by construction rather than by care. An allowance is an invitation addressed to the DOCTOR: the service docblock at :314-321 is explicit that it 'does NOT approve anything', 'does not itself create a PENDING row', and 'the doctor still has to attempt a login'. A bulk tool spending that allowance on the doctor's behalf converts one operator's per-pair reconsideration into a fleet-wide side effect — which is exactly the silent resurrection the brief forbids. The pair is reported as blocked_rejected in the UNRESOLVED table, naming the human decision that must happen first.
- REVOKED IS TERMINAL AND IS ONLY EVER REPORTED. DoctorDeviceAuthorization::STATUS_REVOKED is documented terminal (:42-43) and approve() refuses it with its own message (:170-174). The command excludes it at plan time so that refusal is never reached mid-transaction.
- THE CONFIRMATION IS A PLAN TOKEN, NOT AN INTERACTIVE PROMPT. There is not a single $this->confirm( in app/ — verified by grep across app/Console/Commands and app/Modules. And an interactive prompt would be worse than absent here: Symfony returns the DEFAULT without asking when the input is non-interactive (vendor/symfony/console/Helper/QuestionHelper.php:53-54), and Laravel's confirm() defaults to false (vendor/laravel/framework/src/Illuminate/Console/Concerns/InteractsWithIO.php:138) — so under the detached non-interactive VPS deploy runner a prompt is silently answered, and a confirm(..., true) would be silently answered YES. The STOP is instead: the dry run prints the five-line block plus PLAN_TOKEN (sha256 over the sorted doctor ids, device ids, pair buckets and the five counters); --apply refuses without --confirm-plan=<token>; and the apply recomputes the plan from live data and refuses if the token moved. That is a genuine stop (you cannot apply without having produced and read the block) AND stale-plan-proof (a device revoked or a pair rejected in between changes the token). NEW mechanism — no precedent in this codebase.
- THE FIVE COUNTERS ARE DEFINED AGAINST THE GRID, NOT THE TABLE, AND CARRY AN INVARIANT. EXISTING_AUTHORIZATIONS counts rows whose pair lies inside eligible-doctors x eligible-devices, any status — not all rows in the table. Production has 5 rows but 2 of them (doctor 21 -> device 1 revoked, doctor 21 -> device 4 rejected) sit on devices that are not eligible, so they are reported separately as OUT_OF_GRID_AUTHORIZATIONS and never counted or touched. Because every grid pair either already has a row or gets one, FINAL_EXPECTED_AUTHORIZATIONS === DOCTORS x TRUSTED_DEVICES always holds; the service asserts it before printing and refuses if it does not. Production: DOCTORS=15, TRUSTED_DEVICES=3, EXISTING=3, PROPOSED_NEW=42, FINAL_EXPECTED=45 (brief section J: 3 READY doctors hold the 3 in-grid rows, the other 12 are blocked by exactly no_device_authorization).
- ELIGIBILITY REUSES THE CANONICAL PREDICATES INSTEAD OF INVENTING A FOURTH. Device: DoctorDeviceProofService::isTrustworthy() (:197-202) = isActive() && isCryptographicallyVerified() && public_key !== null, which is already 'ACTIVE + cryptographically_verified, revoked/disabled/pending excluded' (DoctorDevice::isActive() :147-150 is false for all three of pending_approval :38, disabled :41, revoked :44). Doctor: the readiness engine's own conditions and its own reason strings (DoctorGlobalRolloutReadinessService REASON_DOCTOR_NOT_LINKED :53, REASON_DOCTOR_INACTIVE :55, checked at :156-160 and :174-178). Reusing them means the bulk tool's 'eligible' set and the readiness report's 'blocked by no_device_authorization' set cannot drift apart, which is the whole point of running one before the other.
- TWO ADDITIONS TO THAT INHERITED ELIGIBILITY, BOTH FAIL-CLOSED AND BOTH JUSTIFIED. (a) users.is_active must be true. DoctorDeviceRolloutReadinessRepository::doctorAccounts() (:22-28) does not filter it and the readiness engine does not check it; standing access for a disabled account is not something a bulk tool should mint silently. (b) device.branch_id must not be null. approve() throws ValidationException on a branchless device (:210-214), so excluding it at plan time is precisely what stops an apply dying halfway through.
- TWO THINGS DELIBERATELY *NOT* MADE ELIGIBILITY CRITERIA. enrollment_status: DoctorDevice::isEnrollmentVerified() (:222-225) is consulted by neither isTrustworthy(), nor approve(), nor DoctorGlobalRolloutReadinessService::pathFor(); gating on it would make this tool stricter than the login gate and silently withhold access from a device that can legitimately authenticate. And WebAuthn credential presence: trx_doctor_device_webauthn_credentials binds to doctor_device_id only, with no doctor column (brief section J), so the credential is enrolled at the tablet AFTER the authorization row exists — gating on it would defeat O4 outright. Both are REPORTED per device in the plan table, never gated on.
- APPLY IS ONE OUTER TRANSACTION, AND THAT IS SAFE ON POSTGRESQL. resolveOrRequest() catches a QueryException at :103 and then issues a SELECT at :107 — the shape section N warns about. It is correct here because the catch sits OUTSIDE its own nested DB::transaction (:74): a nested transaction compiles to a SAVEPOINT and its rollback to ROLLBACK TO SAVEPOINT (vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php:302-312, reached from handleTransactionException :107), which leaves the enclosing transaction usable. So wrapping the whole run in one outer DB::transaction is all-or-nothing without inheriting a 25P02.
- RECONCILIATION RUNS BEFORE COMMIT, NOT AFTER. Four assertions inside the transaction: every non-blocked grid pair has exactly one row and it is ACTIVE; the in-grid row count equals FINAL_EXPECTED_AUTHORIZATIONS; the GLOBAL row count equals before + PROPOSED_NEW (nothing was created anywhere else); and every out-of-grid row is byte-identical to the pre-run snapshot (proving nothing was revoked or reopened as a side effect). A failure throws and the whole grant rolls back. A check that can only run after commit is a report, not a reconciliation.
- IT CANNOT REVOKE, AND THAT IS PROVEN STRUCTURALLY. revoke() (:281-312), reject() (:247-274), allowReRequest() (:322-347) and markAuthorizedLogin() (:350-353) are never called, and a source-scan test forbids those call shapes in all three new files — the same argument DoctorGlobalRolloutReadinessNonMutationTest makes about the readiness engine (:22-27 of that file), applied to a tool that actually writes. The same scan forbids DoctorDeviceWebAuthnCredential, FeatureFlagService, AndroidDoctorEnforcementScope and any locked_branch/cover token, which is how 'no credential creation, no flag mutation, no pilot-scope mutation, no branch-lock mutation' becomes a property of the file rather than a promise in a docblock.
- THE BATCH GETS ITS OWN AUDIT ACTION. DOCTOR_DEVICE_AUTHORIZATION_BULK_GRANT, entity_id null (sys_audit_logs.entity_id is nullable, migration 2026_06_03_040005:17), emitted inside the transaction so a rolled-back grant leaves no audit row claiming it happened. It does not reuse DOCTOR_DEVICE_AUTHORIZATION_APPROVED — that action means one operator judged one pair, and 42 of them under one token is a different event. Per-pair provenance is unchanged and free: the service already emits PENDING (:95-99) and APPROVED (:237-240) with the actor.
- AUTHORITY IS THE POLICY'S OWN PREDICATE, CHECKED ONCE. $actor->can('manage_doctor_device_authorizations') — literally what DoctorDeviceAuthorizationPolicy::decide() tests (:43-46), with Super Admin passing through the single global Gate::before (RepositoryServiceProvider.php:596-598). decide() itself cannot be called for a pair that has no row yet. The --actor must be a real, active, non-soft-deleted account; the Linux/SSH identity is never an application authority (LegacyRmeImportAdminCommand.php:221-233), and auth()->user() is null on the CLI, so AuditLogService::log() (:36) would otherwise record a null actor.
- HOUSE STYLE, CITED. Dry-run-then-apply with an explicit human actor and refusal-coded non-zero exits: app/Console/Commands/LegacyRmeImportAdminCommand.php (signature :74-81, dry-run branch :130-135, resolveActor :221-254, typed refusals :147-185). The mutually-exclusive --dry-run/--apply guard: LabTechnicianLinkUserCommand.php:41-46. Uppercase KEY=VALUE console output: DoctorDeviceWebAuthnReadinessCommand.php:126-131. A separate phase that verifies before it destroys anything: ClinicalEvidenceMigratePublicCommand.php:22-28.
- NO SCHEMA, NO ROUTE, NO PERMISSION, NO BINDING, NO bootstrap/app.php EDIT. The command is auto-discovered — proven empirically, not assumed: DoctorGlobalRolloutReadinessCommand.php is absent from bootstrap/app.php:34-40 yet `doctor:rollout-readiness` appears in `php artisan list --raw`. The two repository interfaces are already bound (RepositoryServiceProvider.php:294, :296). The only production edit outside the three new classes is one read method, countAll().

## Depends on
- NONE hard — this slice compiles and ships against base 215d277d alone. It adds no migration, no permission, no route and no policy change, so it can land before, after or independently of the session-lease and branch-lock slices.
- MUST STAY DISJOINT from the branch-lock slice. This command must never read or write HOME_LOCKED_BRANCH or TEMPORARY_BRANCH_COVER: owner decision O4 says 'NO branch-lock mutation', and the device's branch must never imply a doctor's branch — owner decision O1 forbids inferring a lock from a device, and section J records drg Fiitri as DOC-TLK002 whose only authorised tablet is ATG3, which is exactly the wrong inference. The non-escalation scan forbids the tokens locked_branch / home_locked_branch / branch_cover so this cannot regress if the lock lands later.
- MUST STAY DISJOINT from the sprint boundary (owner decision O5). It creates authorization rows only; it never arms DoctorAppLoginGate enforcement, never touches AndroidDoctorEnforcementScope or the bounded pilot cohort [9,15,18], and never mints a WebAuthn credential. The non-escalation scan forbids FeatureFlagService, AndroidDoctorEnforcementScope, Phase4aPilot and DoctorDeviceWebAuthnCredential in all three new files.
- ORDERING FOR THE OPERATOR, not a code dependency: run `doctor:rollout-readiness` before and after. Before, it names the 12 doctors blocked by no_device_authorization; after, those 12 should still be NOT_READY but blocked by no_webauthn_credential instead, because an authorization row is provisioning and a credential is a ceremony. Both tools compute their doctor set from the same DoctorDeviceRolloutReadinessRepositoryInterface, so the two reports are comparable by construction.

## Risks
- THE 15 x 3 = 45 GRID IS THE HAPPY CASE; THE SHAPE IS O(doctors x devices). One transaction holding 45 inserts plus 45 approvals is trivial, but at 5 branches with 15 tablets it is 225 pairs and 450 audit rows in one transaction. Mitigated by DEFAULT_MAX_PAIRS = 2000 with an explicit --max-pairs override that changes the plan token. Not mitigated: there is no chunked apply, so a very large estate would hold one long transaction. Deliberate — a partially-applied grant is worse than a slow one at this scale.
- RACE, PLAN -> APPLY. A pair can go none -> rejected between the dry run and the apply. Two layers catch it: the token recomputation refuses a moved plan, and if the row appears inside the apply, resolveOrRequest's re-read under lock (:77-81) returns it and the command aborts with race_lost rather than letting approve() surface a raw ValidationException. RESIDUAL, and it must be written down honestly: if two runs race so tightly that the unique index fires, the loser lands in the catch at :103 and calls reopenIfPermitted($winner) at :113 — which WOULD reopen a rejected-with-allowance row. Reaching it needs a rejection committed in the microseconds between the INSERT attempt and the SELECT, by a different operator, on the same pair. Bounded by the single-operator convention, not by code. State it in the sprint doc; do not pretend it is impossible.
- approve() PROMOTES A pending_approval DEVICE TO ACTIVE (DoctorDeviceAuthorizationService.php:220-229) and audits it as DOCTOR_DEVICE_ADMITTED. That branch cannot fire here because eligibility already requires STATUS_ACTIVE — but it is one relaxed predicate away from a bulk tool that admits hardware. Pin it with an explicit test (case 14) rather than trusting the ordering.
- PARTIAL COVERAGE MUST NOT BE REPORTED AS FULL. The brief's post-condition — every eligible doctor x every eligible device has exactly ONE active authorization — is UNACHIEVABLE for any pair whose slot is occupied by a REJECTED or REVOKED row, because those are never touched and the slot cannot be reused. The reconciliation therefore asserts the achievable set and prints every unresolved pair with its bucket. It must never narrow the verdict to GO while leaving the message claiming full coverage — the exact failure recorded in the memory note about a narrowed verdict leaving its message behind.
- DoctorDeviceRolloutReadinessRepository.php IS UNDER A STRUCTURAL NON-MUTATION SCAN (tests/Feature/DoctorDevice/DoctorGlobalRolloutReadinessNonMutationTest.php:71-73, forbidding '->save(', '->update(', '::create(', 'DB::' at :101-113). This slice READS from it, which is fine, and adds nothing to it. Anyone later tempted to give that repository a write helper 'while they are here' will turn the readiness engine's read-only guarantee red. Say so in the file's docblock.
- THE PLAN TOKEN IS NOT AN AUTHORIZATION. It proves the operator saw THIS plan, nothing more. Authority is the separate --actor permission check. If a future revision ever lets the token stand in for the actor, the audit trail stops naming a human. Worth a comment at the token's definition.
- --include-pending APPROVES A DECISION SOMEBODY ELSE LEFT OPEN. A PENDING row is a human's unfinished judgement, possibly deliberately parked. It is off by default and it changes the plan token, so an operator's approval explicitly names it — but it is still the one flag on this command that can override another person's pause.
- THE COMMAND IS ONLY AS HONEST AS ITS ELIGIBILITY PREDICATE. Every doctor it silently drops is a clinician who will still be blocked afterwards, and the operator will believe the grant succeeded. Hence the mandatory ineligible-doctor and ineligible-device tables with reason strings; an exclusion that is not printed is a defect, not a filter.

## Files

### NEW app/Modules/DoctorDevice/Services/DoctorDeviceBulkAuthorizationService.php
PURPOSE: The plan builder and the transactional applier. All eligibility, bucketing, counting, reconciliation and batch audit live here; the command is a thin adapter (ENT1-R001 Controller/Command -> Service -> RepositoryInterface -> Repository -> Model).

final class DoctorDeviceBulkAuthorizationService.

CONSTRUCTOR (all four already bound; NO new binding needed — app/Providers/RepositoryServiceProvider.php:293-296):
  __construct(
    private readonly DoctorDeviceRolloutReadinessRepositoryInterface $estate,   // read side, bound at RepositoryServiceProvider.php:296
    private readonly DoctorDeviceAuthorizationRepositoryInterface $authorizations, // bound at :294
    private readonly DoctorDeviceAuthorizationService $lifecycle,               // the ONLY write path
    private readonly DoctorDeviceProofService $proofs,                          // isTrustworthy() only
    private readonly AuditLogService $auditLogs,                                // app/Modules/LabOrder/Services/AuditLogService.php:28
  )

CONSTANTS:
  public const AUDIT_ACTION = 'DOCTOR_DEVICE_AUTHORIZATION_BULK_GRANT';  // NEW action, 38 chars, fits sys_audit_logs.action varchar(100) at database/migrations/2026_06_03_040005_create_sys_audit_logs_table.php:18. A batch grant is not a per-pair approval and must not borrow DOCTOR_DEVICE_AUTHORIZATION_APPROVED (ruling P8's principle).
  public const DEFAULT_MAX_PAIRS = 2000;  // blast-radius cap; production grid is 15x3=45
  Bucket names: BUCKET_NEW='new', BUCKET_ALREADY_ACTIVE='already_active', BUCKET_BLOCKED_PENDING='blocked_pending', BUCKET_BLOCKED_REJECTED='blocked_rejected', BUCKET_BLOCKED_REVOKED='blocked_revoked'.
  Ineligibility reason strings REUSE the existing vocabulary verbatim so the two tools cannot drift: DoctorGlobalRolloutReadinessService::REASON_DOCTOR_NOT_LINKED (:53), REASON_DOCTOR_INACTIVE (:55), REASON_DEVICE_NOT_ACTIVE (:61), REASON_DEVICE_IDENTITY_UNVERIFIED (:63); plus two NEW ones this tool needs: 'user_account_inactive', 'device_not_bound_to_branch'.

METHOD plan(array $doctorFilter = [], array $deviceFilter = [], bool $includePending = false, int $maxPairs = self::DEFAULT_MAX_PAIRS): DoctorDeviceBulkAuthorizationPlan
  READ-ONLY. Four queries, all existing, no new repository method:
    $accounts = $this->estate->doctorAccounts();                       // DoctorDeviceRolloutReadinessRepository.php:22-28 (role 'Doctor', soft-deleted users excluded by App\Models\User:20)
    $records  = $this->estate->doctorRecordsForUsers($userIds);        // :30-51, keyed by user_id, soft-deleted doctors excluded by App\Modules\Doctor\Models\Doctor:20
    $devices  = $this->estate->deviceEstate();                         // :67-73, with branch + webAuthnCredentials
    $existing = $this->estate->authorizationsForDoctors($doctorIds);   // :53-65, grouped by doctor_id, device eager-loaded
  DOCTOR ELIGIBLE iff ALL of:
    - the User holds role 'Doctor' (given by doctorAccounts())
    - (bool) $user->is_active === true  (App\Models\User.php:32 fillable, :59 boolean cast) -> else 'user_account_inactive'. NEW criterion, deliberately STRICTER than DoctorGlobalRolloutReadinessService, which does not check it; granting standing access to a disabled account is not something a bulk tool should do silently.
    - a Doctor record exists for that user_id -> else REASON_DOCTOR_NOT_LINKED (mirrors DoctorGlobalRolloutReadinessService.php:156-160; also satisfies ruling P6's 'an unlinked doctor record binds nobody')
    - $doctor->is_active === true -> else REASON_DOCTOR_INACTIVE (mirrors :174-178, and DoctorDeviceAuthorizationService::approve() would refuse it anyway at :186-190)
  DEVICE ELIGIBLE iff BOTH of:
    - $this->proofs->isTrustworthy($device) === true — the CANONICAL device-trust predicate at app/Modules/DoctorDevice/Services/DoctorDeviceProofService.php:197-202 = isActive() && isCryptographicallyVerified() && public_key !== null. isActive() (DoctorDevice.php:147-150) already excludes pending_approval (:38), disabled (:41) and the terminal revoked (:44), which is exactly the brief's 'ACTIVE + cryptographically_verified, terminally revoked excluded, pending/inactive excluded'. Reused rather than re-expressed so a future change to device trust reaches this tool automatically.
    - $device->branch_id !== null -> else 'device_not_bound_to_branch'. MANDATORY: DoctorDeviceAuthorizationService::approve() throws ValidationException on a branchless device at :210-214, so excluding it at plan time is what stops an apply failing halfway through.
  DELIBERATELY NOT eligibility criteria, and stated in the docblock: (a) enrollment_status — DoctorDevice::isEnrollmentVerified() (:222-225) is consulted by NEITHER isTrustworthy() NOR approve() NOR DoctorGlobalRolloutReadinessService::pathFor(); gating on it would make this tool stricter than the login gate and silently withhold access from a device that can legitimately log in. It is REPORTED per device, not gated on. (b) presence of a usable WebAuthn credential — trx_doctor_device_webauthn_credentials binds to doctor_device_id only (no doctor column), so the credential is enrolled at the tablet AFTER the authorization row exists; gating on it would defeat O4 entirely. Reported per device as credentials_usable.
  GRID = eligible doctors x eligible devices, ordered by (doctor_id, device_id) for a deterministic token. If count(grid) > $maxPairs -> throw a refusal; the operator raises it with --max-pairs and that changes the token.
  BUCKET each grid pair by reading the existing row with $existing (equivalently $this->authorizations->findPair(), DoctorDeviceAuthorizationRepository.php:37-43) and switching on status (DoctorDeviceAuthorization.php:127-146):
    no row              -> BUCKET_NEW              (this run inserts it)
    isActive()          -> BUCKET_ALREADY_ACTIVE   (idempotent no-op; this is what makes re-running produce zero duplicate rows)
    isPending()         -> BUCKET_BLOCKED_PENDING  (a human's open decision; only acted on with --include-pending)
    isRejected()        -> BUCKET_BLOCKED_REJECTED (NEVER touched — see decisions)
    isRevoked()         -> BUCKET_BLOCKED_REVOKED  (terminal, DoctorDeviceAuthorization.php:42-43)
  THE PLAN PHASE MUST NEVER CALL $this->lifecycle->resolveOrRequest(). It is a WRITE: DoctorDeviceAuthorizationService.php:69-71 routes any existing row through reopenIfPermitted() (:123-150), which for a REJECTED row carrying a live allowance flips status back to PENDING and emits an audit row. A dry run that called it would mutate. Classification uses findPair() only.
  COUNTERS on the returned plan:
    doctors                       = count(eligible doctors)
    trusted_devices               = count(eligible devices)
    existing_authorizations       = grid pairs that already hold a row, ANY status (NOT the whole table)
    proposed_new_authorizations   = count(BUCKET_NEW)
    final_expected_authorizations = existing_authorizations + proposed_new_authorizations
  INTERNAL INVARIANT asserted before the plan is returned: final_expected_authorizations === doctors * trusted_devices. Every grid pair either has a row or gets one, so the identity always holds; if it does not, the arithmetic is wrong and the plan is refused rather than printed. Production: 15 * 3 = 45; existing in-grid = 3 (doctor 21->device 3, 17->device 5, 20->device 6 per brief section J); proposed_new = 42.
    ALSO carried, so the numbers cannot be misread: out_of_grid_authorizations = rows for eligible doctors on INELIGIBLE devices (production: 2 — the revoked 21->1 and the rejected 21->4 on revoked devices 1 and 4). These are never counted in existing_authorizations and never touched.

METHOD apply(DoctorDeviceBulkAuthorizationPlan $approved, User $actor, string $confirmToken): array
  1. Re-run plan() from live data with the SAME arguments and recompute its token. If it !== $confirmToken -> throw a refusal naming both tokens. This is the STOP: the estate changed (a device revoked, a doctor deactivated, someone rejected a pair) since the operator read the block, so their approval no longer describes what would happen.
  2. $before = $this->authorizations->countAll();
  3. Single outer DB::transaction over the WHOLE run (45 rows is trivial; all-or-nothing is the point). Inside, per BUCKET_NEW pair in deterministic order:
       $auth = $this->lifecycle->resolveOrRequest($doctor, $device, $actor, DoctorDeviceAuthorization::SOURCE_ADMIN);
         // SOURCE_ADMIN is DoctorDeviceAuthorization.php:56 'Created by an administrator ahead of time' — exactly this path.
         // SAFE INSIDE THE OUTER TRANSACTION: its nested DB::transaction (:74) compiles to a SAVEPOINT and its rollback to ROLLBACK TO SAVEPOINT (vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php:302-312 via handleTransactionException :107), so the catch-then-SELECT at :103-107 leaves the enclosing PostgreSQL transaction usable. This is the section-N shape, already correct in that file.
       if (! $auth->isPending()) { throw a 'race_lost' refusal naming the pair and the status found; }  // a concurrent create won the lock at :77-81 and left something we must not approve
       $this->lifecycle->approve($auth, $actor);   // :160-244; re-reads under lock, re-validates doctor is_active (:186), device not revoked (:192), not disabled (:198), cryptographically verified (:204), branch bound (:210)
     For BUCKET_BLOCKED_PENDING pairs ONLY when $includePending: call approve($existingRow, $actor) directly — never resolveOrRequest, so reopenIfPermitted stays unreachable.
     NEVER called anywhere in this class: revoke() (:281-312), reject() (:247-274), allowReRequest() (:322-347), markAuthorizedLogin() (:350-353 — it stamps a login that did not happen).
  4. IN-TRANSACTION RECONCILIATION, before commit (a check that can only run after commit is not a check): re-read via $this->estate->authorizationsForDoctors($doctorIds) and assert
       (a) every grid pair NOT in a blocked bucket has exactly ONE row and that row isActive();
       (b) in-grid row count === $approved->finalExpectedAuthorizations;
       (c) $this->authorizations->countAll() === $before + proposed_new_authorizations  (global row delta: nothing anywhere else was inserted);
       (d) every out-of-grid row is byte-identical to the snapshot taken in step 2 (id, status, revoked_at, rejected_at) — proves nothing was revoked or reopened as a side effect.
     Any failure -> throw -> the whole transaction rolls back and the command exits non-zero.
  5. Still inside the transaction, one batch audit row:
       $this->auditLogs->log('mst_doctor_device_authorizations', null, self::AUDIT_ACTION, null, [counters, plan_token, bucket tallies, eligible doctor ids, eligible device ids, include_pending, actor_id], $actor);
     entity_id null is legal (sys_audit_logs migration :17 nullable). Inside the transaction so a rolled-back grant leaves no audit row claiming it happened. The per-pair trail is already emitted by the service: DOCTOR_DEVICE_AUTHORIZATION_PENDING at :95-99 and DOCTOR_DEVICE_AUTHORIZATION_APPROVED at :237-240, both carrying $actor.
  6. Return the post-commit reconciliation re-read for printing (unresolved pairs listed explicitly by bucket).

DOCBLOCK must state: this class never creates a WebAuthn credential, never writes a branch lock, never touches AndroidDoctorEnforcementScope or any feature flag, and never revokes.

### NEW app/Modules/DoctorDevice/Support/DoctorDeviceBulkAuthorizationPlan.php
PURPOSE: Immutable plan value object plus the PLAN_TOKEN. Separate from the service so the token derivation is a single testable function and the plan cannot be edited between being printed and being approved.

final readonly class DoctorDeviceBulkAuthorizationPlan.

PROPERTIES: /** @var list<array{user_id:int,doctor_id:int,doctor_code:?string,doctor_name:string}> */ public array $doctors; /** @var list<array{device_id:int,device_name:string,branch_id:int,branch_code:?string,enrollment_status:string,credentials_usable:int}> */ public array $devices; /** @var list<array{doctor_id:int,device_id:int,bucket:string,authorization_id:?int,status:?string}> */ public array $pairs; public array $ineligibleDoctors; public array $ineligibleDevices; public int $doctors_count; public int $trusted_devices; public int $existing_authorizations; public int $proposed_new_authorizations; public int $final_expected_authorizations; public int $out_of_grid_authorizations; public bool $includePending; public int $maxPairs;

public function token(): string
  return substr(hash('sha256', json_encode([
      'v' => 1,
      'doctors' => sorted list of doctor_id,
      'devices' => sorted list of device_id,
      'pairs'   => sorted list of "{doctor_id}:{device_id}:{bucket}:{status}",
      'counts'  => [doctors_count, trusted_devices, existing_authorizations, proposed_new_authorizations, final_expected_authorizations],
      'include_pending' => includePending,
  ], JSON_THROW_ON_ERROR)), 0, 16);
  Deterministic and order-independent by construction (everything sorted before hashing). Truncated to 16 hex chars so an operator can retype it; the space is still 2^64 and the token is an anti-staleness check, not a secret.

public function isEmptyGrant(): bool  => $this->proposed_new_authorizations === 0 && no pending selected.
public function blocked(): array      => pairs grouped by the three blocked buckets, for the UNRESOLVED report.
No setters. No database access. No request access.

### NEW app/Console/Commands/DoctorDeviceBulkAuthorizeCommand.php
PURPOSE: The operator surface. Thin adapter over the service in the exact shape of LegacyRmeImportAdminCommand: dry-run unless --apply, an explicit human --actor, refusal codes, non-zero exit on every refusal so a wrapper script cannot mistake one for success.

final class DoctorDeviceBulkAuthorizeCommand extends Command

SIGNATURE (name in the existing `doctor:` namespace beside doctor:rollout-readiness, app/Console/Commands/DoctorGlobalRolloutReadinessCommand.php:28. Deliberately NOT named '...-fleet': O4 is MODEL A, an explicit per-pair audit boundary, not fleet-wide implicit trust):

    protected $signature = 'doctor:device-bulk-authorize
        {--actor= : Id or email of the approver this grant is attributed to (required)}
        {--doctor=* : Restrict to these doctor ids or codes (default: every eligible doctor)}
        {--device=* : Restrict to these device ids (default: every eligible device)}
        {--include-pending : Also approve pairs that already hold a PENDING request}
        {--max-pairs= : Raise the blast-radius cap above 2000 (changes the plan token)}
        {--dry-run : Preview only (default)}
        {--apply : Perform the grant}
        {--confirm-plan= : The PLAN_TOKEN printed by the preceding dry run (required with --apply)}
        {--json : Emit the plan or outcome as JSON}';

    protected $description = 'Grant every eligible doctor an ACTIVE authorization on every eligible clinic device (dry-run unless --apply; never revokes, never reopens a rejected pair).';

REGISTRATION: none needed. app/Console/Commands is auto-discovered — verified empirically: DoctorGlobalRolloutReadinessCommand.php is not listed in bootstrap/app.php:34-40 yet `doctor:rollout-readiness` appears in `php artisan list --raw`. Do NOT edit bootstrap/app.php.
Not blocked by the production console guard: config/release_safety.php:110-117 blocks only the REPL, and ForbiddenConsoleCommandGuard::shouldBlock() (app/Support/Deploy/ForbiddenConsoleCommandGuard.php:74-86) is an exact-name match. This command existing is precisely why the grant must not be done in tinker.

handle(DoctorDeviceBulkAuthorizationService $bulk): int — ORDER OF CHECKS (settle the invocation before anything reaches the services, LegacyRmeImportAdminCommand.php:118-125):
  1. $apply = (bool) $this->option('apply'); if ($apply && $this->option('dry-run')) -> error 'Pass either --dry-run or --apply, not both.' return self::INVALID;  // verbatim shape of LabTechnicianLinkUserCommand.php:41-46
  2. resolveActor(): copy LegacyRmeImportAdminCommand::resolveActor() (:221-254) verbatim in shape — required, id-or-email, soft-deleted excluded by the model scope, refuse when ! $user->is_active. Then ONE authority check: if (! $actor->can('manage_doctor_device_authorizations')) -> refuse ACTOR_NOT_AUTHORIZED, self::FAILURE. That is the exact predicate DoctorDeviceAuthorizationPolicy::decide() uses (app/Modules/DoctorDevice/Policies/DoctorDeviceAuthorizationPolicy.php:43-46); a permission check rather than a role comparison, and Super Admin still passes through the single global Gate::before (app/Providers/RepositoryServiceProvider.php:596-598). The policy's decide() cannot be used directly for BUCKET_NEW pairs because no model instance exists yet.
     The Linux/SSH identity is never an application authority — no --force, no fallback to auth()->user() (null on CLI, so AuditLogService::log() at :36 would record a null actor).
  3. Build the plan: $plan = $bulk->plan(doctorFilter, deviceFilter, includePending, maxPairs). Any refusal (grid too large, arithmetic invariant broken) -> print, return self::FAILURE.
  4. PRINT. The five mandated lines are emitted FIRST, as a contiguous block, verbatim and in this order, using the uppercase KEY=VALUE form of DoctorDeviceWebAuthnReadinessCommand.php:126-131:

        DOCTORS=<n>
        TRUSTED_DEVICES=<n>
        EXISTING_AUTHORIZATIONS=<n>
        PROPOSED_NEW_AUTHORIZATIONS=<n>
        FINAL_EXPECTED_AUTHORIZATIONS=<n>

     Then, clearly after that block: PLAN_TOKEN=<16 hex>, MODE=DRY_RUN|APPLY, INCLUDE_PENDING=true|false, OUT_OF_GRID_AUTHORIZATIONS=<n>, ALREADY_ACTIVE=<n>, BLOCKED_PENDING=<n>, BLOCKED_REJECTED=<n>, BLOCKED_REVOKED=<n>; then $this->table() of the EXACT doctor list (user_id, doctor_id, code, name), the EXACT eligible device list (device_id, name, branch_code, enrollment_status, credentials_usable), the EXACT proposed row additions (doctor_id, device_id), and an UNRESOLVED table naming every blocked pair with its bucket. Then the ineligible doctors and devices with their reason strings, so an exclusion is never silent.
  5. If ! $apply: print 'DRY-RUN — nothing written. Re-run with --apply --confirm-plan=<PLAN_TOKEN> to grant.' and return self::SUCCESS.
  6. If $apply and --confirm-plan is empty or !== $plan->token(): refuse PLAN_NOT_CONFIRMED / PLAN_STALE, print both tokens, return self::FAILURE. Nothing is written.
  7. $bulk->apply($plan, $actor, $token); re-print the five-line block from the post-commit reconciliation plus RECONCILED=true|false and the residual UNRESOLVED table. Any thrown refusal -> print, return self::FAILURE.

EXIT CONTRACT: 0 only when a dry run completed or an apply committed AND reconciled. INVALID for a malformed invocation; FAILURE for unknown/inactive/unauthorized actor, missing or stale --confirm-plan, grid over the cap, a lost race, or a failed reconciliation.

--json emits the same structure as one object (LabTechnicianLinkUserCommand.php:64-68), never a per-line writeln, because Pest's expectsOutputToContain consumes one writeln per expectation.

### EDIT app/Modules/DoctorDevice/Interfaces/DoctorDeviceAuthorizationRepositoryInterface.php
PURPOSE: Add the one read method the global row-delta reconciliation needs.

Anchor: the existing `public function countPending(): int;` at line 42 (its docblock begins at :38). Add immediately after it:

    /**
     * Every row, whatever its status.
     *
     * The bulk grant's reconciliation needs a GLOBAL delta, not a scoped one:
     * `before + proposed_new === after` is what proves the run inserted exactly
     * the rows it announced and touched nothing outside its grid. countPending()
     * cannot answer that, and a scoped count would be blind to precisely the
     * row a defect would create somewhere else.
     */
    public function countAll(): int;

No other change. Do not add a write method here — the write path stays DoctorDeviceAuthorizationService.

### EDIT app/Modules/DoctorDevice/Repositories/DoctorDeviceAuthorizationRepository.php
PURPOSE: Implement countAll().

Anchor: after the closing brace of `countPending()`, which ends at line 84 (method opens at :79). Add:

    public function countAll(): int
    {
        return DoctorDeviceAuthorization::query()->count();
    }

Safe to add here: this file is NOT one of the three under the structural non-mutation scan (tests/Feature/DoctorDevice/DoctorGlobalRolloutReadinessNonMutationTest.php:71-73 lists only DoctorGlobalRolloutReadinessService.php, DoctorGlobalRolloutReadinessCommand.php and DoctorDeviceRolloutReadinessRepository.php).

### NEW tests/Feature/DoctorDevice/DoctorDeviceBulkAuthorizationTest.php
PURPOSE: Behavioural contract. File name begins with DoctorDevice so the `DoctorDevice` token in the critical filter selects it in both gate variants.

Pest, `beforeEach(fn () => seedAccessControl());` as in tests/Feature/DoctorDevice/DoctorGlobalRolloutReadinessCommandTest.php:43-45. File-unique helper prefix `bulkAuth*`, fixtures duplicated rather than borrowed from a sibling file (the reason is given at that file's :47-53). Drive everything through Artisan::call + Artisan::output(), never expectsOutputToContain, for the reason recorded at :21-24.

MUST PIN:
 1. Dry run writes NOTHING: snapshot mst_doctor_device_authorizations and sys_audit_logs counts before and after; both unchanged; exit 0.
 2. Dry run does not reopen a REJECTED pair carrying a LIVE re-request allowance. Build the fixture through the real lifecycle: reject() then allowReRequest() (DoctorDeviceAuthorizationService.php:247, :322), then dry-run, then assert the row is still STATUS_REJECTED with re_request_allowed_at intact. This is the regression that would fire if the plan ever called resolveOrRequest().
 3. The five-line block is emitted verbatim and contiguously, in order, with the right numbers on a 3-doctor x 2-device fixture.
 4. --apply without --confirm-plan writes nothing and exits non-zero.
 5. --apply with a STALE token (revoke a device between the dry run and the apply) writes nothing and exits non-zero.
 6. --apply with the correct token creates exactly proposed_new rows, every one STATUS_ACTIVE with approved_by = the actor, and exits 0.
 7. IDEMPOTENCE: a second full dry-run+apply cycle reports PROPOSED_NEW_AUTHORIZATIONS=0 and creates zero rows. Zero duplicates — the UNIQUE(doctor_id, doctor_device_id) index mst_dd_authorizations_pair_unique (database/migrations/2026_09_03_110001_create_mst_doctor_device_authorizations_table.php:92) is never violated because BUCKET_ALREADY_ACTIVE is skipped.
 8. A REVOKED pair is reported blocked_revoked and is byte-identical afterwards (status, revoked_at, revoked_by, revoked_reason).
 9. A REJECTED pair (both with and without a live allowance) is reported blocked_rejected and is byte-identical afterwards.
10. A PENDING pair is untouched by default; with --include-pending it becomes ACTIVE, and the token differs between the two invocations.
11. EXCLUSIONS, one case each, asserting the pair never appears in the grid: device status pending_approval; device status disabled; device status revoked; device identity_state unverified; device public_key null; device branch_id null; doctor is_active false; user is_active false; user with the Doctor role but no mst_doctors row.
12. An actor holding only `view_doctor_device_authorizations` is refused; one holding `manage_doctor_device_authorizations` succeeds; a Super Admin succeeds via Gate::before.
13. NO ESCALATION on apply: assert trx_doctor_device_webauthn_credentials count unchanged, every DoctorDevice row's status/identity_state/branch_id unchanged, and the enforcement flag and pilot scope unchanged (read them the way EnforcementPostureGovernanceTest does).
14. A device in status pending_approval can never be promoted by this command: assert it is excluded at plan time, which is what stops approve()'s promotion branch (DoctorDeviceAuthorizationService.php:220-229) ever firing here.
15. RECONCILIATION FAILS CLOSED: with the row-count assertion forced to fail (bind a repository double whose countAll() lies), the transaction rolls back, zero rows persist, and the exit is non-zero.
16. The batch audit row exists exactly once with action DOCTOR_DEVICE_AUTHORIZATION_BULK_GRANT, entity_id null, performed_by = the actor; and the per-pair PENDING/APPROVED rows exist for every new pair.

### NEW tests/Feature/DoctorDevice/DoctorDeviceBulkAuthorizationNonEscalationTest.php
PURPOSE: Structural proof that the command CANNOT revoke, reopen, mint credentials, arm enforcement or write a branch lock — the primitives are absent from the source, so a future edit that adds one fails here rather than in a clinic.

Modelled on tests/Feature/DoctorDevice/DoctorGlobalRolloutReadinessNonMutationTest.php (helper at :55-59, file list at :69-74, assertion loop at :82-99). Scanned files:
  app/Console/Commands/DoctorDeviceBulkAuthorizeCommand.php
  app/Modules/DoctorDevice/Services/DoctorDeviceBulkAuthorizationService.php
  app/Modules/DoctorDevice/Support/DoctorDeviceBulkAuthorizationPlan.php
Forbidden substrings (service-call shaped, so the plan may still READ the status constants it classifies on):
  '->revoke(', '->reject(', '->allowReRequest(', '->markAuthorizedLogin(', '->forceFill(', '->delete(', '->forceDelete('
  'DoctorDeviceWebAuthnCredential', 'WebAuthnCeremonyFactory', 'WebAuthnRelyingParty'
  'FeatureFlagService', 'AndroidDoctorEnforcementScope', 'Phase4aPilot'
  'locked_branch', 'home_locked_branch', 'branch_cover'
  'file_put_contents', 'shell_exec', 'proc_open', 'exec(', 'Http::', 'curl_'
  'request(', '$_GET', '$_POST', '->input('   // nothing about this grant may be shaped over HTTP
Plus: expect the Plan support class to contain no 'DB::' and no '::query('.
Use expect($source)->not->toContain($needle, $message) with ONE needle per call — toContain() is variadic, so a second argument becomes another needle rather than a message (the trap recorded at tests/Feature/Cicd/CriticalGateSuiteCoverageTest.php:97-99).

### EDIT config/ci_runner.php
PURPOSE: Declare both new suites mandatory, so their coverage by the critical gate is a governance decision rather than a side effect of where the files happen to live.

Anchor: the `'critical_gate_mandatory_suites' => [` array opening at line 172; append two entries after the existing DoctorDevice block that ends at line 200. Follow the comment convention of the entry at :186-190:

    // DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 (O4) — the bulk device grant.
    // Selected by the `DoctorDevice` token through the file name, and declared
    // here so that coverage is a decision rather than a side effect.
    'tests/Feature/DoctorDevice/DoctorDeviceBulkAuthorizationTest.php',

    // The structural proof that the grant cannot revoke, reopen a refusal, mint
    // a credential or arm enforcement. Losing it silently would be losing the
    // guarantee, not just a test.
    'tests/Feature/DoctorDevice/DoctorDeviceBulkAuthorizationNonEscalationTest.php',

No workflow edit is needed: the `DoctorDevice` token is already present in BOTH critical-gate variants (.github/workflows/foundation-evidence-gates.yml:422 hosted and :690 self-hosted), and both new file names begin with DoctorDevice. tests/Feature/Cicd/CriticalGateSuiteCoverageTest.php:49-67 will assert that both are selected by both variants.
