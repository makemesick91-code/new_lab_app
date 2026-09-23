# SLICE: Migrations and models for DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1: four additive tables (mst_doctor_branch_locks, trx_doctor_branch_lock_requests, trx_doctor_branch_covers, trx_doctor_session_leases), four Eloquent models and one final Support vocabulary class for the DERIVED cover state.

## Decisions
- TRANSFER AND COVER ARE TWO TABLES, NOT ONE TABLE WITH A MODE. Three independent reasons, each sufficient. (1) The invariants differ in KIND: 'at most one PENDING transfer per doctor' is an equality predicate a partial unique index can hold; 'no overlapping ACTIVE covers' is a range predicate no index on either engine can hold. A merged table would carry one enforceable index and one that cannot exist, which is a schema that lies about what it guarantees. (2) The status vocabularies differ: a transfer's terminal state is written (applied_at, status=approved), while a cover's ACTIVE and EXPIRED are DERIVED and must never be written (owner decision O6/Q). Sharing a status column would put a name for ACTIVE within arm's reach of the cover rows. (3) Half the columns would be NULL per mode — starts_at/ends_at/cancelled_* are meaningless for a transfer, source_branch_id/applied_at for a cover. Section Q states the two concepts must 'never be conflated', and the schema is the one place that is enforceable rather than aspirational. House precedent agrees: the codebase already splits the durable authority (trx_daily_branch_contexts, 2026_08_29_100001) from the change request (trx_branch_change_requests, 2026_08_29_100002) rather than adding a mode column.
- INITIAL ASSIGNMENT AND TRANSFER *DO* SHARE ONE TABLE (trx_doctor_branch_lock_requests, discriminated by request_type). They differ in exactly one column — an assignment has no source_branch_id — and share their status vocabulary, approver set, single-pending invariant, apply-inside-the-approval semantics and every other column. Splitting them would duplicate an identical lifecycle. This is the mirror image of the transfer-vs-cover decision, and the asymmetry is the point: split where the INVARIANTS differ, merge where only one nullable column does.
- UNSET IS THE ABSENCE OF A ROW, NOT A ROW WITH A NULL BRANCH. Owner decision O1 forbids a backfill migration; if UNSET were a row, creating one per doctor WOULD BE the backfill. So mst_doctor_branch_locks.home_branch_id is NOT NULL and a row appears only after an approved assignment. This makes O1 structural instead of a promise, and it means the resolver must read a missing row as the legacy compatibility state, never as an error.
- THE OVERLAP INVARIANT CANNOT BE AN INDEX ON EITHER ENGINE, AND I AM NOT PRETENDING OTHERWISE. A unique index compares values; overlap is a range predicate. PostgreSQL could do it with EXCLUDE USING gist + btree_gist, but that extension requires privileges not verified for the deploy user and SQLite has no equivalent at all — so the invariant would exist only in production and never in the suite that is meant to prove it (phpunit.xml:25 DB_CONNECTION=sqlite). A degenerate UNIQUE(doctor_id) WHERE status IN ('pending','approved') is also wrong, and instructively so: because O6 forbids persisting EXPIRED, a finished cover keeps status='approved' forever and that index would permanently block the doctor's second cover.
- WHAT ENFORCES NON-OVERLAP INSTEAD: an application check inside one DB::transaction, after lockForUpdate on the doctor's SINGLE mst_doctor_branch_locks row. Locking the covers table is useless — the dangerous case is two INSERTs with no conflicting row yet, and 'a FOR UPDATE guard on a zero-row predicate does not lock a gap in Postgres' (database/migrations/2026_07_19_100003:10-12). The mutex must be a row certain to exist; the home lock is exactly one row per doctor and a cover is refused for a doctor with no home lock, which is coherent anyway. Honest engine note: lockForUpdate compiles to '' on SQLite (vendor/laravel/framework/src/Illuminate/Database/Query/Grammars/SQLiteGrammar.php:31-34), so the row lock that actually protects production is the PostgreSQL one; SQLite serialises write transactions at the database level and the suite runs one in-memory connection, so the local run proves the LOGIC, not the concurrency.
- TWO PARTIAL INDEXES ON COVERS, ONE OF THEM HONESTLY LABELLED A BACKSTOP. (doctor_id) WHERE status='pending' IS a true invariant and kills the double-submit. (doctor_id, starts_at, ends_at) WHERE status='approved' catches two approvals of the IDENTICAL period — the likeliest concurrency failure — at the database even if a refactor loses the mutex, and its comment states in the migration that it does NOT catch partial overlap. A reviewer must not be able to mistake it for the overlap rule.
- THE LEASE PARTIAL INDEX IS UNGUARDED AND LIVES IN THE TABLE'S OWN MIGRATION. Exactly: CREATE UNIQUE INDEX trx_doctor_session_leases_active_uq ON trx_doctor_session_leases (user_id) WHERE released_at IS NULL — the statement sections K and M proved on sqlite 3.46.1 and PostgreSQL 16. Style copied from database/migrations/2026_08_29_100002:91-94 (unguarded, same migration as the table), NOT from 2026_07_19_100003:26-39, which wraps its index in Schema::hasTable + a driver allow-list + IF NOT EXISTS and degrades to 'the service guard is the fallback'. Here the index IS the invariant: a driver that cannot enforce it must fail the migration rather than deploy a single-session feature that is not one.
- THE LEASE CARRIES BOTH session_token_hash AND session_id, and they are not redundant. The hash is what the middleware compares, so a database reader cannot lift a session out of this table. The plaintext session_id exists for exactly one job — the P3 liveness join to sessions.id, which distinguishes a DEAD incumbent (reclaim) from a merely idle one (deny). It leaks nothing new because the sessions table already stores that value in plaintext. session_id mirrors sessions.id's type exactly, string() with no length (database/migrations/0001_01_01_000000_create_users_table.php:31), even though ids are 40 alnum chars today (vendor/.../Session/Store.php:33) — a shorter column would be engine-asymmetric, raising 22001 on PostgreSQL while SQLite ignores VARCHAR length entirely.
- session_token_hash IS NOT UNIQUE. The cardinality invariant is one ACTIVE lease per USER, held by the partial index. A unique index on the hash would add a second distinct failure mode, on a login path, with no invariant behind it.
- THE LEASE RECORDS effective_cover_id BESIDE effective_branch_id. Without it, a cover expiring onto the same branch as home, or one cover replacing another at the same target, compares equal and the session survives — while section Q says cover activation AND cover expiry BOTH invalidate. The cover reference makes the obligation literal rather than incidental. It is nullOnDelete so a vanished cover degrades to a mismatch (evict) rather than an FK error, and effective_branch_id stays denormalised so the comparison still works when the cover row is gone.
- effective_branch_id IS NULLABLE AND NULL IS A MATCHABLE STATE. An UNSET doctor has no effective branch (O1: preserve current behaviour exactly). The comparison therefore lives in PHP with === (DoctorSessionLease::establishedUnder), never in SQL, because NULL = NULL is UNKNOWN and a SQL comparison would evict every UNSET doctor on their next request.
- ACTIVE AND EXPIRED HAVE NO MODEL CONSTANT AT ALL. DoctorBranchCover declares only PENDING/APPROVED/REJECTED/CANCELLED; the derived vocabulary lives on the final Support class DoctorBranchCoverState with a private constructor, reachable only through for($cover, $at) which demands the instant. A constant sitting next to STATUS_APPROVED eventually gets assigned to status, and the moment ACTIVE is stored some job has to keep it true — which is precisely the failure O6 forbids. This is the house shape for a closed vocabulary given no native PHP enum exists in app/ (cf. app/Modules/DoctorDevice/Support/DoctorSessionProof.php:35, :54-56).
- THE COVER PERIOD IS HALF-OPEN, [starts_at, ends_at), in both coversInstant() and overlapsPeriod(). A closed interval would make two back-to-back covers 'overlap' at a single instant and would refuse a legitimate handover. Pin the boundary instant in a test both ways.
- starts_at/ends_at ARE STORED AS ORDINARY INSTANTS, not clinical dates. Section Q supersedes section O's end-of-clinical-day. ClinicalClock (app/Support/Clinical/ClinicalClock.php:12-48) belongs on the way in — the operator types WITA and the request layer converts — and on the way out for display; that class exists specifically to say a stored value is never re-zoned.
- $fillable IS EMPTY on DoctorBranchLock and DoctorSessionLease (every column is the authority or a lifecycle stamp — the DoctorDeviceAuthorization.php:65-70 reading) and requester-contributed-only on DoctorBranchLockRequest and DoctorBranchCover (the BranchChangeRequest.php:50-58 reading, so a forged status=approved has nowhere to land). Writes go through repositories using forceFill, the pattern already at app/Modules/DoctorDevice/Repositories/DoctorDeviceAuthorizationRepository.php:60-67 and app/Modules/RmeOnlineContext/Repositories/BranchChangeRequestRepository.php:49,:57,:72.
- NO HasFactory AND NO FACTORY CLASSES for any of the four models, matching the nearest siblings: DailyBranchContext.php and BranchChangeRequest.php declare none and database/factories/ contains no factory for either. Approval-workflow rows are built through their service so the invariants stay exercised. If the test slice needs one it MUST declare newFactory() explicitly — module models otherwise resolve to Database\Factories\Modules\... and fail.
- NO FOREIGN KEY FROM THE LEASE TO sessions. sessions.id is a plain string primary key, not a foreignId (0001_01_01_000000_create_users_table.php:31), and SESSION_DRIVER=array in phpunit.xml:30 leaves that table empty in every test run — an FK would make every test lease unwritable.
- TABLE NAMED trx_doctor_branch_lock_requests, NOT ..._transfer_requests. It honestly covers assignment as well as transfer, and at 35 chars the transfer name would produce the auto FK 'trx_doctor_branch_transfer_requests_destination_branch_id_foreign' = 65 bytes, which PostgreSQL silently truncates at 63. At 31 chars the longest auto FK name is 61. Every custom index name in all four migrations is 33-49 chars; the longest custom name anywhere in database/migrations/ today is 60.
- MIGRATION ORDER IS LOAD-BEARING: 100001 locks, 100002 lock requests, 100003 covers, 100004 leases. The lease's effective_cover_id is an FK onto trx_doctor_branch_covers, so reordering the timestamps breaks migrate on a fresh database. Slot 2026_09_10_* is free — the latest existing migration is 2026_09_08_100002.
- FK POLICY: restrictOnDelete to mst_doctors and mst_branches everywhere (these rows are security history — the reading at 2026_09_03_110001:27-29), cascadeOnDelete for the lease's user_id and each request's requester_user_id (matching trx_branch_change_requests:38-42), nullOnDelete for every decided_by / approved_by / released_by / cancelled_by actor (matching :68-72 and 2026_09_03_110001:61-75), and nullOnDelete for the lease's audit-only doctor_id and effective_cover_id so a broken link degrades rather than blocks.

