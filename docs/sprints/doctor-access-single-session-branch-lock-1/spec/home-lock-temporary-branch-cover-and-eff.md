# SLICE: Home lock, temporary branch cover, and effective-branch resolution (read hook, write hook, selector hook, degradation)

## Decisions
- EFFECTIVE_CLINICAL_BRANCH = active approved cover target (by current timestamps) ELSE home lock ELSE null. Implemented in ONE new pure service, App\Modules\DoctorBranchLock\Services\DoctorEffectiveBranchResolver, whose public contract is branchIdFor(?User): ?int plus resolve(?User): DoctorEffectiveBranch. It takes a User and nothing else — no Request, no device id, no branch argument — issues at most 3 bounded reads and performs zero writes.
- BranchContext::forUser() MUST NOT CHANGE. Verified, not assumed: across app/Modules/{MedicalRecord,Odontogram,Consent,Prescription,RME} the string 'BranchContext' appears only in two comments (MedicalRecordService.php:35, OdontogramService.php:147) and in no executable line. The single branchContext->requireId() on the clinic-visit surface is ClinicVisitService::find() at :230-233, and grep over app/, routes/ and tests/ finds ZERO callers of it — it is dead code. The only other doctor-reachable use is CrossBranchPatientLookupService.php:169, which calls the nullable id() to render a cosmetic is_current_branch label (:177) and is not an access boundary. So hooking BranchContext would narrow nothing, and it would import ruling P#9's RuntimeException-500 risk from requireId() (BranchContext.php:54-63) for no benefit. The second half of the earlier verdict also holds: link 1 of the chain (:67) is UserOnlineContextService::activeContextBranchId, the doctor's own self-selected context — sourcing a lock through it would let the locked doctor self-switch, which is why the lock needs its own store keyed on doctor identity.
- THE READ HOOK IS A NEW METHOD, RmeWorkingBranchScope::operationalBranchIdsFor(), NOT an edit to branchIdsFor(). branchIdsFor() (:68-78) is what allows() (:112-119) delegates to, so editing it silently narrows six per-record authorization sites (ClinicVisitPolicy:186, RmeVisitConsentPolicy:101, RmeVisitConsentService:356, RmeInvoicePolicy:54, RmePaymentService:404, PrescriptionWhatsAppDeliveryService:52) — ruling P#2's patient-safety defect — AND the patient-wide odontogram archive read at OdontogramService:86. The additive method satisfies CRITICAL 2 structurally rather than by promise: allows() keeps calling the unnarrowed branchIdsFor(), so per-record authorization cannot regress even by accident.
- THE WRITE HOOK IS ClinicVisitService::resolveBranchId() (:395-420), inserted after the RME-enabled assertion (:413-417) and before the return (:419). It is the sole convergence of the two forged-branch vectors — $data['branch_id'] (:402) and $data['new_patient']['branch_id'] (:400) — it runs at :242 inside the transaction opened at :237 and before resolvePatient() at :245, so the throw blocks the patient creation too, and ValidationException is already the local idiom. The hole is real: Doctor holds manage_clinic_visits (RoleSeeder.php:259) and ClinicVisitPolicy::create is exactly that check (:34-37, :168-171).
- LOCK ORDER IS ALWAYS ELIGIBILITY FIRST, LOCK SECOND, at both the write hook and the selector boundary. This is the codebase's written rule, not a preference: DailyBranchContextService::assertSelectable is documented as being called only after eligibility so that 'an approved switch can never become a route to an ineligible branch' (DailyBranchContextService.php:122-126).
- TIMEZONE: starts_at/ends_at are stored as UTC INSTANTS and compared instant-to-instant with CarbonImmutable::now(), which Carbon::setTestNow() controls. ClinicalClock is used only where a WALL CLOCK is genuinely meant — parsing the approver's typed local datetime as Asia/Makassar before storage, and rendering it back. Justification: config/app.php:68 pins the storage clock to UTC and config/clinical.php says in prose that technical instants keep that architecture while clinical.timezone defines only the calendar day; and ClinicalClock::timezone() throws by design on a bad config (ClinicalClock.php:71-81), which is right for a document-eligibility date but would turn a config typo into a total doctor outage if it ran on every protected request.
- DEGRADATION IS UNSET-EQUIVALENCE, NOT AN EXCEPTION (ruling P#9). If the cover target or the home branch is absent from BranchService::rmeEnabledIds() (active AND is_rme_enabled — BranchRepository.php:40-46), resolve() returns source 'degraded' with branchId null and isLocked() false. Every consumer then behaves exactly as it does for an UNSET doctor, so no RuntimeException and no 500 is reachable. A dead COVER degrades to UNSET and does NOT fall through to home — falling through would move a covering doctor mid-cover with no audit and no session invalidation, which owner decision Q forbids. The reason travels on the value object; the audit row is written once at the rare WRITE hook, never per read, because AuditLogService::log() INSERTs on every call (app/Modules/LabOrder/Services/AuditLogService.php:38-48).
- IDENTITY COMES FROM DoctorIdentityResolver (mst_doctors.user_id, DoctorIdentityResolver.php:35-40), NOT DoctorUserResolver — the latter also returns null for an inactive doctor (DoctorUserResolver.php:35-37), which would silently UNLOCK a deactivated doctor. Role eligibility comes from UserOnlineContextService::requiresDoctorContext (:28-31), so the Owner / Super Admin / Supervisor RME exemption at :58-61 keeps governance accounts that also hold the Doctor role unlockable. An unlinked Doctor account resolves to source 'unlinked' with a null branch (ruling P#6).
- THE SELECTOR CHANGE IS ONE CONTROLLER VARIABLE AND ZERO JAVASCRIPT. Narrowing $doctorAllowedBranches at OnlineContextController.php:55 narrows both the <select> (select.blade.php:51-53) and the Alpine payload, because line :41 seeds the component with roomsByBranch->only($doctorAllowedBranches->pluck('id')), so the other branches' rooms disappear with the branches. The component onlineContextDoctorForm() (:145-157) is inline in the same Blade and is untouched. The server boundary is a separate, mandatory edit in UserOnlineContextService::startDoctorSession after the pivot check at :277-281 — that check reads the model's pivot, so the view narrowing does not enforce anything.
- THE FEATURE FLAG MUST AND both flags in code. app/Services/Foundation/FeatureFlagService.php enforces nothing from a flag's dependencies array — it is copied into the hydrated payload at :202 and read nowhere else — so DoctorEffectiveBranchResolver::enabled() returns flags->enabled('doctor.branch_lock') && flags->enabled('doctor.single_session_lease'), and the declared dependency is documented as descriptive only (ruling P#7). Dotted keys must go through FeatureFlagService, never config() (the precedent and its warning: LegacyRmeFeatureGuard.php:51).
- CARDINALITY: home lock is UNIQUE(doctor_id) with home_branch_id NULLABLE, where NULL and no-row both mean UNSET. That row doubles as the per-doctor mutex the approval and cover transactions lockForUpdate on. Covers get a partial unique index (doctor_id) WHERE status='pending' as a double-submit guard, created in the SAME migration as the table per section K; the no-overlapping-approved-covers invariant is NOT expressible as a unique index and is enforced by the half-open predicate starts_at < :new_ends_at AND ends_at > :new_starts_at under that mutex. Any unique-violation catch must sit outside the failed statement's transaction and use the portable detector copied from DailyBranchContextService.php:219-233 (23505 plus message matching), per section N.
- NOT HOOKED, deliberately: ClinicVisitPolicy and every per-record ability (ruling P#2); DoctorClinicalBranchResolver, whose only two consumers are LegacyRmeWorkspaceScope.php:59 and LegacyOdontogramWorkspaceScope.php:54, both legacy archive scopes (owner decision O3); RmeWorkingBranchScope::isContextBound/activeBranchId/branchIdsFor/allows/narrow/resolve; DailyBranchContextService::LOCKED_ROLE_CONTEXTS (:69-72); BranchContext.
- COMPLETE RmeWorkingBranchScope CONSUMER ENUMERATION (ruling P#12 — the plan said 7, source has 12 files with call sites plus a 13th holding an unused import). By method: branchIdsFor() — RmeInvoiceController.php:208, RmeReportController.php:488, RmeInvoiceService.php:40, OdontogramService.php:86, ClinicVisitService.php:115. allows() — ClinicVisitPolicy.php:186, RmeVisitConsentPolicy.php:101, RmeVisitConsentService.php:356, RmeInvoicePolicy.php:54, RmePaymentService.php:404, PrescriptionWhatsAppDeliveryService.php:52, RmeReportController.php:446. resolve() — ClinicVisitService.php:76, RmeReportController.php:469. isContextBound()/activeBranchId() — PatientSelectorSearchService.php:245. Unused import only: RmeInvoiceService/RmeReportDateScope.php:7 (docblock reference at :45, no call). Comment-only mention: DailyBranchContextService.php:63. Of these, EXACTLY TWO are changed by this slice, both in ClinicVisitService (:76, :115).

## Depends on
- FLAG 'doctor.single_session_lease' is DEFINED BY THE SINGLE-SESSION LEASE SLICE, and this resolver's enabled() hard-requires it. If both slices land together only one may add the key to config/feature_flags.php, or the file conflicts. If the lease slice slips, this slice ships inert — which is the intended fail-safe, not a bug.
- THE APPROVAL SLICE owns every WRITE to mst_doctor_branch_locks and trx_doctor_branch_covers: initial assignment (UNSET → branch), permanent transfer (A → B), cover request/approve/reject/cancel, the Super-Admin-or-Supervisor-RME authority, and ruling P#10 (re-assert the doctor under the row lock at decision time) and P#11 (show the approver the subject's live presence). This slice creates the tables and the read model only, and exposes hasActiveCoverForDoctor(int $doctorId): bool for owner decision Q's rule 'block permanent transfer APPROVAL while a cover is active'. That block MUST be implemented and tested by the approval slice; nothing here enforces it.
- THE LEASE / MIDDLEWARE SLICE consumes DoctorEffectiveBranchResolver::branchIdFor($user) at two points per owner decision Q: record effective_branch_id on the lease at claim time, and recompute-and-compare on every protected request, invalidating the session on a difference. That is the mechanism by which cover activation, cover expiry and transfer approval all invalidate the session with no scheduler. This slice guarantees only that branchIdFor() is pure, deterministic from current timestamps, and never throws.
- A SCHEDULED HOUSEKEEPING COMMAND may tidy or report expired covers, but correctness must never depend on it (owner decision Q). If such a command is added it belongs to the ops slice, and its absence must not change a single resolver test result — the resolver tests are written to prove exactly that by driving expiry with Carbon::setTestNow() and no job run.
- CI SUITE SELECTION: the two new test files must match a token in the critical filter of .github/workflows/foundation-evidence-gates.yml. Neighbouring AccessControl suites are selected by the DailyBranchContext token; a new file whose name matches no token runs in no gate. Whoever owns the workflow edit for this sprint must add a DoctorBranchLock (or DoctorEffectiveBranch) token.

## Risks
- NEW FINDING, NOT IN THE BRIEF — the odontogram archive. OdontogramService::patientHistory calls RmeWorkingBranchScope::branchIdsFor() at app/Modules/Odontogram/Services/OdontogramService.php:86 for a patient-WIDE (not single-visit) read; its own comment at :72-84 explains it is the module's first such read. Narrowing branchIdsFor() would cut a locked doctor off from their patient's odontogram history at other branches, directly violating owner decision O3. This is a second, independent reason the read hook must be an additive method — the brief's CRITICAL list names only the ClinicVisitPolicy path.
- CORRECTION TO BRIEF SECTION E ITEM 1. It predicts tests/Feature/AccessControl/DailyBranchContextLockTest.php:198-217 ('leaves a doctor free to change branch and room') must be rewritten. It must NOT. That test creates a doctor via doctorWithOnlineContext() with no lock row, so the doctor is UNSET, isLocked() is false, and the assertion at :216 that BranchContext resolves to branch B stays true. Rewriting it would destroy the very regression that proves UNSET doctors keep legacy behaviour. Section E item 2 (LOCKED_ROLE_CONTEXTS, :219-227) is correct that it stays green — this slice adds nothing to that constant.
- THE DEGRADATION / LEASE INTERACTION IS UNRESOLVED AND MUST BE DECIDED BY THE LEASE SLICE. If a branch is deactivated, branchIdFor() flips from a branch id to null for every doctor locked to it. Under owner decision Q's recompute-and-compare mechanism that is a difference, so every affected doctor would be logged out simultaneously — a mass eviction triggered by a master-data edit. Defensible (the authority genuinely changed) but it must be an explicit decision with a test, not a side effect discovered in production. Recommend the comparison treat a transition INTO 'degraded' as a warning rather than an invalidation, since degraded is UNSET-equivalent and UNSET is the legacy state nobody is evicted for.
- THE COVER WINDOW IS AN INSTANT INTERVAL, SO IT CAN EXPIRE MID-CONSULTATION. Section O originally pinned expiry to the end of the clinical day precisely to avoid that; section Q supersedes it with explicit starts_at/ends_at and adds session invalidation on expiry. The consequence is real and must be stated in the sprint doc and the approver UI: a cover ending at 15:00 logs the covering doctor out at 15:00, potentially mid-patient. Mitigation is procedural (approvers should set ends_at at a shift boundary), not technical, and the approver form should default ends_at to end-of-clinical-day in Asia/Makassar to make the safe choice the easy one.
- PARTIAL-INDEX FRAGILITY. Section K proves raw sqlite preserves a partial index across ALTER TABLE ADD COLUMN, but SATUSEHAT-4D observed Laravel's schema grammar flattening one when a constrained() FK column caused a table rebuild — which is why 2026_07_19_100010_reassert_single_primary_pilot_partial_unique_index.php exists. Mitigations, both required: create the index in the SAME migration as the table, and add a schema test asserting the index still carries its WHERE clause (query sqlite_master.sql on sqlite, pg_indexes.indexdef on pgsql).
- 'PURE' STILL MEANS 3 QUERIES ON EVERY PROTECTED DOCTOR REQUEST once the lease middleware calls branchIdFor() per request. All three are single-row primary/unique-key lookups on indexed columns and are cheap, but they are not free. Do NOT reach for a static memo (it would survive across users in a queue worker or a test run); if profiling demands it, memoise on a per-request instance keyed by (int) $user->id.
- THE PENDING-COVER PARTIAL INDEX IS ONE PENDING PER DOCTOR, WHICH IS A PRODUCT CONSTRAINT AS WELL AS A GUARD. A doctor with a pending cover for next Tuesday cannot have a second pending cover for the following Friday until the first is decided. That may be exactly right for a pilot, but it is a behaviour the owner should confirm rather than discover.
- AN UNLINKED DOCTOR IS UNLOCKABLE, BY DESIGN AND WITH A COST. Production has doctors whose accounts are not linked via mst_doctors.user_id (the recorded users-18-vs-doctor-21 hazard, and the operator-mapping work that preceded it). Those accounts resolve to source 'unlinked' and stay on legacy behaviour, so the lock cannot be applied to them at all. That is ruling P#6's fail-closed choice for the approval path, but on the enforcement path it fails OPEN. The gap must be visible: a readiness surface should report the count of Doctor-role accounts that hold no mst_doctors.user_id link, reusing the existing rme:doctor-performance-access-audit finding rather than inventing a second detector.

## Files

### NEW database/migrations/2026_09_10_100001_create_mst_doctor_branch_locks_table.php
PURPOSE: HOME_LOCKED_BRANCH store. Per-doctor, cardinality 1, and simultaneously the per-doctor MUTEX row the approval/cover transactions serialize on.

Schema::create('mst_doctor_branch_locks'): $table->id(); $table->foreignId('doctor_id')->constrained('mst_doctors')->cascadeOnUpdate()->restrictOnDelete(); $table->unique('doctor_id') — cardinality 1 per doctor; $table->foreignId('home_branch_id')->nullable()->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete(); $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete(); $table->timestamp('assigned_at')->nullable(); $table->text('assignment_reason')->nullable(); $table->timestamps(); $table->index('home_branch_id'). NO deleted_at. NO backfill statement of any kind (owner decision O1). home_branch_id NULLABLE is the whole compatibility story: NULL == UNSET == 'no row', and the resolver must treat both identically. FK targets verified: mst_doctors exists with softDeletes (database/migrations/2026_06_03_030002_create_mst_doctors_table.php:14-28) so restrictOnDelete is never triggered by a soft delete; mst_branches is the branch table (database/migrations/2026_08_29_100002_create_trx_branch_change_requests_table.php:54). The row is created lazily by the approval slice via firstOrCreate INSIDE its transaction and then lockForUpdate'd; a row with home_branch_id NULL is a mutex, NOT an assigned branch, so it does not violate 'no backfill'.

### NEW database/migrations/2026_09_10_100002_create_trx_doctor_branch_covers_table.php
PURPOSE: TEMPORARY_BRANCH_COVER: explicit approved temporary operational authority with starts_at/ends_at. ACTIVE and EXPIRED are DERIVED, never persisted (owner decision Q).

Schema::create('trx_doctor_branch_covers'): $table->id(); foreignId('doctor_id')->constrained('mst_doctors')->cascadeOnUpdate()->restrictOnDelete(); foreignId('target_branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete(); $table->timestamp('starts_at'); $table->timestamp('ends_at'); $table->text('reason'); $table->string('status', 16)->default('pending'); foreignId('requested_by_user_id')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete(); $table->timestamp('requested_at'); foreignId('approved_by_user_id')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete(); $table->timestamp('approved_at')->nullable(); $table->text('decision_note')->nullable(); foreignId('cancelled_by_user_id')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete(); $table->timestamp('cancelled_at')->nullable(); $table->text('cancel_reason')->nullable(); $table->timestamps(); indexes: ['doctor_id','status','starts_at'], ['status','ends_at'], 'target_branch_id'.

PERSISTED STATUSES ARE EXACTLY: pending, approved, rejected, cancelled. There is NO 'active' and NO 'expired' column and NO expiry job — Q forbids redundant state a delayed job would have to maintain.

IN THE SAME MIGRATION, after Schema::create (section K rule: never a separate migration — the SATUSEHAT-4D flattening trap at database/migrations/2026_07_19_100003_add_single_active_wave_membership_partial_unique_index.php is exactly the separate-migration shape that failed):
  $driver = Schema::getConnection()->getDriverName();
  if (in_array($driver, ['pgsql','sqlite'], true)) {
      DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS trx_doctor_branch_cover_pending_uq ON trx_doctor_branch_covers (doctor_id) WHERE status = 'pending'");
  }
Copied verbatim in shape from database/migrations/2026_08_29_100002_create_trx_branch_change_requests_table.php:90-94 and the driver guard from 2026_07_19_100003:30-39.

WHAT THE INDEX DOES AND DOES NOT DO: it is a double-submit guard for one PENDING request per doctor. It CANNOT express 'no two APPROVED covers whose [starts_at, ends_at) intervals overlap' — no unique index can express interval overlap. That invariant is enforced in the approval transaction by lockForUpdate on the doctor's mst_doctor_branch_locks row (the mutex) plus the half-open overlap predicate: exists(status='approved' AND cancelled_at IS NULL AND starts_at < :new_ends_at AND ends_at > :new_starts_at). Do not claim the index enforces it.

down(): drop the index under the same driver guard, then Schema::dropIfExists.

### NEW app/Modules/DoctorBranchLock/Models/DoctorBranchLock.php
PURPOSE: Eloquent model for mst_doctor_branch_locks.

protected $table = 'mst_doctor_branch_locks'; $fillable = ['doctor_id','home_branch_id','assigned_by_user_id','assigned_at','assignment_reason']; casts: 'doctor_id'=>'integer','home_branch_id'=>'integer','assigned_at'=>'datetime'. Relations: doctor() belongsTo App\Modules\Doctor\Models\Doctor, homeBranch() belongsTo App\Modules\Branch\Models\Branch (the canonical Branch model — NOT App\Models\Branch). Helper: public function isUnset(): bool { return $this->home_branch_id === null; }. No SoftDeletes.

### NEW app/Modules/DoctorBranchLock/Models/DoctorBranchCover.php
PURPOSE: Eloquent model for trx_doctor_branch_covers, carrying the DERIVED active predicate.

protected $table = 'trx_doctor_branch_covers'; status constants STATUS_PENDING='pending', STATUS_APPROVED='approved', STATUS_REJECTED='rejected', STATUS_CANCELLED='cancelled'. casts: starts_at/ends_at/requested_at/approved_at/cancelled_at => 'datetime'; doctor_id/target_branch_id => 'integer'. public function isActiveAt(\DateTimeInterface $instant): bool { return $this->status === self::STATUS_APPROVED && $this->cancelled_at === null && $this->starts_at <= $instant && $this->ends_at > $instant; } — HALF-OPEN on purpose so a cover ending at 17:00 and one starting at 17:00 can never both be active. Relations: doctor(), targetBranch(), approvedBy().

### NEW app/Modules/DoctorBranchLock/Interfaces/DoctorBranchLockRepositoryInterface.php
PURPOSE: Repository boundary for the home lock (enterprise baseline ENT1-R001: Service must not query a Model directly).

findForDoctor(int $doctorId): ?DoctorBranchLock; lockForDoctor(int $doctorId): ?DoctorBranchLock (SELECT ... FOR UPDATE, used only by the approval slice); firstOrCreateForDoctor(int $doctorId): DoctorBranchLock; update(DoctorBranchLock $lock, array $attributes): DoctorBranchLock.

### NEW app/Modules/DoctorBranchLock/Interfaces/DoctorBranchCoverRepositoryInterface.php
PURPOSE: Repository boundary for covers.

activeForDoctorAt(int $doctorId, \DateTimeInterface $instant): ?DoctorBranchCover — WHERE doctor_id = ? AND status='approved' AND cancelled_at IS NULL AND starts_at <= ? AND ends_at > ? ORDER BY starts_at DESC, id DESC LIMIT 1 (the ORDER BY is defence in depth: overlap is forbidden, but the resolver must be deterministic even if a bad row exists, and it must NEVER combine two). overlappingApprovedForDoctor(int $doctorId, \DateTimeInterface $startsAt, \DateTimeInterface $endsAt, ?int $ignoreId = null): Collection — half-open predicate starts_at < :endsAt AND ends_at > :startsAt. create(array $attributes): DoctorBranchCover; update(...); lockById(int $id): ?DoctorBranchCover.

### NEW app/Modules/DoctorBranchLock/Repositories/DoctorBranchLockRepository.php
PURPOSE: Concrete implementation.

Plain Eloquent implementation of the interface. lockForDoctor uses DoctorBranchLock::query()->where('doctor_id',$id)->lockForUpdate()->first().

### NEW app/Modules/DoctorBranchLock/Repositories/DoctorBranchCoverRepository.php
PURPOSE: Concrete implementation.

Plain Eloquent implementation. All queries bounded by doctor_id; no unbounded trx_* scan (ENT-2 DBPERF-R005). The indexes ['doctor_id','status','starts_at'] and ['status','ends_at'] declared in the migration are exactly the two predicates used here (DBPERF-R008 index-matches-query).

### NEW app/Modules/DoctorBranchLock/Support/DoctorEffectiveBranch.php
PURPOSE: Immutable value object carrying the answer AND why — so the degradation reason exists without the resolver writing anything.

final readonly class with private constructor and named static factories, mirroring the shape of App\Modules\DoctorDevice\Support\DoctorSessionProof (final, private constructor). Fields: ?int $branchId; string $source; ?int $homeBranchId; ?int $coverId; ?string $degradedReason. SOURCE constants: SOURCE_NOT_APPLICABLE ('not_applicable' — null user, non-Doctor, exempt governance role, feature off), SOURCE_UNLINKED ('unlinked'), SOURCE_UNSET ('unset'), SOURCE_HOME ('home'), SOURCE_COVER ('cover'), SOURCE_DEGRADED ('degraded'). Factories: notApplicable(), unlinked(), unset(), home(int $branchId), cover(int $branchId, int $coverId, ?int $homeBranchId), degraded(string $reason, ?int $homeBranchId, ?int $coverId). Predicates: isLocked(): bool — TRUE only for SOURCE_HOME and SOURCE_COVER; isDegraded(): bool. NOTE: SOURCE_DEGRADED deliberately returns branchId === null and isLocked() === false, i.e. it is UNSET-equivalent for every consumer, and only the reason distinguishes it — that is the whole of ruling P#9.

### NEW app/Modules/DoctorBranchLock/Services/DoctorEffectiveBranchResolver.php
PURPOSE: THE resolver. The single canonical answer to EFFECTIVE_CLINICAL_BRANCH. Pure: takes a User, takes no Request, no device id, no branch argument, writes nothing.

final class DoctorEffectiveBranchResolver with constructor injection of DoctorBranchLockRepositoryInterface $locks, DoctorBranchCoverRepositoryInterface $covers, App\Modules\Doctor\Services\DoctorIdentityResolver $identity, App\Modules\RmeOnlineContext\Services\UserOnlineContextService $onlineContext, App\Modules\Branch\Services\BranchService $branches, App\Services\Foundation\FeatureFlagService $flags.

PUBLIC API (this is the contract the sibling lease/middleware slice consumes):
  public function enabled(): bool
  public function branchIdFor(?User $user): ?int          // the null-returning primary
  public function resolve(?User $user): DoctorEffectiveBranch
  public function isLocked(?User $user): bool             // === resolve($user)->isLocked()
  public function hasActiveCoverForDoctor(int $doctorId): bool   // for the permanent-transfer block (Q)

enabled(): must require BOTH flags, because nothing in the codebase enforces a flag's `dependencies` array — verified: it is only copied into the hydrated payload at app/Services/Foundation/FeatureFlagService.php:202 and read nowhere else (grep for "['dependencies']" over app/ returns only FeatureFlagService.php:25 and :202 plus an unrelated package.json read at ArchitectureUiGovernanceCheckCommand.php:1242). So:
  return $this->flags->enabled('doctor.branch_lock') && $this->flags->enabled('doctor.single_session_lease');
Flag keys contain dots so they MUST go through FeatureFlagService, never config('feature_flags.…') — the precedent and its written warning is app/Modules/LegacyRme/Support/LegacyRmeFeatureGuard.php:51.

resolve() — the exact ordered algorithm:
 1. if ($user === null) return DoctorEffectiveBranch::notApplicable();
 2. if (! $this->enabled()) return ::notApplicable();
 3. if (! $this->onlineContext->requiresDoctorContext($user)) return ::notApplicable();
    Reusing requiresDoctorContext (app/Modules/RmeOnlineContext/Services/UserOnlineContextService.php:28-31) rather than a raw hasRole('Doctor') is deliberate: it is hasRole('Doctor') && ! isExemptFromContext($user), and isExemptFromContext is hasRole(['Owner','Super Admin','Supervisor RME']) (:58-61). A governance account that also carries the Doctor role must never be branch-locked — locking one would break the very approvers this feature depends on.
 4. $doctor = $this->identity->resolveForUser($user);  // mst_doctors.user_id ONLY (app/Modules/Doctor/Services/DoctorIdentityResolver.php:35-40)
    if ($doctor === null) return ::unlinked();   // ruling P#6: an unlinked Doctor account binds nobody
    Deliberately DoctorIdentityResolver, not DoctorUserResolver: the latter additionally returns null for ! $doctor->is_active (app/Modules/RmeOnlineContext/Services/DoctorUserResolver.php:35-37), which would silently UNLOCK a deactivated doctor. Identity and 'may practise now' are separate questions and that class's own docblock says so (DoctorIdentityResolver.php:23-26).
 5. $now = CarbonImmutable::now();   // see TIMEZONE below
    $cover = $this->covers->activeForDoctorAt((int) $doctor->id, $now);
    $lock  = $this->locks->findForDoctor((int) $doctor->id);
    $homeBranchId = $lock?->home_branch_id;
 6. $rmeIds = $this->branches->rmeEnabledIds();   // active AND is_rme_enabled (app/Modules/Branch/Repositories/BranchRepository.php:40-46)
 7. if ($cover !== null):
        if (in_array((int) $cover->target_branch_id, $rmeIds, true)) return ::cover((int)$cover->target_branch_id, (int)$cover->id, $homeBranchId);
        return ::degraded('cover_branch_not_rme_enabled', $homeBranchId, (int) $cover->id);
        // NOTE: a dead COVER degrades to UNSET, it does NOT silently fall through to home. Falling through would move a covering doctor back to their home branch mid-cover with no audit and no session invalidation — a silent branch switch, which Q forbids outright.
 8. if ($homeBranchId === null) return ::unset();
 9. if (in_array((int) $homeBranchId, $rmeIds, true)) return ::home((int) $homeBranchId);
10. return ::degraded('home_branch_not_rme_enabled', $homeBranchId, null);

branchIdFor(): return $this->resolve($user)->branchId;   // null for null user, non-Doctor, unlinked, no row, UNSET, and degraded

TIMEZONE — the decision and its justification. starts_at/ends_at are INSTANTS in the application's technical frame, not wall-clock strings. config/app.php:68 hard-codes 'timezone' => 'UTC' and config/clinical.php states in prose that technical instants keep the UTC architecture on purpose while clinical.timezone (App\Support\Clinical\ClinicalTimezone::DEFAULT = 'Asia/Makassar', app/Support/Clinical/ClinicalTimezone.php:37) defines only the calendar day. Comparing an instant to an instant is timezone-agnostic, so the resolver uses CarbonImmutable::now() — which Carbon::setTestNow() controls, exactly as ClinicalClock's own docblock notes at app/Support/Clinical/ClinicalClock.php:56-61.
  DO NOT call ClinicalClock::now() here. ClinicalClock::timezone() throws InvalidClinicalTimezoneException on a misconfigured or blank clinical.timezone (app/Support/Clinical/ClinicalClock.php:71-81) — fail-closed is right for a clinical eligibility date, but this resolver runs on EVERY protected doctor request, and a config typo would become a total doctor outage rather than a refused document.
  ClinicalClock IS the authority in the two places a WALL CLOCK is actually meant, both owned by the approval slice: (a) parsing the approver's typed 'YYYY-MM-DD HH:mm' as Asia/Makassar via CarbonImmutable::parse($input, $clock->timezone())->utc() before storing, and (b) rendering starts_at/ends_at back to the approver with ->setTimezone($clock->timezone()). Never store or compare a naive wall-clock string.

PURITY — what 'pure' costs and forbids. resolve() issues at most 3 bounded reads (doctor by user_id, active cover by doctor_id, lock by doctor_id) and performs ZERO writes. In particular it MUST NOT call App\Modules\LabOrder\Services\AuditLogService::log(), which INSERTs a row on every call (app/Modules/LabOrder/Services/AuditLogService.php:38-48); auditing a degradation from the resolver would write a sys_audit_logs row on every page view of every degraded doctor. The degradation is surfaced as ->degradedReason on the value object; the WRITE hook audits it once per attempt, and a readiness/console surface reports the standing condition. Memoise per request only if a profile demands it, and if so key the cache on (int) $user->id and never on a static that survives the request.

### EDIT config/feature_flags.php
PURPOSE: Register the NEW kill switch. OFF by default; the lock is inert until both it and the sibling session flag are on.

ANCHOR: insert immediately after the closing bracket of the 'doctor.pwa_webauthn_device_login' definition, i.e. after the line `'rollback_action' => 'Set the environment override to false and clear the config cache. Requesting assertion options…` block that ends the entry beginning at config/feature_flags.php:410, and BEFORE `'rme.legacy_odontogram_archive' => [` (currently config/feature_flags.php:426).

NEW entry, copying the exact key set already used at :397-409 ('name','description','default','env_key','owner','risk','dependencies','rollback_action' — the whitelist is enforced at app/Services/Foundation/FeatureFlagService.php:25):
  'doctor.branch_lock' => [
    'name' => 'Doctor Home Branch Lock and Temporary Cover',
    'description' => must state: EVERY doctor starts UNSET and no backfill exists; while a doctor is UNSET behaviour is byte-identical to today; the lock narrows operational LISTS and blocks visit-creation WRITES at another branch, and never narrows per-record authorization or the legacy archive; a temporary cover is derived from current timestamps with no scheduler; and that this flag is inert unless doctor.single_session_lease is ALSO on, because branch authority must never change inside an already-authenticated session.
    'default' => false,
    'env_key' => 'FEATURE_DOCTOR_BRANCH_LOCK',
    'owner' => 'rme',
    'risk' => 'high',
    'dependencies' => ['doctor.single_session_lease'],
    'rollback_action' => 'Set the environment override to false and clear the config cache. Every doctor immediately resolves as UNSET and the pre-sprint list, write and selector behaviour resumes on the next request. No lock row, cover row, device, authorization or credential is altered, so re-enabling needs no re-approval.',
  ]
The 'dependencies' entry is DECLARATIVE ONLY and is documentation, not enforcement — enforcement is the explicit AND inside DoctorEffectiveBranchResolver::enabled(). Say so in the description; do not let the array imply a guarantee it does not provide (ruling P#7).
CROSS-SLICE: 'doctor.single_session_lease' is defined by the single-session lease slice. If both slices land together, only one of them may add it — coordinate, or this file conflicts.

### EDIT app/Modules/RmeOnlineContext/Services/RmeWorkingBranchScope.php
PURPOSE: THE READ HOOK. Exactly one NEW narrowing point inside the canonical class. It must be a NEW method, not an edit to branchIdsFor(), or it reaches per-record authorization and the odontogram archive.

ANCHOR: append a new public method immediately after resolve() (which ends at app/Modules/RmeOnlineContext/Services/RmeWorkingBranchScope.php:106) and before allows() (:108-119). Add DoctorEffectiveBranchResolver to the constructor at :32-35 (it currently takes UserOnlineContextService and BranchService only).

NEW METHOD:
  /** OPERATIONAL list scope. Narrower than branchIdsFor() for a LOCKED doctor and identical to it for everyone else. */
  public function operationalBranchIdsFor(?User $user, ?int $requestedBranchId = null): array
  {
      $effective = $this->doctorBranch->resolve($user);
      if ($effective->isLocked()) {
          return [$effective->branchId];   // request branch_id is IGNORED entirely; a filter may never widen and here it may not even narrow further
      }
      return $this->resolve($user, $requestedBranchId);
  }

WHY A NEW METHOD AND NOT AN EDIT TO branchIdsFor() — verified, and this is the load-bearing decision of the slice:
  allows() (:112-119) delegates straight to branchIdsFor() (:68-78). Narrowing branchIdsFor() therefore silently narrows SIX per-record authorization sites: ClinicVisitPolicy.php:186 (reached by view/update/transition at :21, :41, :56), RmeVisitConsentPolicy.php:101, RmeVisitConsentService.php:356, RmeInvoicePolicy.php:54, RmePaymentService.php:404, PrescriptionWhatsAppDeliveryService.php:52. Ruling P#2 (CRITICAL 2) forbids exactly that, and the defect is reproducible: MedicalRecordController::show resolves the patient's canonical workspace visit at app/Modules/MedicalRecord/Controllers/MedicalRecordController.php:86 and 302-redirects to it at :91-95; PatientRmWorkspaceResolver::resolveCanonicalWorkspaceVisit scopes that lookup to rmeBranchIds() = BranchService::rmeEnabledIds(), the WHOLE estate (app/Modules/MedicalRecord/Services/PatientRmWorkspaceResolver.php:28-31, :65-74); the redirect re-enters show() and hits $this->authorize('view', $clinicVisit) at :89. So a doctor locked to SPN4, opening the RM of a patient standing in front of them at SPN4 whose earliest visit was at LDK2, would be redirected to the LDK2 canonical visit and then 403'd out of their own patient's chart. A patient-safety defect, not a policy nuance.
  A second, NEWLY FOUND consumer the brief does not list: OdontogramService::patientHistory calls branchIdsFor() at app/Modules/Odontogram/Services/OdontogramService.php:86 for a patient-WIDE odontogram archive read (its own comment at :72-84 explains it is the module's first patient-wide read). Narrowing branchIdsFor() would cut a locked doctor off from their patient's odontogram history at other branches — a direct violation of owner decision O3 ('the lock does NOT narrow legacy archive reads'). REPORT THIS: it is not in the brief's CRITICAL list and it alone rules out the branchIdsFor() edit.

DO NOT TOUCH isContextBound() (:40-49). tests/Feature/RME/FixClinicOpsWorkingBranchContextTest.php:92-95 ('does not pin a Doctor, preserving the clinical practice branch model') asserts isContextBound($doctorUser) === false, and PatientSelectorSearchService.php:245 uses isContextBound()+activeBranchId() as a fail-closed guard that would start returning empty patient searches for a locked doctor. Leaving it alone keeps that test green unmodified and keeps the doctor's patient search working.

DO NOT TOUCH branchIdsFor(), allows(), narrow() or resolve(). The new method is purely additive; every existing caller keeps today's semantics.

### EDIT app/Modules/ClinicVisit/Services/ClinicVisitService.php
PURPOSE: Consume the read hook (2 call sites) and install the WRITE hook (ruling P#1, CRITICAL 1).

Constructor at :29-40 already injects RmeWorkingBranchScope ($workingBranchScope, :38), BranchService ($branches, :33) and ClinicalClock (:39). ADD: private readonly DoctorEffectiveBranchResolver $doctorBranch.

EDIT 1 — READ HOOK, one line. app/Modules/ClinicVisit/Services/ClinicVisitService.php:76, inside scopeBranchIds() (:74-77):
  FROM: return $this->workingBranchScope->resolve(Auth::user(), $branchFilter);
  TO:   return $this->workingBranchScope->operationalBranchIdsFor(Auth::user(), $branchFilter);
This single line is every surface owner decision O2 names. Verified callers of scopeBranchIds(): :52 (paginate → Daftar Kunjungan), :130 (roomWorklist → treatment room worklist), :146 (registeredQueue → Antrian Pasien), :475 (visitsTodayCount), :484 (waitingCount), :492 (inProgressCount). Six surfaces, one edit.

EDIT 2 — the branch FILTER selector, one line. :115, inside selectableRmeBranches() (:113-119):
  FROM: $allowed = $this->workingBranchScope->branchIdsFor(Auth::user());
  TO:   $allowed = $this->workingBranchScope->operationalBranchIdsFor(Auth::user());
Without this a locked doctor is offered a branch filter whose every other option returns an empty list.

EDIT 3 — WRITE HOOK. resolveBranchId() at :395-420. Insert AFTER the existing RME-enabled assertion (the in_array($branchId, $this->branches->rmeEnabledIds(), true) check that throws 'Klinik/Cabang yang dipilih harus cabang RME aktif.', :413-417) and BEFORE `return $branchId;` (:419):
  $effective = $this->doctorBranch->resolve(Auth::user());
  if ($effective->isLocked() && $effective->branchId !== $branchId) {
      throw ValidationException::withMessages([
          'branch_id' => $this->lockedBranchMessage($effective),   // Indonesian, names the branch the doctor is already assigned to — their own working context, not a leak
      ]);
  }
ORDERING IS THE ESTABLISHED RULE, NOT A PREFERENCE: eligibility first, lock second, exactly as DailyBranchContextService::assertSelectable is invoked only after eligibility and says so at app/Modules/RmeOnlineContext/Services/DailyBranchContextService.php:122-126 — 'an approved switch can never become a route to an ineligible branch'. Copy the message style from lockedMessage() at :210-217.

WHY THIS EXACT POINT: resolveBranchId() is the sole convergence of BOTH forged-branch vectors — existing-patient mode reads $data['branch_id'] (:402) and new-patient mode reads $data['new_patient']['branch_id'] (:400). It is called at :242, INSIDE the DB::transaction opened at :237 and BEFORE resolvePatient() at :245, so a throw here also prevents the new patient record from being created, and the transaction rolls back. ValidationException (already the idiom here, :407-409 and :414-416) is a handled 422 — never a RuntimeException 500.

THE HOLE IS REAL, NOT THEORETICAL: ClinicVisitPolicy::create() returns canManage() = can('manage_clinic_visits') (ClinicVisitPolicy.php:34-37, :168-171) and the Doctor role holds 'manage_clinic_visits' at database/seeders/RoleSeeder.php:259. A locked doctor can POST rme.visits.store today with any active RME branch_id.

DEGRADATION IS AUTOMATIC HERE: a degraded lock yields isLocked() === false, so the guard is skipped and the pre-existing RME-enabled assertion at :413 remains the only constraint — precisely the legacy behaviour. Audit the degradation ONCE here (AuditLogService::log with entity_type 'mst_doctor_branch_locks', action 'DOCTOR_BRANCH_LOCK_DEGRADED', new_values ['reason' => $effective->degradedReason, 'home_branch_id' => …]) because this path is a rare write, not a per-request read.

DO NOT TOUCH find() at :230-233. It is the only branchContext->requireId() on this surface and it is DEAD — grep over app/, routes/ and tests/ for '->find(' on this service returns nothing. That is the evidence for the BranchContext decision below.

### EDIT app/Modules/RmeOnlineContext/Services/UserOnlineContextService.php
PURPOSE: THE SERVER BOUNDARY for the branch selector. The dropdown is cosmetic; this is enforcement.

ANCHOR: startDoctorSession() at app/Modules/RmeOnlineContext/Services/UserOnlineContextService.php:247-298. Insert immediately AFTER the practice-branch eligibility check that throws 'Cabang yang dipilih tidak termasuk Cabang Praktik yang Diizinkan.' (:277-281) and BEFORE $this->assertRmeBranch($branchId) (:283):
  $effective = app(DoctorEffectiveBranchResolver::class)->resolve($user);
  if ($effective->isLocked() && $effective->branchId !== $branchId) {
      throw ValidationException::withMessages([
          'branch_id' => 'Cabang kerja Anda terkunci di '.$branchName.'. Perubahan memerlukan persetujuan Super Admin atau Supervisor RME.',
      ]);
  }
Same eligibility-then-lock ordering as above. Resolve $branchName through BranchRepositoryInterface::findById with the same '?? cabang yang dipilih sebelumnya' fallback used at DailyBranchContextService.php:213-214.

WHY HERE AND NOT IN THE FORM REQUEST: :277-281 checks the PIVOT ($doctor->branches->contains('id', $branchId)), i.e. eligibility, which the lock is not. And narrowing the view's $doctorAllowedBranches does not narrow this check, because it re-reads the pivot from the model. Both changes are required and this one is the boundary.

EFFECT ON EXISTING TESTS: none. tests/Pest.php:512-537 rmeMakeDoctorOnline() — the shared helper behind most doctor tests — creates no lock row, so every doctor it produces is UNSET and isLocked() is false. tests/Feature/AccessControl/DailyBranchContextLockTest.php:198-217 ('leaves a doctor free to change branch and room') moves a doctor from branch A to B through this exact method and STAYS GREEN unmodified. CORRECTION TO REPORT: brief section E item 1 predicted that test must be rewritten; that prediction assumed unconditional enforcement and is wrong under owner decision O1's UNSET default. Likewise DailyBranchContextLockTest.php:219-227 (LOCKED_ROLE_CONTEXTS pin) stays green — this sprint adds nothing to DailyBranchContextService::LOCKED_ROLE_CONTEXTS (:69-72).

### EDIT app/Modules/RmeOnlineContext/Controllers/OnlineContextController.php
PURPOSE: Narrow the selector's source collection so the doctor is never offered a branch the server will refuse — and so the Alpine room map narrows with it, for free.

ANCHOR: app/Modules/RmeOnlineContext/Controllers/OnlineContextController.php:55, currently `$doctorAllowedBranches = $linkedDoctor?->branches ?? collect();`. Replace with: resolve $effective = app(DoctorEffectiveBranchResolver::class)->resolve($user); when $effective->isLocked(), filter that collection to the single effective branch — $doctorAllowedBranches = $doctorAllowedBranches->where('id', $effective->branchId)->values(); — otherwise leave it untouched. Add two view variables to the array at :66-80: 'doctorEffectiveBranch' => $effective and 'doctorLockedBranch' => the Branch model or null.

WHY THIS ONE VARIABLE IS THE WHOLE FRONTEND ANSWER — verified: the Blade seeds the Alpine component with collect($roomsByBranch->all())->only($doctorAllowedBranches->pluck('id')->all()) at resources/views/rme/online-context/select.blade.php:41, and renders the <select name="branch_id"> by @foreach ($doctorAllowedBranches …) at :51-53. Both read the SAME variable, so narrowing it here narrows the dropdown AND removes the other branches' rooms from the Alpine payload. The component itself, onlineContextDoctorForm(branchRooms) at :145-157, is a 12-line inline function whose only logic is syncRooms() matching branch_id → rooms (:151-155). NOTHING IN IT CHANGES. There is no separate .js module.

MANIFEST CONSEQUENCE — state it honestly (ruling P#14): frontend_change stays FALSE and that is not a dodge. No .js/.css file is touched at all, and the validator's frontend pattern is '#\.(js|jsx|ts|tsx|vue|css|scss)$#' plus vite/tailwind/package.json (app/Support/Devflow/SprintManifestValidator.php:137-140), which does not match .blade.php in any case. security_impact must be declared TRUE on its merits (this slice adds an authorization boundary), even though the validator's security pattern '#^app/Policies/#' (:142) would not fire — this codebase's policies live under app/Modules/*/Policies/, so that pattern is near-dead here. The validator only errors on a false flag that contradicts the diff (:151-157); it never objects to an honestly-true flag.

### EDIT resources/views/rme/online-context/select.blade.php
PURPOSE: Tell the locked doctor why there is one option, reusing the existing locked-branch presentation verbatim.

ANCHOR: inside the @if ($requiresDoctor) block, after the $doctorAllowedBranches->isEmpty() branch (:31-37) and before/inside the @else at :38. Add, guarded by @if ($doctorEffectiveBranch->isLocked()):
  <x-ui.alert variant="warning"> naming $doctorLockedBranch->name, and when the source is 'cover' also stating it is a temporary cover with its end time rendered in Asia/Makassar via ClinicalClock — never a raw UTC timestamp.
Copy the wording and component choice from the existing daily-lock banner at :93-100 ('Cabang kerja Anda hari ini terkunci di …'), and the lock glyph treatment (&#128274; plus an sr-only label) from :109-112. Keep the <select> as a select with one option rather than a disabled input, matching :115-125 — the same server boundary applies either way, and a disabled control would submit nothing.
Do NOT add a 'request a transfer' link unless the approval slice has actually shipped the route; @if (Route::has(...)) guard it if in doubt (the codebase already guards drilldowns this way).

### EDIT app/Providers/RepositoryServiceProvider.php
PURPOSE: Bind the two new repository interfaces.

ANCHOR: the private array $repositories at app/Providers/RepositoryServiceProvider.php:286 (bound in the loop at :514). Add two entries following the exact style of DailyBranchContextRepositoryInterface::class => DailyBranchContextRepository::class at :316, plus the two matching `use` imports beside the existing RmeOnlineContext imports at :240 and :245.
  DoctorBranchLockRepositoryInterface::class => DoctorBranchLockRepository::class,
  DoctorBranchCoverRepositoryInterface::class => DoctorBranchCoverRepository::class,
Note the arrays are `private`, not `protected` (:286 and :447) — a stock-Laravel copy/paste registers nothing. No policy is added by THIS slice: the resolver is a service, and the cover/lock approval policies belong to the approval slice. DoctorEffectiveBranchResolver itself needs no binding (concrete class, constructor-autowired, exactly like ClinicalClock).

### NEW tests/Feature/AccessControl/DoctorEffectiveBranchResolverTest.php
PURPOSE: Pin the resolver contract and every Q/P ruling it owns.

Pest, in tests/Feature/AccessControl/ beside DailyBranchContextLockTest.php so it is selected by CI (the workflow's critical filter already carries a DailyBranchContext token; confirm this new file's name matches a token in .github/workflows/foundation-evidence-gates.yml or add one — a suite matching no token runs nowhere).
Null-return matrix: null user; a non-Doctor user; a Doctor who is ALSO Supervisor RME/Owner/Super Admin (exempt → notApplicable, never locked); a Doctor with no mst_doctors.user_id link (→ unlinked, branchId null); a Doctor with no lock row; a Doctor with a row whose home_branch_id is NULL. All six → branchIdFor() === null.
Home: lock set to SPN4 → branchId SPN4, source 'home', isLocked true.
Cover, driven ONLY by Carbon::setTestNow() with NO job run and NO command invoked — this is the proof correctness does not depend on a scheduler: before starts_at → home; at starts_at exactly → cover (inclusive lower bound); one second before ends_at → cover; at ends_at exactly → home (exclusive upper bound); after ends_at → home.
Cover status matrix: pending / rejected / cancelled covers are NEVER effective even inside their window.
Degradation: home branch flipped is_active=false → branchId null, isLocked false, degradedReason 'home_branch_not_rme_enabled', and NO exception of any kind. Same for is_rme_enabled=false. Cover target deactivated mid-cover → degraded, and explicitly assert it does NOT silently fall back to home.
Flags: with doctor.branch_lock on but doctor.single_session_lease off → notApplicable (pins ruling P#7 against the decorative dependencies array).
Purity: wrap resolve() in DB::listen / assert no INSERT or UPDATE is issued, and assert sys_audit_logs row count is unchanged across 50 calls.

### NEW tests/Feature/AccessControl/DoctorBranchLockOperationalScopeTest.php
PURPOSE: Pin owner decision O2's mandatory three-case regression, ruling P#1 (write hook) and ruling P#2 (per-record authorization must NOT narrow).

O2's three mandated cases, through the real HTTP routes, not the service: (1) UNSET doctor → Daftar Kunjungan, Antrian Pasien and the room worklist show the same rows as before the sprint; (2) doctor locked to SPN4 → those three lists contain only SPN4 rows; (3) THE SAME doctor with an online context on the LDK2 branch → STILL only SPN4 rows, proving the physical tablet/context never determines list scope.
Cover: locked home SPN4 + active approved cover to LDK2 → the lists show LDK2; after Carbon::setTestNow() past ends_at with no job run → back to SPN4.
WRITE (ruling P#1, both vectors): a doctor locked to SPN4 POSTs rme.visits.store with branch_id = LDK2 → 422 and assert ClinicVisit::count() is unchanged; then patient_mode=new with new_patient.branch_id = LDK2 → 422 and assert BOTH ClinicVisit::count() AND Patient::count() are unchanged (this is what proves the throw lands before resolvePatient() at ClinicVisitService.php:245).
PER-RECORD MUST STILL PASS (ruling P#2 — the test that would have caught the branchIdsFor() edit): create a patient whose EARLIEST non-cancelled visit is at LDK2 and who has a current visit at SPN4; as a doctor locked to SPN4, GET rme.visits.medical-record.show for the SPN4 visit with followingRedirects() and assert 200 — it must survive the canonical redirect to the LDK2 anchor (MedicalRecordController.php:86-95). Assert ClinicVisitPolicy::view on the LDK2 canonical visit is allowed.
ARCHIVE MUST STILL BE CROSS-BRANCH (owner decision O3, and the newly found OdontogramService consumer): as the SPN4-locked doctor, assert OdontogramService::patientHistory still returns the patient's LDK2 odontogram rows, and that the legacy RME/odontogram workspace scope (which resolves through DoctorClinicalBranchResolver, untouched — its only consumers are LegacyRmeWorkspaceScope.php:59 and LegacyOdontogramWorkspaceScope.php:54) still returns the doctor's full practice set.
SELECTOR: as a locked doctor, GET the online-context select page and assert the rendered HTML contains exactly one <option> under name="branch_id"; then POST rme.online-context.doctor with the other branch id and assert 422 — the dropdown is not the boundary.