## Depends on
- SERVICE SLICE — the lease claim MUST use the savepoint shape or it breaks on production while passing locally: outer DB::transaction, the INSERT inside a NESTED DB::transaction so Laravel emits a SAVEPOINT, catch the QueryException OUTSIDE it, only then read the incumbent to build the denial. Template: DailyBranchContextService::assertSelectable() (app/Modules/RmeOnlineContext/Services/DailyBranchContextService.php:147-232). The portable unique-violation detector must be duplicated per service per house convention (:221-232) and must accept BOTH sqlite's SQLSTATE 23000 and PostgreSQL's 23505.
- SERVICE SLICE — cover approval and permanent-transfer approval must both take lockForUpdate on the doctor's mst_doctor_branch_locks row FIRST (after locking the request row, mirroring BranchChangeApprovalService::approve() at :149-155 which locks the request then the authority). That row lock is the ONLY thing enforcing non-overlap; nothing in this slice's schema does it.
- SERVICE SLICE — a cover request and a cover approval must both REFUSE a doctor with no mst_doctor_branch_locks row. Without it the mutex row does not exist and the overlap check silently has nothing to serialise on. Also refuse a cover whose target_branch_id equals the live home_branch_id (a no-op cover that only creates comparison noise).
- SERVICE SLICE — refuse a lock request and a lock approval for a Doctor-role account with no mst_doctors.user_id link (ruling P6). The column is nullable (2026_06_29_120002:15-20) and unique (:22), so the link may simply be absent; the lease's doctor_id is nullable for exactly this reason, and single-session enforcement must still apply to such an account.
- MIDDLEWARE SLICE — the P3 liveness check reads the `sessions` table, which is EMPTY in every test run (phpunit.xml:30 SESSION_DRIVER=array, and both CI jobs set the same). A naive join makes every incumbent look DEAD, so every 'second login is denied' test would silently pass for the wrong reason and then fail in production the other way. The check must be driver-aware: consult `sessions` only when config('session.driver') === 'database', and otherwise treat the incumbent as ALIVE, which fails closed toward denial — the direction requirement 1 wants.
- MIDDLEWARE SLICE — last_seen_at renewal has no throttled-write precedent in this codebase (brief IP5 RISK: TouchOnlineContextLastSeen does one unconditional UPDATE per request, and app/ contains no Cache::add or Cache::remember at all). Because last_seen_at drives NOTHING (no idle reclaim, by ruling P3), an unthrottled write on a row under a unique index buys nothing. Consider writing it only on release, or on an interval, and say which.
- SERVICE SLICE — the DENY path must not call Auth::guard('web')->logout(), which cycles the shared users.remember_token (SessionGuard.php:650 -> :657) and would partially break the very session it refused to evict. Use logoutCurrentDevice() (:680). This forbids reusing DoctorDeviceSessionService::invalidate() on the deny path.
- SERVICE SLICE — per section R, claim on a listener for Illuminate\Auth\Events\Login, not in login controllers: fireLoginEvent also fires on the remember-me recaller path (SessionGuard.php:197-202), which a controller-only claim misses entirely, while actingAs goes through setUser and fires only Authenticated, which is what keeps ~3564 test call sites working.
- AUDIT SLICE — lease events need their own audit action, NOT DOCTOR_SESSION_DEVICE_INVALIDATED (ruling P8). The release_reason constants on DoctorSessionLease are the vocabulary that audit should carry.
- RESOLVER SLICE — EFFECTIVE_CLINICAL_BRANCH must be computed in ONE place that returns the pair (branchId, coverId) so the middleware's comparison and the claim's write can never disagree. It must fail closed to UNSET-with-audited-warning when the locked or covered branch has lost is_active or is_rme_enabled (ruling P9), rather than letting BranchContext::requireId() throw a 500 on every write path.
- TEST SLICE — assert the partial indexes still carry their WHERE clause after all four migrations have run (section K consequence 2: raw sqlite did not flatten the predicate on ADD COLUMN, but Laravel's schema grammar may rebuild a table and the safe rule stands either way). Read sqlite_master.sql on sqlite and pg_indexes.indexdef on PostgreSQL and assert the predicate text is present.
- TEST SLICE — the four mandatory concurrency tests from section Q (cover approval vs doctor login; cover expiry vs a protected request; simultaneous overlapping approvals for the same doctor; permanent transfer while a cover is active) cannot exercise real interleaving on sqlite, where lockForUpdate compiles to ''. Say so in the sprint doc and pin the LOGIC locally; the concurrency claim rests on the PostgreSQL CI run.

## Risks
- THE OVERLAP INVARIANT IS NOT IN THE DATABASE. This is the single biggest honest weakness of this schema, and it is unavoidable: no index on either engine can express non-overlapping ranges (see decisions). If a future caller creates an approved cover outside the approval service, or without the mst_doctor_branch_locks row lock, overlapping covers become possible and the effective branch becomes ambiguous. Mitigations shipped here: the pending partial index, the identical-period backstop index, and a docblock in the migration that states the rule. Mitigation NOT shipped: nothing stops a raw insert.
- SQLITE CANNOT PROVE THE CONCURRENCY. lockForUpdate compiles to an empty string (SQLiteGrammar.php:31-34) and the suite runs one in-memory connection, so every 'two simultaneous approvals' test locally proves the logic only. The partial unique indexes DO fire on sqlite (section K observed SQLSTATE 23000, driver code 19), so the lease cardinality and the pending invariants are genuinely exercised; the cover overlap rule is not.
- THE `sessions` LIVENESS JOIN IS UNTESTABLE AS THE SUITE IS CONFIGURED. SESSION_DRIVER=array everywhere means the table is empty, so a test can neither prove 'dead incumbent is reclaimed' nor 'live incumbent is denied' against real session rows. Whatever the middleware slice does here will be pinned by a fake, not by the real driver — state that plainly rather than claiming coverage.
- ONE-TIME EVICTION WAVE AT FIRST ASSIGNMENT. A doctor whose home lock moves from UNSET to a branch changes their effective branch from NULL to that branch, so their live session is invalidated on the next request. That is correct per O1 ('after the first approved assignment the branch is server-authoritative') and per section Q, but it will look like a bug to the first clinician it happens to. It must be in the approver's confirmation copy (ruling P11 already requires showing live presence and a second confirmation when the subject is ONLINE).
- restrictOnDelete FROM FOUR TABLES ONTO mst_branches AND mst_doctors means a branch or doctor carrying a lock, a request, a cover or a lease can no longer be hard-deleted. Both tables soft-delete (2026_06_04_081546:22 and 2026_06_03_030002:23), so this is inert in normal operation — but any existing maintenance path that force-deletes a branch or doctor will start failing. I did not audit for such a path; the deploy slice should.
- THE PERIOD BACKSTOP INDEX CAN BE MISREAD. trx_doctor_branch_covers_period_uq looks like an overlap guarantee to anyone who skims the index list without reading the comment. If that misreading reaches a reviewer, the missing mutex could be shipped. The comment says so in the migration; consider also naming it in the sprint doc's schema table.
- A CANCELLED-THEN-RE-APPROVED COVER CAN COLLIDE WITH THE BACKSTOP INDEX. The index predicate is status='approved', and cancellation is expressed by cancelled_at with status left at 'approved' in my model's isApproved() reading. If the cancel path leaves status='approved' and a new cover is granted for the identical period, the backstop index refuses it. FIX FOR THE SERVICE SLICE: cancellation MUST set status='cancelled' as well as cancelled_at, so the row leaves the index predicate. DoctorBranchCoverState::for() already treats either signal as CANCELLED, so the derived state is safe either way — but the index is not.
- effective_cover_id MAKES THE LEASE DEPEND ON THE COVERS TABLE, coupling the DoctorDevice module's model to the Doctor module's model and fixing the migration order. If a later slice decides to move the lease to App\Modules\Doctor\Models, the FK is fine but the namespace import changes; do not leave two lease models.
- LEASE ROWS ACCUMULATE FOREVER. Released rows are the audit trail and are deliberately unconstrained, so one row per doctor per login persists indefinitely. At 15 doctors this is nothing; there is no pruning story here and none is proposed, but a housekeeping command may eventually want one — it must never delete a row that is still unreleased.
- I DID NOT VERIFY that no maintenance or seeder path mass-assigns into these table names — they are all NEW and the absence grep over app/, database/, config/ and routes/ for doctor_session_lease, doctor_branch_lock, branch_cover and doctor_branch_transfer returned nothing, so nothing can reference them yet. Any later collision would be introduced by this sprint, not inherited.

## Files

### NEW /home/fikri/Projects/doctor-access-single-session-branch-lock-1/database/migrations/2026_09_10_100001_create_mst_doctor_branch_locks_table.php
PURPOSE: HOME_LOCKED_BRANCH (section Q concept 1). One durable row per doctor. The ABSENCE of a row IS the UNSET state, which is what makes owner decision O1 (no backfill, ever) structural rather than a promise. Also serves as the per-doctor MUTEX row that makes cover-overlap prevention race-safe on PostgreSQL.

MIGRATION ORDER: this is FIRST of four. Slot 2026_09_10_100001 is free — the latest existing migration is database/migrations/2026_09_08_100002_create_trx_doctor_device_webauthn_challenges_table.php and `ls database/migrations/ | grep 2026_09_10` returns nothing.

PREFIX: `mst_` not `trx_`. It is a durable per-subject authority row mutated in place with lifecycle stamps, exactly like mst_doctor_device_authorizations (database/migrations/2026_09_03_110001:45). `trx_daily_branch_contexts` is `trx_` because it is per-clinical-day; a home lock has no day. The brief's own RUNTIME_FIX_REQUIRED paragraph already names it `mst_doctor_branch_locks`.

WHY UNSET IS 'NO ROW' AND NOT 'home_branch_id NULL': owner decision O1 forbids a backfill migration. If UNSET were a row with a NULL branch, creating one row per doctor WOULD BE a backfill. So `home_branch_id` is NOT NULL and a row exists only after an approved assignment. Consequence to carry into the resolver slice: `DoctorBranchLockResolver` must treat a missing row as the legacy/compatibility state, never as an error.

FULL FILE:

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — HOME_LOCKED_BRANCH.
 *
 * THE ABSENCE OF A ROW IS THE UNSET STATE.
 *
 * Owner decision O1: every doctor starts UNSET and nothing is ever inferred
 * from a doctor code, a device placement, a last login, a room or history.
 * If UNSET were expressed as a row carrying a NULL branch, creating those rows
 * would itself be the backfill O1 forbids. So `home_branch_id` is NOT NULL and
 * a row exists only once an approved workflow put a branch there. A doctor with
 * no row keeps the pre-sprint branch-selection behaviour exactly.
 *
 * ONE HOME LOCK PER DOCTOR, AND THAT ROW IS ALSO THE MUTEX.
 *
 * `UNIQUE(doctor_id)` is an ordinary unique index, not a partial one: unlike a
 * pending request there is no lifecycle predicate to exclude — one doctor has
 * one home, forever, and a transfer MOVES it rather than adding a row.
 *
 * That guaranteed-single row is load-bearing a second time. A temporary cover
 * must not overlap another cover, and overlap is a RANGE predicate that no
 * partial unique index on either engine can express. The cover services
 * therefore serialise on THIS row with `lockForUpdate`. A `FOR UPDATE` guard
 * over a zero-row predicate locks no gap in PostgreSQL — the point
 * database/migrations/2026_07_19_100003:10-12 records — so the mutex has to be
 * a row that is certain to exist. This one is, and a cover is refused outright
 * for a doctor who has no home lock, which is coherent anyway: covering
 * somewhere else is meaningless without a home to come back to.
 *
 * KEYED ON `mst_doctors.id`, NOT `users.id`.
 *
 * The clinical authority belongs to the doctor master record. `mst_doctors.user_id`
 * is nullable and UNIQUE (database/migrations/2026_06_29_120002:15-22), so a
 * Doctor-role account with no link binds nobody and must be refused by the
 * request and approve paths rather than silently locked (red-team ruling P6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_doctor_branch_locks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('doctor_id')
                ->constrained('mst_doctors')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // THE AUTHORITY. Every operational list and every branch-scoped
            // write for a LOCKED doctor resolves through this value unless an
            // approved cover is currently in force.
            $table->foreignId('home_branch_id')
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // The home this row held immediately before the last approved
            // transfer, kept for the audit trail after the move — the same
            // reason `initial_branch_id` survives a switch on
            // trx_daily_branch_contexts (2026_08_29_100001:50-55).
            $table->foreignId('previous_branch_id')
                ->nullable()
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Which of the two O1 workflows put the current value here.
            // Audit metadata; never an input to the resolver.
            $table->string('established_via', 24)->default('initial_assignment');

            $table->timestamp('established_at');

            $table->foreignId('established_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('last_transferred_at')->nullable();

            // How many APPROVED transfers have landed here. Each needed its own
            // approval; this is the count of them, not a budget — the same
            // reading as `change_count` at 2026_08_29_100001:67-69.
            $table->unsignedSmallInteger('transfer_count')->default(0);

            $table->timestamps();

            $table->unique('doctor_id', 'mst_doctor_branch_locks_doctor_uq');
            $table->index('home_branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_doctor_branch_locks');
    }
};

INDEX NAME BUDGET (PostgreSQL truncates identifiers at 63 bytes; the longest custom name anywhere in database/migrations/ today is 60, 'rpt_inv_product_summaries_branch_snapshot_outbound_90d_index'):
- mst_doctor_branch_locks_doctor_uq = 33
- auto FK mst_doctor_branch_locks_established_by_user_id_foreign = 54
- auto FK mst_doctor_branch_locks_previous_branch_id_foreign = 50
All clear.

NO PARTIAL INDEX HERE. Deliberate: an ordinary UNIQUE is the correct shape, and it is what makes two concurrent INITIAL ASSIGNMENT approvals for the same unset doctor collide at the database rather than at an interleavable application check. The loser must be handled with the savepoint shape of section M/N.

### NEW /home/fikri/Projects/doctor-access-single-session-branch-lock-1/database/migrations/2026_09_10_100002_create_trx_doctor_branch_lock_requests_table.php
PURPOSE: ONE table serving BOTH O1 workflows — INITIAL BRANCH ASSIGNMENT (UNSET -> branch) and BRANCH TRANSFER (branch A -> branch B) — discriminated by `request_type`. Carries the UNGUARDED partial unique index enforcing at most one PENDING request per doctor.

WHY ASSIGNMENT AND TRANSFER SHARE ONE TABLE (and cover does NOT — see the next file). Both are literally 'make this doctor's home lock become branch X, by approval'. They have the SAME status vocabulary, the SAME approval authority (Super Admin or Supervisor RME), the SAME single-pending invariant, the SAME apply-inside-the-approval-transaction semantics, and the SAME columns — the ONLY difference is that `source_branch_id` is NULL for an assignment because there is no source. Splitting them would duplicate an identical lifecycle twice, which is the mistake mst_doctor_device_authorizations explicitly avoids for a different axis at 2026_09_03_110001:11-14 ('conflating the two would have forced a duplicate device row per doctor').

TABLE NAME IS `..._lock_requests`, NOT `..._transfer_requests`. Two reasons: it must honestly cover assignment as well as transfer, and 'trx_doctor_branch_transfer_requests' (35 chars) would make Laravel's auto FK name 'trx_doctor_branch_transfer_requests_destination_branch_id_foreign' = 65 bytes, which PostgreSQL SILENTLY TRUNCATES at 63. At 31 chars the longest auto FK name is 61.

WHAT IS DELIBERATELY ABSENT: `clinical_date`. That is the single visible difference from the sibling trx_branch_change_requests, whose pending-uniqueness is keyed `(requester_user_id, clinical_date)` at 2026_08_29_100002:91-94. A permanent lock has no day boundary, which is also (per brief IP10) exactly why BranchChangeApprovalService cannot be reused.

FULL FILE:

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the approval record for setting
 * or moving a doctor's HOME_LOCKED_BRANCH.
 *
 * ONE TABLE, TWO WORKFLOWS.
 *
 * Owner decision O1 names two: INITIAL BRANCH ASSIGNMENT (UNSET -> branch) and
 * BRANCH TRANSFER (branch A -> branch B). They differ in exactly one column —
 * an assignment has no `source_branch_id` — and share their status vocabulary,
 * their approver set, their single-pending invariant and their apply-inside-
 * the-approval semantics. `request_type` records which one this is; nothing
 * else about them diverges, so nothing else is duplicated.
 *
 * A TEMPORARY COVER IS NOT ONE OF THEM and lives in its own table. It has
 * different columns (a period), a different invariant (non-overlap, which no
 * partial unique index can express) and a status vocabulary whose ACTIVE and
 * EXPIRED members are DERIVED and never written. Section Q is explicit that
 * the two concepts must never be conflated; a shared table with a mode column
 * would conflate them in the one place it matters, the schema.
 *
 * AN APPROVAL IS NOT A TOKEN.
 *
 * The move is applied INSIDE the approval transaction, so no reusable
 * 'approved' credential is ever left lying around — the property
 * trx_branch_change_requests states for itself at 2026_08_29_100002:14-21.
 * `applied_at` is the proof the approval was consumed.
 *
 * ONE PENDING REQUEST PER DOCTOR.
 *
 * Enforced by a partial unique index rather than an application check, because
 * an application check is raceable by exactly the double-submit it is meant to
 * stop. PostgreSQL and SQLite both support partial indexes — proved on both in
 * this sprint's probes — so the invariant is identical on the production
 * driver and in the test suite. The predicate is `status = 'pending'`: decided
 * rows are unconstrained and accumulate as the audit trail.
 *
 * NO DRIVER GUARD ON THAT INDEX, ON PURPOSE. The sibling at
 * database/migrations/2026_07_19_100003:26-39 wraps its CREATE INDEX in
 * `Schema::hasTable`, a driver allow-list and `IF NOT EXISTS`, and degrades to
 * 'the service guard is the fallback'. Here the index IS the invariant, it is
 * created in the SAME migration as the table so nothing can rebuild the table
 * out from under it, and a driver that cannot enforce it must fail the
 * migration loudly rather than deploy a lock that silently is not one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_doctor_branch_lock_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('doctor_id')
                ->constrained('mst_doctors')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // WHO ASKED. Never an authority: nothing a doctor sends approves
            // their own move — the reading `requested_by` already carries at
            // 2026_09_03_110001:59-61.
            $table->foreignId('requester_user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // 'initial_assignment' | 'transfer'.
            $table->string('request_type', 24);

            // NULL for an initial assignment: there is no source. For a
            // transfer the approval re-asserts that the live lock still sits
            // here, so a request whose lock moved underneath it is refused as
            // stale rather than silently applied against a different starting
            // point (2026_08_29_100002:49-52).
            $table->foreignId('source_branch_id')
                ->nullable()
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('destination_branch_id')
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->text('reason');
            $table->timestamp('requested_at');

            $table->string('status', 16)->default('pending');

            $table->foreignId('decided_by_user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            // Set in the same transaction that moves the lock AND releases the
            // doctor's live session lease. Its presence is the proof the
            // approval was consumed.
            $table->timestamp('applied_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('doctor_id');
            $table->index(
                ['status', 'requested_at'],
                'trx_doctor_branch_lock_req_status_requested_index'
            );
            $table->index('decided_by_user_id');
            $table->index('destination_branch_id');
        });

        // At most one PENDING request per doctor.
        DB::statement(
            'CREATE UNIQUE INDEX trx_doctor_branch_lock_req_pending_uq '
            ."ON trx_doctor_branch_lock_requests (doctor_id) WHERE status = 'pending'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_doctor_branch_lock_requests');
    }
};

EXACT DB::statement, single line, for copy-paste verification:
  CREATE UNIQUE INDEX trx_doctor_branch_lock_req_pending_uq ON trx_doctor_branch_lock_requests (doctor_id) WHERE status = 'pending'

This is byte-for-byte the shape of database/migrations/2026_08_29_100002:91-94, which is the house exemplar and is already in production. Index name = 37 chars; longest auto FK name (trx_doctor_branch_lock_requests_destination_branch_id_foreign) = 61; the named index (trx_doctor_branch_lock_req_status_requested_index) = 49. All under 63.

### NEW /home/fikri/Projects/doctor-access-single-session-branch-lock-1/database/migrations/2026_09_10_100003_create_trx_doctor_branch_covers_table.php
PURPOSE: TEMPORARY_BRANCH_COVER (section Q concept 2) with explicit starts_at/ends_at. Carries TWO partial unique indexes: one that IS the pending invariant, and one that is an honestly-labelled double-submit backstop — because the real non-overlap invariant CANNOT be expressed as an index on either engine.

THE OVERLAP QUESTION, ANSWERED HONESTLY.

Can a partial unique index express 'no overlapping ACTIVE covers per doctor' on BOTH engines? NO. Not on either engine, in fact.

- A unique index compares VALUES for equality. Overlap is a RANGE predicate ([a,b) intersects [c,d)) and no equality key exists that is equal exactly when two periods overlap.
- PostgreSQL COULD express it with an exclusion constraint: `ALTER TABLE ... ADD CONSTRAINT ... EXCLUDE USING gist (doctor_id WITH =, tstzrange(starts_at, ends_at) WITH &&) WHERE (status = 'approved')`. That requires the `btree_gist` extension (`CREATE EXTENSION btree_gist`, which needs privileges the deploy user is not verified to hold) and it has NO SQLite equivalent whatsoever. The local suite runs sqlite (phpunit.xml:25 DB_CONNECTION=sqlite, :30 SESSION_DRIVER=array), so a PostgreSQL-only invariant would be a production-only invariant that the suite can never exercise — precisely the failure class section K exists to warn about. REJECTED.
- A degenerate index `UNIQUE(doctor_id) WHERE status IN ('pending','approved')` is WRONG here, and the reason is instructive: owner decision O6/Q forbids persisting ACTIVE and EXPIRED, so a cover that has run its course still carries `status = 'approved'` forever. Such an index would permanently block the doctor's SECOND cover. REJECTED, and this is exactly why 'derive, never persist' has a schema cost that must be paid somewhere else.

WHAT ENFORCES IT INSTEAD, AND HOW IT IS MADE RACE-SAFE.

The application, inside one DB::transaction, after taking `lockForUpdate` on the doctor's SINGLE `mst_doctor_branch_locks` row — not on the covers table.

- Locking the covers table is useless for this: the dangerous case is two INSERTs where no conflicting row exists yet, and 'a FOR UPDATE guard on a zero-row predicate does not lock a gap in Postgres' — the codebase's own words at database/migrations/2026_07_19_100003:10-12. The mutex must be a row that certainly exists.
- `mst_doctor_branch_locks` has exactly one row per doctor (UNIQUE(doctor_id)), it certainly exists because a cover is REFUSED for a doctor with no home lock, and `SELECT ... FOR UPDATE` on an existing row is a genuine row lock on PostgreSQL. Two concurrent cover approvals for the same doctor therefore serialise, and the second sees the first's committed row.
- ON SQLITE THE ROW LOCK IS A NO-OP — verified in vendor: SQLiteGrammar::compileLock() returns '' (vendor/laravel/framework/src/Illuminate/Database/Query/Grammars/SQLiteGrammar.php:31-34). It does not matter: SQLite serialises write transactions at the database level, and the suite runs a single in-memory connection, so the interleaving cannot occur locally. Be honest in the sprint doc that the mutex that actually protects production is the PostgreSQL row lock, and that the SQLite run proves the LOGIC, not the concurrency.
- The service check under that lock is `overlapsPeriod()`: `status = 'approved' AND cancelled_at IS NULL AND starts_at < :new_ends AND :new_starts < ends_at`. HALF-OPEN [starts_at, ends_at), so two adjacent covers sharing a boundary instant do NOT overlap. Pin that boundary case with a test; a closed interval would reject a legitimate back-to-back handover.

THE TWO INDEXES THAT ARE REAL:
1. `(doctor_id) WHERE status = 'pending'` — at most one PENDING cover request per doctor. A true invariant, expressible, both engines, and it kills the double-submit that an application check cannot.
2. `(doctor_id, starts_at, ends_at) WHERE status = 'approved'` — a BACKSTOP, not the invariant. It catches the single most likely concurrency failure, two approvals of the SAME period, at the database even if a future refactor loses the mutex. It does NOT catch partial overlap. The comment says so; do not let a reader mistake it for the overlap rule.

FULL FILE:

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — TEMPORARY_BRANCH_COVER.
 *
 * Approved, time-boxed operational authority to work somewhere other than the
 * doctor's HOME_LOCKED_BRANCH. It NEVER changes the home lock; when it stops
 * being current the effective branch simply resolves back to home.
 *
 * ACTIVE AND EXPIRED ARE NEVER WRITTEN HERE.
 *
 * `status` holds only what a human decided: pending, approved, rejected,
 * cancelled. Whether an approved cover is SCHEDULED, ACTIVE or EXPIRED is
 * derived on every read from `starts_at`, `ends_at` and the current instant.
 * Owner decision O6/Q: 'Do not persist redundant state that a delayed job
 * would have to maintain.' A column a cron has to flip is a security boundary
 * that fails open the moment the cron is late — the same reasoning
 * BranchChangeRequest::isStaleForClinicalDay() records at
 * app/Modules/RmeOnlineContext/Models/BranchChangeRequest.php:104-114,
 * 'Security correctness must never depend on a cron.'
 *
 * THE PERIOD IS AN INSTANT PAIR, NOT A CLINICAL DATE.
 *
 * Section Q corrected section O: a cover carries explicit `starts_at` and
 * `ends_at`, not an end-of-clinical-day. They are stored as ordinary instants
 * in the application's existing timestamp architecture. ClinicalClock
 * (app/Support/Clinical/ClinicalClock.php:12-48) belongs on the way IN — the
 * operator types a WITA wall-clock time and the request layer converts — and
 * on the way OUT for display. It must NOT be used to shift a stored instant;
 * that class exists precisely to say a stored value is never re-zoned.
 *
 * The interval is HALF-OPEN, [starts_at, ends_at). Two covers that meet at a
 * boundary do not overlap, so a legitimate back-to-back handover is allowed.
 *
 * NON-OVERLAP IS NOT AN INDEX, AND CANNOT BE.
 *
 * Overlap is a range predicate; a unique index compares values. PostgreSQL
 * could express it with an EXCLUDE ... USING gist constraint, but that needs
 * the btree_gist extension and has no SQLite equivalent at all, so the
 * invariant would exist only in production and never in the suite that is
 * supposed to prove it. Instead the cover services serialise on the doctor's
 * single mst_doctor_branch_locks row with lockForUpdate and evaluate the
 * overlap there — see that migration's docblock for why the mutex must be a
 * row that is certain to exist.
 *
 * The two partial indexes below are what the database CAN hold: one PENDING
 * cover per doctor, and a duplicate-period backstop. The second is a backstop,
 * not the overlap rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_doctor_branch_covers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('doctor_id')
                ->constrained('mst_doctors')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Provenance only. A doctor may NEVER self-create or self-approve a
            // cover (owner decision O6/Q); the approver check is the boundary.
            $table->foreignId('requester_user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // The home lock as it stood when the cover was requested. The
            // approval re-asserts the live lock still equals it, so a cover
            // whose home moved underneath it (an approved transfer landed
            // first) is refused as stale instead of silently granted against a
            // branch the approver never saw — the guard shape at
            // app/Modules/RmeOnlineContext/Services/BranchChangeApprovalService.php:166-170.
            $table->foreignId('source_home_branch_id')
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Where the doctor is covering. EFFECTIVE_CLINICAL_BRANCH while the
            // cover is current.
            $table->foreignId('target_branch_id')
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Half-open [starts_at, ends_at). Instants, never re-zoned.
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');

            $table->text('reason');
            $table->timestamp('requested_at');

            // Decided state ONLY: pending | approved | rejected | cancelled.
            $table->string('status', 16)->default('pending');

            $table->foreignId('decided_by_user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            // Cancellation is a SECOND event that can follow an approval, so it
            // needs its own stamps rather than overwriting the decision — the
            // per-outcome stamp convention of mst_doctor_device_authorizations
            // (2026_09_03_110001:63-72). A cancelled cover stops being current
            // immediately; it is not an expiry and it does not rewrite history.
            $table->foreignId('cancelled_by_user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_reason', 500)->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('doctor_id');

            // The hot path. On every protected request the middleware asks
            // 'is there an approved cover for this doctor that has not ended?'
            $table->index(
                ['doctor_id', 'status', 'ends_at'],
                'trx_doctor_branch_covers_doctor_status_ends_index'
            );

            $table->index(
                ['status', 'starts_at'],
                'trx_doctor_branch_covers_status_starts_index'
            );

            $table->index('target_branch_id');
            $table->index('decided_by_user_id');
        });

        // At most one PENDING cover request per doctor. A true invariant, and
        // the only one of the two that is.
        DB::statement(
            'CREATE UNIQUE INDEX trx_doctor_branch_covers_pending_uq '
            ."ON trx_doctor_branch_covers (doctor_id) WHERE status = 'pending'"
        );

        // BACKSTOP, NOT THE OVERLAP RULE. It refuses two approved covers for
        // the same doctor over the IDENTICAL period, which is the double-submit
        // race, and it costs nothing. It does NOT and cannot refuse a partial
        // overlap; that is enforced under the mst_doctor_branch_locks row lock.
        DB::statement(
            'CREATE UNIQUE INDEX trx_doctor_branch_covers_period_uq '
            .'ON trx_doctor_branch_covers (doctor_id, starts_at, ends_at) '
            ."WHERE status = 'approved'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_doctor_branch_covers');
    }
};

EXACT DB::statements, single lines:
  CREATE UNIQUE INDEX trx_doctor_branch_covers_pending_uq ON trx_doctor_branch_covers (doctor_id) WHERE status = 'pending'
  CREATE UNIQUE INDEX trx_doctor_branch_covers_period_uq ON trx_doctor_branch_covers (doctor_id, starts_at, ends_at) WHERE status = 'approved'

Index name budget: pending_uq = 35, period_uq = 34, doctor_status_ends_index = 49, status_starts_index = 44, longest auto FK (trx_doctor_branch_covers_source_home_branch_id_foreign) = 53. All under 63.

### NEW /home/fikri/Projects/doctor-access-single-session-branch-lock-1/database/migrations/2026_09_10_100004_create_trx_doctor_session_leases_table.php
PURPOSE: The single-session lease. Cardinality 1 active lease per user, enforced by an UNGUARDED partial unique index created in the SAME migration as the table. Carries session_token_hash, session_id and the effective branch the session was established under, per section Q.

MIGRATION ORDER MATTERS: this is LAST of the four because `effective_cover_id` is a foreign key onto trx_doctor_branch_covers, created in 100003. Reordering the timestamps breaks `migrate` on a fresh database.

WHY THE SESSION ID AND THE HASH ARE BOTH PRESENT (they are not redundant):
- `session_token_hash` is what the per-request middleware compares. Storing only a hash means a database reader cannot lift anyone's session out of this table.
- `session_id` exists for ONE job: the P3 liveness check. 'An incumbent whose sessions row no longer exists is DEAD and is reclaimed immediately; an incumbent whose session row is alive is DENIED however idle it is.' That join needs the plaintext key of `sessions.id`, which is `$table->string('id')->primary()` (database/migrations/0001_01_01_000000_create_users_table.php:31). It leaks nothing new: the `sessions` table already stores that exact value in plaintext.
- Column type mirrors `sessions.id` EXACTLY — `string('session_id')`, no explicit length, i.e. 255 — even though the framework's ids are 40 alnum chars today (vendor/laravel/framework/src/Illuminate/Session/Store.php:33 SESSION_ID_LENGTH = 40, :722-725). A shorter column would be an engine-asymmetric trap: PostgreSQL raises 22001 on an over-length value while SQLite does not enforce VARCHAR length at all, so a custom handler with longer ids would break production and pass the suite.

NO FOREIGN KEY TO `sessions`. Two reasons. `sessions.id` is a plain string primary key, not a `foreignId`, so it is not the house FK shape; and SESSION_DRIVER=array in phpunit.xml:30 and in both CI jobs means the table is EMPTY in every test run — an FK would make every test lease unwritable.

`session_token_hash` IS DELIBERATELY NOT UNIQUE. The cardinality invariant is one ACTIVE lease per USER and that is the partial index below. A unique index on the hash would add a second, distinct failure mode with no invariant behind it, on a login path, for an event that cannot occur.

FULL FILE:

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — one authenticated session per
 * doctor, and the branch that session was established under.
 *
 * WHY A TABLE AND NOT THE EXISTING SESSION BINDING.
 *
 * A doctor's session is Laravel's `web` session plus six payload keys written
 * by DoctorDeviceSessionService::bind()
 * (app/Modules/DoctorDevice/Services/DoctorDeviceSessionService.php:163-179).
 * Every one of them lives INSIDE the session it describes, so nothing outside
 * the owning request can ask 'does this doctor already hold a session?' and
 * nothing can be marked released from outside. A claim needs a row.
 *
 * trx_user_online_contexts was rejected for the same reason its sibling
 * rejected it at database/migrations/2026_08_29_100001:11-17: it is a session
 * representation with a nullable branch, rewritten wholesale by updateOrCreate
 * on every session start, and it carries no session identity at all.
 *
 * THE INVARIANT IS AT MOST ONE UNRELEASED ROW PER USER.
 *
 * A partial unique index on `(user_id) WHERE released_at IS NULL`, created in
 * THIS migration so no later table rebuild can quietly drop the predicate.
 * Released rows are unconstrained and accumulate as the audit trail, which is
 * why the key is a nullable release stamp and not a boolean.
 *
 * Two simultaneous logins cannot both win: the loser's INSERT is refused by
 * the database, not by an application check that can be interleaved. Claim it
 * with the savepoint shape — nested DB::transaction so Laravel emits a
 * SAVEPOINT, catch the QueryException OUTSIDE it, only then read the incumbent
 * to build the denial. On PostgreSQL a failed statement aborts the whole
 * transaction, so reading the incumbent inside the failed statement's scope
 * raises 25P02 while passing on SQLite. The template is
 * DailyBranchContextService::assertSelectable()
 * (app/Modules/RmeOnlineContext/Services/DailyBranchContextService.php:147-232),
 * whose own comment records the same discovery.
 *
 * REFUSED, NOT EVICTED. The second login is denied; the first is untouched.
 * There is no idle reclaim: an incumbent is reclaimed only when its `sessions`
 * row is gone, i.e. the session is genuinely dead. A timer would be eviction
 * by the back door and would invert the requirement this table exists for.
 *
 * WHY THE EFFECTIVE BRANCH IS RECORDED HERE.
 *
 * Section Q: the session is bound to the branch it was established under, and
 * every protected request recomputes EFFECTIVE_CLINICAL_BRANCH purely from
 * current timestamps and compares. A cover that started, a cover that ended, a
 * transfer that was approved — all three become the same comparison, all three
 * invalidate the session, and none of them needs a scheduler to be correct.
 * Structurally, a branch cannot switch silently mid-session.
 *
 * `effective_branch_id` IS NULLABLE, and NULL is the compatibility state, not
 * missing data. A doctor with no home lock and no cover has no effective
 * branch (owner decision O1: while UNSET, preserve current behaviour exactly).
 * NULL recomputes to NULL, matches, and the doctor is never evicted.
 * COMPARE IN PHP WITH ===, NEVER IN SQL: `NULL = NULL` is UNKNOWN, so a SQL
 * comparison would evict every UNSET doctor on their next request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_doctor_session_leases', function (Blueprint $table) {
            $table->id();

            // THE HOLDER is the authenticated principal, users.id — the value
            // the guard and the sessions table both key on.
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Audit only, and nullable on purpose. mst_doctors.user_id is
            // nullable (2026_06_29_120002:15-20), so a Doctor-role account may
            // hold no doctor record; single-session enforcement must still
            // apply to it. Recorded at claim time because the link can be
            // broken later and the lease must still say who this was.
            $table->foreignId('doctor_id')
                ->nullable()
                ->constrained('mst_doctors')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            // Mirrors sessions.id exactly (no explicit length), so no value
            // that table accepts can ever be rejected here. Used ONLY for the
            // liveness join that distinguishes a dead session from an idle one.
            $table->string('session_id');

            // Hash only. The middleware compares this; the plaintext lives in
            // the cookie and in sessions.id, never twice.
            // Deliberately NOT unique — see the class note.
            $table->string('session_token_hash', 128);

            // EFFECTIVE_CLINICAL_BRANCH at claim time. NULL means UNSET, which
            // is a legitimate, matchable state.
            $table->foreignId('effective_branch_id')
                ->nullable()
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // WHICH authority produced that branch. Not decoration: without it
            // a cover that expires onto the same branch as home, or one cover
            // replacing another at the same target, would compare equal and the
            // session would survive — while section Q says expiry MUST
            // invalidate. nullOnDelete rather than restrict so a vanished cover
            // degrades to a mismatch (evict) instead of an FK error.
            $table->foreignId('effective_cover_id')
                ->nullable()
                ->constrained('trx_doctor_branch_covers')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamp('claimed_at');

            // Display and audit ONLY. It must never drive a reclaim: idle is
            // not dead, and a doctor who finished on the ward tablet and walked
            // to the office PC must not be locked out of their own account.
            $table->timestamp('last_seen_at')->nullable();

            // THE PARTIAL INDEX PREDICATE. Nullable stamp, not a boolean, so
            // history survives.
            $table->timestamp('released_at')->nullable();
            $table->string('released_reason', 32)->nullable();

            // Who released it, when a human did — the approver clearing a stuck
            // lease. NULL for a self-release at logout.
            $table->foreignId('released_by_user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamps();

            $table->index('user_id');
            $table->index(
                ['user_id', 'released_at'],
                'trx_doctor_session_leases_user_released_index'
            );
            $table->index('session_token_hash');
            $table->index('session_id');
            $table->index('effective_branch_id');
        });

        // THE CARDINALITY INVARIANT: at most one unreleased lease per user.
        DB::statement(
            'CREATE UNIQUE INDEX trx_doctor_session_leases_active_uq '
            .'ON trx_doctor_session_leases (user_id) WHERE released_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_doctor_session_leases');
    }
};

EXACT DB::statement, single line — this is the statement sections K and M proved on sqlite 3.46.1 and PostgreSQL 16 respectively:
  CREATE UNIQUE INDEX trx_doctor_session_leases_active_uq ON trx_doctor_session_leases (user_id) WHERE released_at IS NULL

No `IF NOT EXISTS`, no `Schema::hasTable` guard, no driver allow-list — unlike database/migrations/2026_07_19_100003:26-39. The table is created two statements earlier in the same up(), so it exists; and a driver that cannot enforce this must fail the migration, because a lease table without this index is a single-session feature that is not one.

Index name budget: active_uq = 35, user_released_index = 45, longest auto FK (trx_doctor_session_leases_released_by_user_id_foreign) = 53. All under 63.

DELIBERATE OMISSIONS, so a reviewer does not read them as oversights:
- No `uuid`. The lease is never exposed to a client; the sibling trx_branch_change_requests has none either.
- No `ip_address` / `user_agent`. The `sessions` table already holds both and `session_id` joins to it; duplicating PII into a second table with a different retention would be a privacy regression for no new capability.
- No `proof_type`. It would duplicate a session payload key (DoctorAppLoginGate.php:51-69) that the request already carries.

### NEW /home/fikri/Projects/doctor-access-single-session-branch-lock-1/app/Modules/Doctor/Models/DoctorBranchLock.php
PURPOSE: Eloquent model for mst_doctor_branch_locks. Nothing fillable — every column is the authority or a lifecycle stamp.

NAMESPACE: App\Modules\Doctor\Models — the module that already owns Doctor.php and that brief IP10 names for DoctorBranchTransferApprovalService. Directory app/Modules/Doctor/Models/ currently holds only Doctor.php.

NO HasFactory, NO factory class. Matches the nearest siblings: app/Modules/RmeOnlineContext/Models/DailyBranchContext.php and BranchChangeRequest.php both omit it, and database/factories/ contains no DailyBranchContextFactory or BranchChangeRequestFactory. Approval-workflow rows are built through their service in tests, which is what keeps the invariants exercised. IF the test slice later needs one, it MUST declare `protected static function newFactory()` explicitly — module models otherwise resolve to Database\Factories\Modules\... and fail.

FULL FILE:

<?php

declare(strict_types=1);

namespace App\Modules\Doctor\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — HOME_LOCKED_BRANCH.
 *
 * One doctor, one home branch, set only by an approved workflow. The absence
 * of a row is UNSET, and UNSET is the compatibility state: a doctor without a
 * row keeps the pre-sprint selection behaviour exactly (owner decision O1).
 *
 * A cover NEVER changes this row. `home_branch_id` moves only through an
 * approved initial assignment or an approved transfer, both applied inside the
 * approval transaction.
 */
class DoctorBranchLock extends Model
{
    /** The first branch this doctor was ever locked to. */
    public const VIA_INITIAL_ASSIGNMENT = 'initial_assignment';

    /** A later approved move from one home branch to another. */
    public const VIA_TRANSFER = 'transfer';

    public const ESTABLISHED_VIA = [
        self::VIA_INITIAL_ASSIGNMENT,
        self::VIA_TRANSFER,
    ];

    protected $table = 'mst_doctor_branch_locks';

    /**
     * Nothing is fillable. `home_branch_id` IS the authority, so a request
     * payload must never be able to drive it by mass assignment — the same
     * reading as DoctorDeviceAuthorization (:65-70). The approval repository
     * writes with forceFill, the pattern already used at
     * app/Modules/DoctorDevice/Repositories/DoctorDeviceAuthorizationRepository.php:60-67.
     *
     * @var array<int, string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'doctor_id' => 'integer',
            'home_branch_id' => 'integer',
            'previous_branch_id' => 'integer',
            'established_by_user_id' => 'integer',
            'established_at' => 'datetime',
            'last_transferred_at' => 'datetime',
            'transfer_count' => 'integer',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    public function homeBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'home_branch_id');
    }

    public function previousBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'previous_branch_id');
    }

    public function establishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'established_by_user_id');
    }

    /** Every approval that ever moved this lock, newest last. */
    public function requests(): HasMany
    {
        return $this->hasMany(DoctorBranchLockRequest::class, 'doctor_id', 'doctor_id');
    }

    /** Every cover ever granted against this doctor. */
    public function covers(): HasMany
    {
        return $this->hasMany(DoctorBranchCover::class, 'doctor_id', 'doctor_id');
    }

    /**
     * True when the given branch is the home this doctor is committed to.
     *
     * Mirrors DailyBranchContext::isLockedTo() (:84-87). Note this asks about
     * HOME, not about the effective branch — an active cover does not change
     * the answer, and a caller that wants the effective branch must ask the
     * resolver, not this model.
     */
    public function isLockedTo(int $branchId): bool
    {
        return (int) $this->home_branch_id === $branchId;
    }
}

### NEW /home/fikri/Projects/doctor-access-single-session-branch-lock-1/app/Modules/Doctor/Models/DoctorBranchLockRequest.php
PURPOSE: Eloquent model for trx_doctor_branch_lock_requests. Requester-contributed columns only in $fillable; every decision column is excluded so a forged status has nowhere to land.

MODELLED LINE-FOR-LINE on app/Modules/RmeOnlineContext/Models/BranchChangeRequest.php, minus STATUS_EXPIRED (no clinical day) and minus isStaleForClinicalDay() (nothing to be stale against), plus the request_type discriminator.

FULL FILE:

<?php

declare(strict_types=1);

namespace App\Modules\Doctor\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — a request to SET or MOVE a
 * doctor's HOME_LOCKED_BRANCH, and the record of what an approver decided.
 *
 * Both of owner decision O1's workflows live here, told apart by
 * `request_type`: an initial assignment has no `source_branch_id`, a transfer
 * has one and the approval re-asserts it under a row lock.
 *
 * The status vocabulary is closed and every transition runs through the
 * approval service. Nothing here may be reached by mass assignment: `status`,
 * `decided_by_user_id`, `decided_at` and `applied_at` are the whole security
 * value of the row, so `$fillable` excludes them and the repository writes
 * them explicitly with forceFill.
 *
 * There is no EXPIRED status. Its sibling trx_branch_change_requests has one
 * because a request belongs to a clinical day; a permanent lock has no day, so
 * a pending request stays pending until it is decided or cancelled.
 */
class DoctorBranchLockRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /** UNSET -> approved home branch. `source_branch_id` is NULL. */
    public const TYPE_INITIAL_ASSIGNMENT = 'initial_assignment';

    /** Home branch A -> approved home branch B. `source_branch_id` is bound. */
    public const TYPE_TRANSFER = 'transfer';

    public const TYPES = [
        self::TYPE_INITIAL_ASSIGNMENT,
        self::TYPE_TRANSFER,
    ];

    protected $table = 'trx_doctor_branch_lock_requests';

    /**
     * Only the fields a requester legitimately contributes, and even those are
     * re-derived server-side before the row is written. The decision fields are
     * absent on purpose: a forged `status=approved` or
     * `decided_by_user_id=self` in a payload has nowhere to land.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'doctor_id',
        'requester_user_id',
        'request_type',
        'source_branch_id',
        'destination_branch_id',
        'reason',
        'requested_at',
    ];

    protected function casts(): array
    {
        return [
            'doctor_id' => 'integer',
            'requester_user_id' => 'integer',
            'source_branch_id' => 'integer',
            'destination_branch_id' => 'integer',
            'decided_by_user_id' => 'integer',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function sourceBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'source_branch_id');
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isDecided(): bool
    {
        return ! $this->isPending();
    }

    public function isInitialAssignment(): bool
    {
        return $this->request_type === self::TYPE_INITIAL_ASSIGNMENT;
    }

    /**
     * Was this approval already consumed?
     *
     * Read from `applied_at` rather than from `status`, because the stamp is
     * written in the same transaction that moved the lock. A row that says
     * APPROVED but carries no `applied_at` never moved anything.
     */
    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }

    /** Human-readable status, for the requester and approver surfaces. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Menunggu Persetujuan',
            self::STATUS_APPROVED => 'Disetujui',
            self::STATUS_REJECTED => 'Ditolak',
            self::STATUS_CANCELLED => 'Dibatalkan',
            default => (string) $this->status,
        };
    }
}

### NEW /home/fikri/Projects/doctor-access-single-session-branch-lock-1/app/Modules/Doctor/Models/DoctorBranchCover.php
PURPOSE: Eloquent model for trx_doctor_branch_covers. Persists only decided statuses; ACTIVE/EXPIRED/SCHEDULED are derived by DoctorBranchCoverState and are structurally absent from this class's constants.

THE KEY STRUCTURAL POINT: this model has NO STATUS_ACTIVE and NO STATUS_EXPIRED constant. Owner decision O6/Q says those are derived and must never be persisted; the cheapest way to make that stick is to leave no constant a future writer could assign. The derived vocabulary lives on DoctorBranchCoverState (next file) and is reachable only through a computation that takes an instant.

HALF-OPEN INTERVAL, stated once and enforced in both predicates below: a cover covers [starts_at, ends_at). Two adjacent covers sharing a boundary do not overlap. Pin the boundary instant in a test both ways.

FULL FILE:

<?php

declare(strict_types=1);

namespace App\Modules\Doctor\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Support\DoctorBranchCoverState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — TEMPORARY_BRANCH_COVER.
 *
 * Approved, time-boxed authority to work somewhere other than home. It never
 * touches the home lock; when it stops being current the effective branch
 * resolves back to home on the next request.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT DECLARE.
 *
 * There is no STATUS_ACTIVE and no STATUS_EXPIRED. Section Q: those are
 * DERIVED from approval plus timestamps and must never be persisted, because a
 * persisted one is a value some job has to maintain and a late job means an
 * expired cover that is still effective. Leaving the constants out means there
 * is no name for a writer to assign. The derived vocabulary lives on
 * {@see DoctorBranchCoverState} and is only reachable through a call that
 * takes the instant to judge against.
 *
 * THE PERIOD IS HALF-OPEN, [starts_at, ends_at).
 *
 * A cover that ends at 17:00 does not cover 17:00, so a second cover may start
 * there. A closed interval would refuse a legitimate back-to-back handover and
 * would make two covers 'overlap' at a single instant.
 */
class DoctorBranchCover extends Model
{
    /** Requested, awaiting a Super Admin or Supervisor RME decision. */
    public const STATUS_PENDING = 'pending';

    /**
     * Granted. Whether it is SCHEDULED, ACTIVE or EXPIRED right now is not
     * recorded — ask {@see self::state()}.
     */
    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** Withdrawn after approval. Stops being current immediately. */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Every status that is ever WRITTEN. Deliberately shorter than the state
     * vocabulary a reader sees.
     */
    public const PERSISTED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'trx_doctor_branch_covers';

    /**
     * Requester-contributed only, and re-derived server-side before the write.
     * `status`, the decision stamps and the cancellation stamps are absent:
     * a doctor must never self-approve or self-extend a cover.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'doctor_id',
        'requester_user_id',
        'source_home_branch_id',
        'target_branch_id',
        'starts_at',
        'ends_at',
        'reason',
        'requested_at',
    ];

    protected function casts(): array
    {
        return [
            'doctor_id' => 'integer',
            'requester_user_id' => 'integer',
            'source_home_branch_id' => 'integer',
            'target_branch_id' => 'integer',
            'decided_by_user_id' => 'integer',
            'cancelled_by_user_id' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function sourceHomeBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'source_home_branch_id');
    }

    public function targetBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'target_branch_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Granted and not withdrawn. Says nothing about the clock. */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED && $this->cancelled_at === null;
    }

    /**
     * Is this cover the doctor's operational authority at the given instant?
     *
     * The whole effective-branch rule reduces to this predicate. It reads only
     * the row and the instant it is handed — no cron, no cached flag, no
     * ambient clock — so an expired cover can never remain effective because a
     * queue worker was late.
     */
    public function coversInstant(CarbonInterface $at): bool
    {
        if (! $this->isApproved() || $this->starts_at === null || $this->ends_at === null) {
            return false;
        }

        return $this->starts_at->lessThanOrEqualTo($at) && $this->ends_at->greaterThan($at);
    }

    /**
     * Would this cover collide with the proposed period?
     *
     * Half-open on both sides, so `ends_at == $start` is NOT a collision. This
     * is the predicate the approval service evaluates under the doctor's
     * mst_doctor_branch_locks row lock, because no index on either engine can
     * express it.
     */
    public function overlapsPeriod(CarbonInterface $start, CarbonInterface $end): bool
    {
        if (! $this->isApproved() || $this->starts_at === null || $this->ends_at === null) {
            return false;
        }

        return $this->starts_at->lessThan($end) && $this->ends_at->greaterThan($start);
    }

    /** SCHEDULED / ACTIVE / EXPIRED / PENDING / REJECTED / CANCELLED. */
    public function state(CarbonInterface $at): string
    {
        return DoctorBranchCoverState::for($this, $at);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Menunggu Persetujuan',
            self::STATUS_APPROVED => 'Disetujui',
            self::STATUS_REJECTED => 'Ditolak',
            self::STATUS_CANCELLED => 'Dibatalkan',
            default => (string) $this->status,
        };
    }
}

### NEW /home/fikri/Projects/doctor-access-single-session-branch-lock-1/app/Modules/Doctor/Support/DoctorBranchCoverState.php
PURPOSE: The DERIVED cover vocabulary as a final Support class with a private constructor — the house shape for a closed vocabulary, since no native PHP enum exists in app/.

WHY A SUPPORT CLASS AND NOT MODEL CONSTANTS. Persisted statuses are model constants everywhere in this codebase (BranchChangeRequest.php:25-38, DoctorDeviceAuthorization.php:28-50). This vocabulary is different in kind: it is never stored, it exists only as the answer to a question about an instant, and putting ACTIVE/EXPIRED next to the persisted statuses on the model is exactly how someone eventually writes one into the column. The `final` class with a private constructor is the shape DoctorSessionProof already uses (app/Modules/DoctorDevice/Support/DoctorSessionProof.php:35, :54-56).

NO NATIVE ENUM, confirmed against the brief's constraint and the two exemplars above.

FULL FILE:

<?php

declare(strict_types=1);

namespace App\Modules\Doctor\Support;

use App\Modules\Doctor\Models\DoctorBranchCover;
use Carbon\CarbonInterface;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — what a cover IS right now.
 *
 * Section Q draws a line the schema has to hold: PENDING, APPROVED, REJECTED
 * and CANCELLED are decisions a human made and are written down. SCHEDULED,
 * ACTIVE and EXPIRED are consequences of the clock and are never written down.
 *
 * They live here rather than beside the persisted statuses on the model for a
 * blunt reason: a constant sitting next to `STATUS_APPROVED` eventually gets
 * assigned to `status`, and the moment ACTIVE is a stored value some job has
 * to keep it true — which is the failure mode the decision exists to prevent.
 * A late job would leave an expired cover effective. Here the only way to
 * obtain one of these names is to hand over the instant to judge against.
 *
 * There is no ambient clock in this class. The caller supplies the instant, so
 * the middleware, the approval service, the resolver and the tests all reach
 * the same answer, and Carbon::setTestNow() controls it without any bespoke
 * freezing mechanism.
 */
final class DoctorBranchCoverState
{
    /** Requested, undecided. */
    public const PENDING = 'pending';

    /** Refused. Never becomes current. */
    public const REJECTED = 'rejected';

    /** Withdrawn after approval. Never becomes current again. */
    public const CANCELLED = 'cancelled';

    /** Approved, but `starts_at` is still ahead of the given instant. */
    public const SCHEDULED = 'scheduled';

    /** Approved and current: starts_at <= at < ends_at. THE effective branch. */
    public const ACTIVE = 'active';

    /** Approved and run out. The effective branch is home again. */
    public const EXPIRED = 'expired';

    /**
     * The closed vocabulary. A value outside this list is not a state to be
     * interpreted generously; it is a row this build cannot read.
     */
    public const STATES = [
        self::PENDING,
        self::REJECTED,
        self::CANCELLED,
        self::SCHEDULED,
        self::ACTIVE,
        self::EXPIRED,
    ];

    /** Never instantiated: this is a vocabulary and a single derivation. */
    private function __construct() {}

    /**
     * Derive the state of one cover at one instant.
     *
     * Cancellation outranks the clock: a cover cancelled mid-period is not
     * ACTIVE, whatever `ends_at` says. A row missing either bound is treated as
     * EXPIRED rather than ACTIVE — an unreadable period must never widen a
     * doctor's authority.
     */
    public static function for(DoctorBranchCover $cover, CarbonInterface $at): string
    {
        if ($cover->cancelled_at !== null || $cover->status === DoctorBranchCover::STATUS_CANCELLED) {
            return self::CANCELLED;
        }

        if ($cover->status === DoctorBranchCover::STATUS_PENDING) {
            return self::PENDING;
        }

        if ($cover->status !== DoctorBranchCover::STATUS_APPROVED) {
            return self::REJECTED;
        }

        if ($cover->starts_at === null || $cover->ends_at === null) {
            return self::EXPIRED;
        }

        if ($cover->starts_at->greaterThan($at)) {
            return self::SCHEDULED;
        }

        return $cover->ends_at->greaterThan($at) ? self::ACTIVE : self::EXPIRED;
    }

    /** Human-readable, for the approver and doctor surfaces. */
    public static function label(string $state): string
    {
        return match ($state) {
            self::PENDING => 'Menunggu Persetujuan',
            self::REJECTED => 'Ditolak',
            self::CANCELLED => 'Dibatalkan',
            self::SCHEDULED => 'Terjadwal',
            self::ACTIVE => 'Sedang Berlaku',
            self::EXPIRED => 'Sudah Berakhir',
            default => $state,
        };
    }
}

### NEW /home/fikri/Projects/doctor-access-single-session-branch-lock-1/app/Modules/DoctorDevice/Models/DoctorSessionLease.php
PURPOSE: Eloquent model for trx_doctor_session_leases. Nothing fillable. Carries the constant-time token comparison and the effective-branch match used by the per-request middleware.

NAMESPACE: App\Modules\DoctorDevice\Models — beside DoctorDeviceAuthorization.php and DoctorDeviceChallenge.php. Chosen because the lease is a session-binding artefact and brief IP2/IP3 put both the claim (DoctorDeviceSessionService::bind(), 'the sanctioned owner of doctor session writes') and the middleware (App\Modules\DoctorDevice\Middleware\EnsureDoctorSessionLease) in this module. FLAG FOR THE SERVICE SLICE: it holds a `users.id`, not a doctor id, so if that slice moves the claim to an Illuminate\Auth\Events\Login listener per section R the model may equally well sit in App\Modules\Doctor\Models. Pick one and do not leave a duplicate.

NO HasFactory — same rationale as the branch-lock family. If the test slice adds one, declare newFactory() explicitly.

FULL FILE:

<?php

declare(strict_types=1);

namespace App\Modules\DoctorDevice\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Doctor\Models\DoctorBranchCover;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the claim that says 'this
 * doctor currently holds this session', and the branch it was established
 * under.
 *
 * Before this row existed there was nothing outside the owning request that
 * could answer either question: the whole session binding is six session-
 * payload keys (DoctorDeviceSessionService.php:163-179), all of them inside
 * the session they describe.
 *
 * AT MOST ONE UNRELEASED ROW PER USER, and the database says so — a partial
 * unique index on (user_id) WHERE released_at IS NULL. Released rows stay as
 * the audit trail, which is why the key is a nullable stamp and not a boolean.
 *
 * REFUSED, NOT EVICTED. A second login is denied; the first is untouched. That
 * is the requirement, and it constrains two things this class must not tempt
 * anyone into:
 *  - there is NO idle reclaim. `last_seen_at` is display and audit only. A
 *    lease is reclaimable when its `sessions` row is gone — dead — never when
 *    it is merely quiet.
 *  - the deny path must NOT use Auth::guard('web')->logout(), which cycles the
 *    SHARED users.remember_token (SessionGuard::logout() :650 -> :657) and
 *    would therefore partially break the session it just refused to evict.
 *    logoutCurrentDevice() (:680) does not cycle it.
 *
 * THE EFFECTIVE BRANCH IS PART OF THE CLAIM. Section Q: every protected
 * request recomputes EFFECTIVE_CLINICAL_BRANCH from current timestamps and
 * compares it with what the session was established under. Cover started,
 * cover expired, transfer approved — one comparison, three behaviours, no
 * scheduler, and no way for a branch to change silently mid-session.
 */
class DoctorSessionLease extends Model
{
    /** The doctor logged out. The ordinary release. */
    public const RELEASE_LOGOUT = 'logout';

    /**
     * EFFECTIVE_CLINICAL_BRANCH no longer matches what this session was
     * established under — a cover started, a cover ended, or a transfer
     * landed. Section Q makes all three the same event.
     */
    public const RELEASE_EFFECTIVE_BRANCH_CHANGED = 'effective_branch_changed';

    /** Released inside an approved permanent transfer's transaction. */
    public const RELEASE_BRANCH_TRANSFER_APPROVED = 'branch_transfer_approved';

    /**
     * The incumbent's `sessions` row was gone, so the lease was reclaimed by
     * the next login. NOT an idle timeout — dead, not quiet.
     */
    public const RELEASE_DEAD_SESSION_RECLAIMED = 'dead_session_reclaimed';

    /** An approver cleared a stuck lease. `released_by_user_id` names them. */
    public const RELEASE_ADMIN = 'admin_release';

    /** The device session was torn down (revocation, proof no longer valid). */
    public const RELEASE_DEVICE_INVALIDATED = 'device_invalidated';

    /** The account was deleted. */
    public const RELEASE_ACCOUNT_DELETED = 'account_deleted';

    public const RELEASE_REASONS = [
        self::RELEASE_LOGOUT,
        self::RELEASE_EFFECTIVE_BRANCH_CHANGED,
        self::RELEASE_BRANCH_TRANSFER_APPROVED,
        self::RELEASE_DEAD_SESSION_RECLAIMED,
        self::RELEASE_ADMIN,
        self::RELEASE_DEVICE_INVALIDATED,
        self::RELEASE_ACCOUNT_DELETED,
    ];

    protected $table = 'trx_doctor_session_leases';

    /**
     * Nothing is fillable. Every column is either the claim itself or a
     * lifecycle decision, and a request payload must never drive one — the
     * reading DoctorDeviceAuthorization states at :65-70. The service writes
     * with forceFill.
     *
     * @var array<int, string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'doctor_id' => 'integer',
            'effective_branch_id' => 'integer',
            'effective_cover_id' => 'integer',
            'released_by_user_id' => 'integer',
            'claimed_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    public function effectiveBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'effective_branch_id');
    }

    public function effectiveCover(): BelongsTo
    {
        return $this->belongsTo(DoctorBranchCover::class, 'effective_cover_id');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }

    /** The predicate the partial unique index is built on. Keep them identical. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    public function isActive(): bool
    {
        return $this->released_at === null;
    }

    /**
     * Is the request in front of us the holder of this lease?
     *
     * hash_equals rather than ===, because this is a comparison against a
     * value derived from a bearer credential.
     */
    public function matchesTokenHash(string $candidateHash): bool
    {
        return hash_equals((string) $this->session_token_hash, $candidateHash);
    }

    /**
     * Does the recomputed effective branch still match what this session was
     * established under?
     *
     * COMPARED IN PHP, NEVER IN SQL. A doctor with no home lock and no cover
     * has a NULL effective branch, and that is the compatibility state owner
     * decision O1 requires — but `NULL = NULL` is UNKNOWN in SQL, so pushing
     * this comparison into a query would evict every UNSET doctor on their
     * next request. Here NULL === NULL is a match and they are left alone.
     *
     * The cover reference is part of the comparison, not decoration: without
     * it a cover expiring onto the same branch as home, or one cover replacing
     * another at the same target, would compare equal and the session would
     * survive — while section Q says expiry MUST invalidate.
     */
    public function establishedUnder(?int $branchId, ?int $coverId): bool
    {
        $leaseBranch = $this->effective_branch_id === null ? null : (int) $this->effective_branch_id;
        $leaseCover = $this->effective_cover_id === null ? null : (int) $this->effective_cover_id;

        return $leaseBranch === $branchId && $leaseCover === $coverId;
    }
}
